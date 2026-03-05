<?php

namespace App\Http\Controllers;

use App\Mail\ChatInvitationMail;
use App\Models\Chat;
use App\Models\ChatInvitation;
use App\Models\Message;
use App\Models\User;
use App\Services\AiMemoryService;
use App\Services\AiReasoningService;
use App\Traits\UsesEvidenceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    use UsesEvidenceRules;

    public function __construct(
        private readonly AiReasoningService $aiReasoningService,
        private readonly AiMemoryService $aiMemoryService
    ) {}

    /**
     * List all chats the user participates in.
     * GET /chats
     */
    public function index(Request $request): Response
    {
        $chats = $request->user()
            ->chats()
            ->with(['creator:id,name', 'participants:id,name', 'pendingInvitations.invitedUser:id,name,username'])
            ->withCount('messages')
            ->latest()
            ->get()
            ->map(fn ($chat) => [
                'id' => $chat->id,
                'context_type' => $chat->context_type,
                'title' => $chat->title,
                'status' => $chat->status,
                'created_by' => $chat->creator?->only(['id', 'name']),
                'participants' => $chat->participants->map->only(['id', 'name'])->values(),
                'pending_invitations' => $chat->pendingInvitations->map(fn ($inv) => [
                    'id' => $inv->id,
                    'display' => $inv->invitedUser
                        ? '@'.$inv->invitedUser->username
                        : ($inv->invited_email ?? 'via link'),
                ])->values(),
                'messages_count' => $chat->messages_count,
                'updated_at' => $chat->updated_at,
            ]);

        return Inertia::render('chat/Index', [
            'chats' => $chats,
            'contextTypes' => $this->getContextTypes(),
        ]);
    }

    /**
     * Create a new chat and invite participants by username.
     * POST /chats
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'context_type' => ['required', 'string', 'in:relationship,business,family,financial,legal,general'],
            'title' => ['nullable', 'string', 'max:255'],
            'invitee_usernames' => ['nullable', 'array', 'max:2'],
            'invitee_usernames.*' => ['string', 'max:255'],
            'use_invite_link' => ['nullable', 'boolean'],
        ]);

        $invitees = collect($validated['invitee_usernames'] ?? [])
            ->filter()
            ->map(fn ($u) => User::where('username', $u)->first())
            ->filter()
            ->reject(fn ($u) => $u->id === $request->user()->id)
            ->unique('id')
            ->take(2)
            ->values();

        $status = $invitees->isNotEmpty() ? 'waiting' : 'active';

        $chat = Chat::create([
            'context_type' => $validated['context_type'],
            'title' => $validated['title'] ?? null,
            'created_by' => $request->user()->id,
            'status' => $status,
        ]);

        $chat->participants()->attach($request->user()->id);

        foreach ($invitees as $invitee) {
            $invitation = ChatInvitation::create([
                'chat_id' => $chat->id,
                'invited_user_id' => $invitee->id,
                'token' => Str::uuid()->toString(),
            ]);

            try {
                Mail::to($invitee->email)->send(new ChatInvitationMail($invitation, $request->user()));
            } catch (\Exception) {
                // Non-fatal if email fails
            }
        }

        // Optionally auto-generate a shareable invite link (when no usernames given)
        $inviteUrl = null;
        if (($validated['use_invite_link'] ?? false) && $invitees->isEmpty()) {
            $link = ChatInvitation::create([
                'chat_id' => $chat->id,
                'token' => Str::uuid()->toString(),
            ]);
            $inviteUrl = route('invitations.show', $link->token);
        }

        return redirect()->route('chats.show', $chat->id)
            ->with('invite_link', $inviteUrl);
    }

    /**
     * Show the chat room with messages.
     * GET /chats/{chat}
     */
    public function show(Request $request, Chat $chat): Response|RedirectResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            return redirect()->route('chats.index')
                ->with('error', 'You are not a participant in this chat.');
        }

        $messages = $chat->messages()
            ->with('sender:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'sender_type' => $msg->sender_type,
                'sender' => $msg->sender ? ['id' => $msg->sender->id, 'name' => $msg->sender->name] : null,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toISOString(),
            ]);

        $chat->load(['creator:id,name', 'participants:id,name', 'pendingInvitations.invitedUser:id,name,username']);

        return Inertia::render('chat/Show', [
            'chat' => [
                'id' => $chat->id,
                'context_type' => $chat->context_type,
                'title' => $chat->title,
                'status' => $chat->status,
                'created_by' => $chat->creator?->only(['id', 'name']),
                'participants' => $chat->participants->map->only(['id', 'name'])->values(),
                'pending_invitations' => $chat->pendingInvitations->map(fn ($inv) => [
                    'id' => $inv->id,
                    'display' => $inv->invitedUser
                        ? '@'.$inv->invitedUser->username
                        : ($inv->invited_email ?? 'via link'),
                ])->values(),
            ],
            'messages' => $messages,
            'currentUser' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
            'initialInviteLink' => session('invite_link'),
        ]);
    }

    /**
     * Generate a shareable invite link for this chat (creator only).
     * POST /chats/{chat}/invite-link
     */
    public function generateInviteLink(Request $request, Chat $chat): JsonResponse
    {
        if ($chat->created_by !== $request->user()->id) {
            abort(403);
        }

        if (! $chat->canAddParticipant()) {
            return response()->json(['error' => 'This session already has the maximum number of participants.'], 422);
        }

        $invitation = ChatInvitation::create([
            'chat_id' => $chat->id,
            'token' => Str::uuid()->toString(),
        ]);

        return response()->json([
            'url' => route('invitations.show', $invitation->token),
        ]);
    }

    /**
     * Send a message and trigger AI mediation.
     * POST /chats/{chat}/messages
     */
    public function sendMessage(Request $request, Chat $chat): RedirectResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        if ($chat->isWaiting()) {
            return back()->with('error', 'Waiting for all participants to join before the session can begin.');
        }

        if ($chat->isFinalized()) {
            return back()->with('error', 'This chat has been finalized.');
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'user',
            'sender_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        if ($this->shouldAiRespond($validated['content'])) {
            try {
                $this->aiReasoningService->mediate($chat);
            } catch (\Exception $e) {
                // Non-fatal: user message is saved
            }
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('chats.show', $chat->id);
    }

    /**
     * Finalize a chat (creator only).
     * POST /chats/{chat}/finalize
     */
    public function finalize(Request $request, Chat $chat): RedirectResponse
    {
        if ($chat->created_by !== $request->user()->id) {
            abort(403, 'Only the chat creator can finalize this session.');
        }

        if ($chat->isFinalized()) {
            return back()->with('error', 'Already finalized.');
        }

        $chat->update(['status' => 'finalized']);

        try {
            $this->aiMemoryService->extractAndStoreMemory($chat);
        } catch (\Exception $e) {
            // Memory extraction failed but finalization succeeded
        }

        return redirect()->route('chats.index')
            ->with('success', 'Chat finalized. Context memory extracted.');
    }

    /**
     * Heartbeat — records that the current user is present in this chat.
     * POST /chats/{chat}/heartbeat
     */
    public function heartbeat(Request $request, Chat $chat): JsonResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        Cache::put("chat_online_{$chat->id}_{$request->user()->id}", true, 35);

        return response()->json(['ok' => true]);
    }

    /**
     * Returns the IDs of participants currently online.
     * GET /chats/{chat}/online
     */
    public function online(Request $request, Chat $chat): JsonResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        $onlineIds = $chat->participants()->get()->pluck('id')
            ->filter(fn ($id) => Cache::has("chat_online_{$chat->id}_{$id}"))
            ->values();

        return response()->json($onlineIds);
    }

    /**
     * Poll for new messages after a given message ID.
     * GET /chats/{chat}/messages?after={id}
     */
    public function pollMessages(Request $request, Chat $chat): JsonResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        $afterId = (int) $request->query('after', 0);

        $messages = $chat->messages()
            ->with('sender:id,name')
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'sender_type' => $msg->sender_type,
                'sender' => $msg->sender ? ['id' => $msg->sender->id, 'name' => $msg->sender->name] : null,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toISOString(),
            ]);

        return response()->json($messages);
    }

    /**
     * Record that the current user is typing.
     * POST /chats/{chat}/typing
     */
    public function recordTyping(Request $request, Chat $chat): JsonResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        $key = "chat_typing_{$chat->id}";
        $typers = Cache::get($key, []);
        $typers[$request->user()->id] = [
            'name' => $request->user()->name,
            'expires_at' => now()->addSeconds(5)->timestamp,
        ];
        Cache::put($key, $typers, 60);

        return response()->json(['ok' => true]);
    }

    /**
     * Get users currently typing (excluding self).
     * GET /chats/{chat}/typing
     */
    public function getTyping(Request $request, Chat $chat): JsonResponse
    {
        if (! $chat->participants()->where('user_id', $request->user()->id)->exists()) {
            abort(403);
        }

        $key = "chat_typing_{$chat->id}";
        $typers = Cache::get($key, []);
        $now = now()->timestamp;
        $userId = $request->user()->id;

        $active = collect($typers)
            ->filter(fn ($t, $id) => $t['expires_at'] > $now && (int) $id !== $userId)
            ->map(fn ($t) => ['name' => $t['name']])
            ->values();

        return response()->json($active);
    }

    private function getContextTypes(): array
    {
        return [
            ['value' => 'relationship', 'label' => 'Relationship'],
            ['value' => 'business', 'label' => 'Business'],
            ['value' => 'family', 'label' => 'Family'],
            ['value' => 'financial', 'label' => 'Financial'],
            ['value' => 'legal', 'label' => 'Legal'],
            ['value' => 'general', 'label' => 'General'],
        ];
    }
}
