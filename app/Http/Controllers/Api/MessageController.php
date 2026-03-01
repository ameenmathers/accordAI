<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AiReasoningService;
use App\Traits\UsesEvidenceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles sending messages and retrieving chat history for the REST API.
 *
 * GET  /api/chats/{id}/messages — paginated message history
 * POST /api/chats/{id}/messages — send a user message + trigger AI response
 */
class MessageController extends Controller
{
    use UsesEvidenceRules;

    public function __construct(
        private readonly AiReasoningService $aiReasoningService
    ) {}

    /**
     * Return paginated message history for a chat.
     */
    public function index(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $chat);

        $messages = $chat->messages()
            ->with('sender:id,name')
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $messages->items() ? collect($messages->items())->map(fn ($m) => $this->formatMessage($m))->reverse()->values() : [],
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * Store a user message and synchronously trigger the AI mediator response.
     *
     * Returns both the user message and the AI response so the client
     * can display them immediately without a second request.
     */
    public function store(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $chat);

        if ($chat->isFinalized()) {
            return response()->json(['error' => 'This chat has been finalized.'], 422);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        // 1. Persist the user's message
        $userMessage = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'user',
            'sender_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        $userMessage->load('sender:id,name');

        // 2. Determine if AI should respond to this message
        if (! $this->shouldAiRespond($validated['content'])) {
            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'ai_message' => null,
            ], 201);
        }

        // 3. Trigger AI mediation synchronously (no queue needed for now)
        try {
            $aiMessage = $this->aiReasoningService->mediate($chat);

            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'ai_message' => $this->formatMessage($aiMessage),
            ], 201);
        } catch (\Exception $e) {
            // AI failure is non-fatal — the user's message was already saved
            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'ai_message' => null,
                'ai_error' => 'AI mediator temporarily unavailable.',
            ], 201);
        }
    }

    private function authorizeParticipant(User $user, Chat $chat): void
    {
        if (! $chat->participants()->where('user_id', $user->id)->exists()) {
            abort(403, 'You are not a participant in this chat.');
        }
    }

    private function formatMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'sender_type' => $message->sender_type,
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
            ] : null,
            'content' => $message->content,
            'created_at' => $message->created_at,
        ];
    }
}
