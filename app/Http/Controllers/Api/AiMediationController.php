<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Services\AiMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles the chat finalization flow which triggers memory extraction.
 *
 * POST /api/chats/{id}/finalize
 *   - Marks the chat as finalized (no new messages allowed)
 *   - Calls Claude (Anthropic) to extract behavioral traits per participant
 *   - Stores traits in user_context_notes for future chat enrichment
 *
 * Only the chat creator can finalize a chat.
 */
class AiMediationController extends Controller
{
    public function __construct(
        private readonly AiMemoryService $aiMemoryService
    ) {}

    /**
     * Finalize a chat and extract memory for all participants.
     */
    public function finalize(Request $request, Chat $chat): JsonResponse
    {
        // Only the creator can finalize
        if ($chat->created_by !== $request->user()->id) {
            return response()->json(['error' => 'Only the chat creator can finalize this session.'], 403);
        }

        if ($chat->isFinalized()) {
            return response()->json(['error' => 'This chat has already been finalized.'], 422);
        }

        // Mark as finalized so no more messages are accepted
        $chat->update(['status' => 'finalized']);

        // Extract and store behavioral traits using Claude
        // This is synchronous for now (no background jobs per spec)
        try {
            $this->aiMemoryService->extractAndStoreMemory($chat);

            return response()->json([
                'message' => 'Chat finalized. Behavioral context has been extracted and stored for all participants.',
                'chat_id' => $chat->id,
                'status' => 'finalized',
            ]);
        } catch (\Exception $e) {
            // Memory extraction failure shouldn't break finalization
            return response()->json([
                'message' => 'Chat finalized. Memory extraction encountered an issue but the session is closed.',
                'chat_id' => $chat->id,
                'status' => 'finalized',
                'memory_error' => $e->getMessage(),
            ]);
        }
    }
}
