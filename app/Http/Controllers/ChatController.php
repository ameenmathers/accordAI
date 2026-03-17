<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Jobs\CheckChatEngagement;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * Build the chat list for the authenticated user.
     */
    private function buildUserChats(Request $request): \Illuminate\Support\Collection
    {
        $userId = $request->user()->id;

        return $request->user()
            ->chats()
            ->with([
                'creator:id,name',
                'participants:id,name',
                'participants' => fn ($q) => $q->withPivot('last_read_message_id'),
                'pendingInvitations.invitedUser:id,name,username',
                'latestMessage',
            ])
            ->withCount('messages')
            ->latest()
            ->get()
            ->map(function ($chat) use ($userId) {
                $latestMsgId = $chat->latestMessage?->id ?? 0;
                $myPivot = $chat->participants->firstWhere('id', $userId);
                $myLastRead = $myPivot?->pivot?->last_read_message_id ?? 0;
                $unreadCount = $myLastRead < $latestMsgId
                    ? $chat->messages()->where('id', '>', $myLastRead)->where('sender_id', '!=', $userId)->count()
                    : 0;

                return [
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
                    'unread_count' => $unreadCount,
                    'last_message' => $chat->latestMessage ? [
                        'content' => $chat->latestMessage->content,
                        'sender_type' => $chat->latestMessage->sender_type,
                    ] : null,
                    'updated_at' => $chat->updated_at,
                ];
            });
    }

    /**
     * List all chats the user participates in.
     * GET /chats
     */
    public function index(Request $request): Response
    {
        $chats = $this->buildUserChats($request);

        $onlineUserIds = $chats
            ->flatMap(fn ($c) => collect($c['participants'])->pluck('id'))
            ->unique()
            ->filter(fn ($id) => Cache::has("user_online_{$id}"))
            ->values();

        return Inertia::render('chat/Index', [
            'chats' => $chats,
            'contextTypes' => $this->getContextTypes(),
            'onlineUserIds' => $onlineUserIds,
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
                Mail::to($invitee->email)->queue(new ChatInvitationMail($invitation, $request->user()));
            } catch (\Throwable) {
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

        // Mark current user as having read all messages on page load
        $userId = $request->user()->id;
        if ($messages->isNotEmpty()) {
            $lastId = $messages->last()['id'];
            Cache::put("chat_read_{$chat->id}_{$userId}", $lastId, 86400);
            $chat->participants()->updateExistingPivot($userId, ['last_read_message_id' => $lastId]);
        }

        $readStatus = $chat->participants->pluck('id')
            ->mapWithKeys(fn ($pid) => [$pid => Cache::get("chat_read_{$chat->id}_{$pid}", 0)])
            ->all();

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
            'chats' => $this->buildUserChats($request),
            'contextTypes' => $this->getContextTypes(),
            'initialInviteLink' => session('invite_link'),
            'initialReadStatus' => $readStatus,
            'initialSummary' => session('session_summary'),
            'vapidPublicKey' => env('VAPID_PUBLIC_KEY'),
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
    public function sendMessage(Request $request, Chat $chat): StreamedResponse|RedirectResponse|JsonResponse
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

        $userMessage = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'user',
            'sender_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        try {
            broadcast(new MessageSent($chat->id, [
                'id' => $userMessage->id,
                'sender_type' => 'user',
                'sender' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'content' => $userMessage->content,
                'created_at' => $userMessage->created_at->toISOString(),
            ]))->toOthers();
        } catch (\Throwable) { /* non-fatal: Reverb may not be running */ }

        // Proactive re-engagement: if the chat goes quiet for 10 min, Accord will check in
        CheckChatEngagement::dispatch($chat->id, $userMessage->id)->delay(now()->addMinutes(10));

        // Auto-extract insights every 20 user messages (threshold-based memory)
        $userMessageCount = $chat->messages()->where('sender_type', 'user')->count();
        if ($userMessageCount > 0 && $userMessageCount % 20 === 0) {
            try {
                $this->aiMemoryService->extractAndStoreMemory($chat);
            } catch (\Throwable) {
                // Non-fatal
            }
        }

        $shouldRespond = $this->shouldAiRespond($validated['content'], $chat);

        // Streaming path: client sends Accept: text/event-stream → stream tokens in real time
        // (skips the synchronous mediate() call — mediateStreaming() saves the message itself)
        if ($request->header('Accept') === 'text/event-stream') {
            $aiReasoningService = $this->aiReasoningService;

            return response()->stream(function () use ($chat, $shouldRespond, $aiReasoningService) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                if ($shouldRespond) {
                    try {
                        $aiReasoningService->mediateStreaming($chat, function ($token) {
                            echo 'data: '.json_encode(['token' => $token])."\n\n";
                            flush();
                        });
                    } catch (\Throwable) {
                        // Non-fatal — client will see [DONE] and poll for the message
                    }
                }

                echo "data: [DONE]\n\n";
                flush();
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // Non-streaming fallback: call mediate() synchronously
        if ($shouldRespond) {
            try {
                $this->aiReasoningService->mediate($chat);
            } catch (\Throwable) {
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

        // Closing ritual: Accord sends a final message before memory extraction
        try {
            $this->aiReasoningService->closingRitual($chat);
        } catch (\Exception) {
            // Non-fatal
        }

        try {
            $this->aiMemoryService->extractAndStoreMemory($chat);
        } catch (\Exception $e) {
            // Memory extraction failed but finalization succeeded
        }

        $summary = '';
        try {
            $summary = $this->aiReasoningService->generateSummary($chat);
        } catch (\Exception $e) {
            // Non-fatal — finalization still succeeded
        }

        return redirect()->route('chats.show', $chat->id)
            ->with('session_summary', $summary);
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

        $userId = $request->user()->id;
        Cache::put("chat_online_{$chat->id}_{$userId}", true, 35);
        Cache::put("user_online_{$userId}", true, 35); // global — used by chat list

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
        $userId = $request->user()->id;

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

        // Auto mark-read: user is actively polling = they're looking at the chat
        if ($messages->isNotEmpty()) {
            $lastId = $messages->last()['id'];
            Cache::put("chat_read_{$chat->id}_{$userId}", $lastId, 86400);
            $chat->participants()->updateExistingPivot($userId, ['last_read_message_id' => $lastId]);
        }

        // Read status for all participants (last message ID each has read)
        $readStatus = $chat->participants()->pluck('users.id')
            ->mapWithKeys(fn ($pid) => [$pid => Cache::get("chat_read_{$chat->id}_{$pid}", 0)])
            ->all();

        return response()->json([
            'messages' => $messages,
            'readStatus' => $readStatus,
            'chatStatus' => $chat->fresh()->status,
        ]);
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
