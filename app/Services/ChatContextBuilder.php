<?php

namespace App\Services;

use App\Models\Chat;
use App\Traits\UsesConversationContext;

/**
 * Assembles the full context payload for AI mediation calls.
 * Now includes participation balance stats so the AI knows
 * who is dominating the conversation and who needs more voice.
 */
class ChatContextBuilder
{
    use UsesConversationContext;

    /**
     * Build the complete context for a mediation call.
     *
     * Returns:
     * - chat:                 basic chat metadata
     * - participants:         list of human participants
     * - messages:             last N messages for prompt injection
     * - context_notes:        stored behavioral traits from past sessions
     * - participation_stats:  per-user message counts and balance notes
     */
    public function build(Chat $chat, int $messageLimit = 20): array
    {
        $participants = $this->loadParticipants($chat);
        $messages = $this->loadRecentMessages($chat, $messageLimit);
        $totalMessageCount = $chat->messages()->count();

        $stage = match (true) {
            $totalMessageCount <= 4  => 'opening',
            $totalMessageCount <= 14 => 'active',
            default                  => 'deep',
        };

        return [
            'chat' => [
                'id' => $chat->id,
                'context_type' => $chat->context_type,
                'title' => $chat->title,
                'status' => $chat->status,
            ],
            'participants' => $participants,
            'messages' => $messages,
            'context_notes' => $this->loadUserContextNotes($chat),
            'participation_stats' => $this->analyzeParticipationBalance($participants, $messages),
            'total_message_count' => $totalMessageCount,
            'stage' => $stage,
            'tone' => $this->detectTone($messages),
        ];
    }

    /**
     * Lighter context for Claude's memory extraction step after finalization.
     */
    public function buildForMemoryExtraction(Chat $chat): array
    {
        return [
            'chat' => [
                'id' => $chat->id,
                'context_type' => $chat->context_type,
            ],
            'participants' => $this->loadParticipants($chat),
            'transcript' => $this->buildChatTranscript($chat),
        ];
    }
}
