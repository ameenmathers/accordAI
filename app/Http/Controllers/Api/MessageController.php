<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AiReasoningService;
use App\Services\MediationAssessmentService;
use App\Traits\UsesEvidenceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    use UsesEvidenceRules;

    public function __construct(
        private readonly AiReasoningService $aiReasoningService,
        private readonly MediationAssessmentService $assessmentService,
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
     * Store a user message and trigger AI triage → respond/listen.
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

        // 2. Increment human message count and trigger assessment if due
        $chat->increment('human_message_count');

        try {
            $this->assessmentService->assessIfDue($chat);
        } catch (\Throwable) { /* non-fatal */ }

        // 3. Triage: should Accord respond or listen?
        $triageResult = $this->aiReasoningService->triage($chat);

        // Concurrency lock
        $lockKey = "chat_ai_responding_{$chat->id}";
        if ($triageResult === 'respond' && Cache::has($lockKey)) {
            $triageResult = 'listen';
        }

        if ($triageResult !== 'respond') {
            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'ai_message' => null,
                'listening' => $triageResult === 'listen',
            ], 201);
        }

        // 4. Respond
        Cache::put($lockKey, true, 120);
        try {
            $aiMessage = $this->aiReasoningService->mediate($chat);

            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'ai_message' => $aiMessage ? $this->formatMessage($aiMessage) : null,
            ], 201);
        } catch (\Exception $e) {
            Log::error('[AI] API mediation failed', ['chat_id' => $chat->id, 'error' => $e->getMessage()]);

            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'ai_message' => null,
                'ai_error' => 'AI mediator temporarily unavailable.',
            ], 201);
        } finally {
            Cache::forget($lockKey);
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
