<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\ChatSession;

/**
 * Retrieves prior session context between the same (or overlapping) participants.
 *
 * Queries chat_sessions by participant overlap to find relevant history.
 * Injected into the AI context block so Accord can reference prior agreements
 * and unresolved issues naturally.
 */
class CrossSessionMemoryService
{
    /**
     * Find prior sessions where at least 2 of the current participants overlapped.
     *
     * @return array<array{date: string, topic: string, resolved: array, unresolved: array}>
     */
    public function getPriorSessions(Chat $chat, int $limit = 3): array
    {
        $participantIds = $chat->participants()->pluck('users.id')->sort()->values()->toArray();

        if (count($participantIds) < 2) {
            return [];
        }

        return ChatSession::where('chat_id', '!=', $chat->id)
            ->latest()
            ->get()
            ->filter(function (ChatSession $session) use ($participantIds) {
                $sessionIds = collect($session->participant_ids ?? []);

                return $sessionIds->intersect($participantIds)->count() >= 2;
            })
            ->take($limit)
            ->map(fn (ChatSession $s) => [
                'date' => $s->created_at->format('M j, Y'),
                'topic' => $s->topic_summary,
                'summary' => $s->session_summary,
                'resolved' => $s->resolved_issues ?? [],
                'unresolved' => $s->unresolved_issues ?? [],
            ])
            ->values()
            ->toArray();
    }
}
