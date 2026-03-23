<?php

namespace App\Traits;

use App\Models\Chat;
use App\Models\UserContextNote;

/**
 * Loads conversation context for injection into AI prompts.
 *
 * Simplified from the original: all tone detection, agreement detection,
 * and participation balance analysis has been removed — Claude handles
 * these via the periodic assessment system now.
 */
trait UsesConversationContext
{
    /**
     * Load the last N messages, oldest-first, formatted for prompt injection.
     */
    protected function loadRecentMessages(Chat $chat, int $limit = 30): array
    {
        return $chat->messages()
            ->with('sender')
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($message) => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender?->name ?? 'Accord',
                'content' => $message->content,
                'created_at' => $message->created_at->toISOString(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Load participants with basic info.
     */
    protected function loadParticipants(Chat $chat): array
    {
        return $chat->participants()
            ->get()
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])
            ->toArray();
    }

    /**
     * Load stored behavioral context notes scoped to this chat's context_type.
     */
    protected function loadUserContextNotes(Chat $chat): array
    {
        $participantIds = $chat->participants()->pluck('users.id');

        return UserContextNote::whereIn('user_id', $participantIds)
            ->where('context_type', $chat->context_type)
            ->with('user')
            ->get()
            ->map(fn ($note) => [
                'user_id' => $note->user_id,
                'user_name' => $note->user->name,
                'trait' => $note->trait,
                'context_type' => $note->context_type,
            ])
            ->toArray();
    }

    /**
     * Build a full plain-text transcript for memory extraction.
     */
    protected function buildChatTranscript(Chat $chat): string
    {
        return $chat->messages()
            ->with('sender')
            ->get()
            ->map(function ($msg) {
                $label = $msg->sender_type === 'ai' ? 'Accord' : $msg->sender->name;

                return "[{$label}]: {$msg->content}";
            })
            ->implode("\n");
    }
}
