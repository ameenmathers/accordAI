<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\User;
use App\Models\UserContextNote;
use App\Traits\UsesAiPrompts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles memory extraction using Anthropic's Claude API.
 * After a chat is finalized, Claude reads the full transcript and
 * extracts 1-3 behavioral traits per participant.
 *
 * These traits are stored in user_context_notes and retrieved in future
 * chats to give the AI richer context about each participant.
 *
 * Why Claude for memory? Claude excels at nuanced summarization and
 * structured extraction tasks, complementing OpenAI's reasoning role.
 */
class AiMemoryService
{
    use UsesAiPrompts;

    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    private const CLAUDE_MODEL = 'claude-opus-4-6';

    public function __construct(
        private readonly ChatContextBuilder $contextBuilder
    ) {}

    /**
     * Extract and store behavioral traits for all participants in a finalized chat.
     * Called once when POST /api/chats/{id}/finalize is triggered.
     */
    public function extractAndStoreMemory(Chat $chat): void
    {
        $context = $this->contextBuilder->buildForMemoryExtraction($chat);

        foreach ($context['participants'] as $participant) {
            $this->extractTraitsForUser(
                chat: $chat,
                userId: $participant['id'],
                userName: $participant['name'],
                transcript: $context['transcript'],
                contextType: $context['chat']['context_type']
            );
        }
    }

    /**
     * Extract traits for a single participant and persist them.
     */
    private function extractTraitsForUser(
        Chat $chat,
        int $userId,
        string $userName,
        string $transcript,
        string $contextType
    ): void {
        $prompt = $this->getMemoryExtractionPrompt($userName, $contextType, $transcript);

        $traits = $this->callClaude($prompt);

        if (empty($traits)) {
            return;
        }

        // Remove old notes for this user+context from this chat (idempotent finalization)
        UserContextNote::where('user_id', $userId)
            ->where('context_type', $contextType)
            ->where('source_chat_id', $chat->id)
            ->delete();

        // Store each extracted trait as a separate note for targeted retrieval
        foreach ($traits as $trait) {
            if (! empty(trim($trait))) {
                UserContextNote::create([
                    'user_id' => $userId,
                    'context_type' => $contextType,
                    'trait' => trim($trait),
                    'source_chat_id' => $chat->id,
                ]);
            }
        }
    }

    /**
     * Calls Anthropic Claude API and parses the JSON trait array response.
     *
     * @return array<string> Array of trait strings (empty on failure)
     */
    private function callClaude(string $prompt): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post(self::ANTHROPIC_API_URL, [
                'model' => self::CLAUDE_MODEL,
                'max_tokens' => 300,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if (! $response->successful()) {
                Log::error('Anthropic API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $content = $response->json('content.0.text', '[]');

            // Claude returns a JSON array of trait strings
            $decoded = json_decode($content, true);

            return is_array($decoded) ? array_slice($decoded, 0, 3) : [];
        } catch (\Exception $e) {
            Log::error('AiMemoryService exception', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
