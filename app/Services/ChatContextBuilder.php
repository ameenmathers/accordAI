<?php

namespace App\Services;

use App\Models\Chat;
use App\Traits\UsesConversationContext;

/**
 * Assembles context payloads for AI calls.
 *
 * Three build modes:
 *  - build()                  — full context for mediation responses (30 messages)
 *  - buildLightweight()       — minimal context for triage calls (last 10 messages)
 *  - buildForMemoryExtraction() — full transcript for Claude memory extraction
 */
class ChatContextBuilder
{
    use UsesConversationContext;

    /**
     * Full context for a mediation call.
     */
    public function build(Chat $chat): array
    {
        $participants = $this->loadParticipants($chat);

        return [
            'participants' => $participants,
            'messages' => $this->loadRecentMessages($chat, 30),
            'context_notes' => $this->loadUserContextNotes($chat),
        ];
    }

    /**
     * Lightweight context for triage calls — fewer messages, no context notes.
     */
    public function buildLightweight(Chat $chat): array
    {
        return [
            'participants' => $this->loadParticipants($chat),
            'messages' => $this->loadRecentMessages($chat, 10),
        ];
    }

    /**
     * Full transcript context for Claude's memory extraction.
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
