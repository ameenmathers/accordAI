<?php

namespace App\Http\Controllers;

use App\Mail\ChatInvitationMail;
use App\Models\Chat;
use App\Models\ChatInvitation;
use App\Models\Message;
use App\Services\AiMemoryService;
use App\Services\AiReasoningService;
use App\Traits\UsesEvidenceRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->with(['creator:id,name', 'participants:id,name', 'pendingInvitations'])
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
                'pending_invitations' => $chat->pendingInvitations->map->only(['invited_email'])->values(),
                'messages_count' => $chat->messages_count,
                'updated_at' => $chat->updated_at,
            ]);

        return Inertia::render('chat/Index', [
            'chats' => $chats,
            'contextTypes' => $this->getContextTypes(),
        ]);
    }

    /**
     * Create a new chat and send email invitations.
     * POST /chats
     *
     * Invitees are specified by email address, not user ID.
     * Chat starts as 'waiting' until all invitees accept.
     * If no invitees, chat starts as 'active' immediately.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'context_type' => ['required', 'string', 'in:relationship,business,family,financial,legal,general'],
            'title' => ['nullable', 'string', 'max:255'],
            // Up to 2 email addresses to invite (max 3 participants total)
            'invitee_emails' => ['nullable', 'array', 'max:2'],
            'invitee_emails.*' => ['email', 'max:255'],
        ]);

        $inviteeEmails = collect($validated['invitee_emails'] ?? [])
            ->filter()
            ->reject(fn ($email) => strtolower($email) === strtolower($request->user()->email))
            ->unique()
            ->take(2)
            ->values();

        // Chat waits for invitees if any were added, otherwise active immediately
        $status = $inviteeEmails->isNotEmpty() ? 'waiting' : 'active';

        $chat = Chat::create([
            'context_type' => $validated['context_type'],
            'title' => $validated['title'] ?? null,
            'created_by' => $request->user()->id,
            'status' => $status,
        ]);

        // Creator is always the first participant
        $chat->participants()->attach($request->user()->id);

        // Create an invitation record and send an email for each invitee
        foreach ($inviteeEmails as $email) {
            $invitation = ChatInvitation::create([
                'chat_id' => $chat->id,
                'invited_email' => $email,
                'token' => Str::uuid()->toString(),
            ]);

            Mail::to($email)->send(new ChatInvitationMail($invitation, $request->user()));
        }

        return redirect()->route('chats.show', $chat->id);
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

        $chat->load(['creator:id,name', 'participants:id,name', 'pendingInvitations']);

        return Inertia::render('chat/Show', [
            'chat' => [
                'id' => $chat->id,
                'context_type' => $chat->context_type,
                'title' => $chat->title,
                'status' => $chat->status,
                'created_by' => $chat->creator?->only(['id', 'name']),
                'participants' => $chat->participants->map->only(['id', 'name'])->values(),
                'pending_invitations' => $chat->pendingInvitations->map->only(['invited_email'])->values(),
            ],
            'messages' => $messages,
            'currentUser' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
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

        // Block messages while waiting for invitees to join
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
