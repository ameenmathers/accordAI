<?php

namespace App\Traits;

use App\Models\Chat;
use App\Models\UserContextNote;

/**
 * Loads and structures conversation context for injection into AI prompts.
 * The key addition here is participation balance analysis — giving the AI
 * real data about who is dominating and who is being silenced.
 */
trait UsesConversationContext
{
    /**
     * Load the last N messages, oldest-first, formatted for prompt injection.
     */
    protected function loadRecentMessages(Chat $chat, int $limit = 20): array
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
                'sender_name' => $message->sender?->name ?? 'AccordAI',
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
     * This is the "memory retrieval" step — traits extracted by Claude from past sessions.
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
     * Analyse how balanced the conversation is between human participants.
     *
     * Returns a per-user stats array used to tell the AI who is dominating
     * and whose voice needs amplifying. Example output:
     *
     *   [42 => ['message_count' => 6, 'note' => 'most active speaker'],
     *    99 => ['message_count' => 1, 'note' => 'spoke only once — may need more voice']]
     *
     * The AI uses this to decide whether to redirect attention.
     */
    protected function analyzeParticipationBalance(array $participants, array $recentMessages): array
    {
        // Count messages per human participant (exclude AI messages)
        $counts = collect($recentMessages)
            ->where('sender_type', 'user')
            ->groupBy('sender_id')
            ->map->count();

        $totalHumanMessages = $counts->sum();

        if ($totalHumanMessages === 0) {
            return [];
        }

        $stats = [];
        $maxCount = $counts->max();
        $minCount = $counts->min();

        // Find the ID of the last human speaker
        $lastHumanMessage = collect($recentMessages)
            ->where('sender_type', 'user')
            ->last();
        $lastSpeakerId = $lastHumanMessage['sender_id'] ?? null;

        foreach ($participants as $participant) {
            $id = $participant['id'];
            $count = $counts->get($id, 0);
            $percentage = $totalHumanMessages > 0
                ? round(($count / $totalHumanMessages) * 100)
                : 0;

            // Generate a plain-English note the AI can read and act on
            $note = match (true) {
                $count === 0 => 'has not spoken yet — needs to be drawn in',
                $count === $minCount && $count < $maxCount && $count > 0 => "less active ({$percentage}% of messages) — may need more voice",
                $count === $maxCount && $maxCount > $minCount => "most active speaker ({$percentage}% of messages)",
                $id === $lastSpeakerId => "spoke most recently",
                default => "{$count} messages ({$percentage}%)",
            };

            $stats[$id] = [
                'message_count' => $count,
                'percentage' => $percentage,
                'note' => $note,
            ];
        }

        return $stats;
    }

    /**
     * Detect mutual agreement moments between participants.
     * Returns up to 2 brief excerpts where both users signalled agreement in sequence.
     * Injected into the prompt so Accord doesn't re-litigate settled points.
     */
    protected function detectAgreements(array $recentMessages): array
    {
        $agreementPhrases = ['agree', 'that works', 'sounds good', "let's do that", 'deal', 'fair enough', 'that makes sense', 'ok with that', 'yes exactly', 'works for me', 'happy with that'];

        $userMessages = collect($recentMessages)->where('sender_type', 'user')->values();
        $agreements = [];

        for ($i = 0; $i < $userMessages->count() - 1; $i++) {
            $msg1 = $userMessages[$i];
            $msg2 = $userMessages[$i + 1];

            if ($msg1['sender_id'] === $msg2['sender_id']) {
                continue;
            }

            $agreed1 = collect($agreementPhrases)->contains(fn ($p) => str_contains(strtolower($msg1['content']), $p));
            $agreed2 = collect($agreementPhrases)->contains(fn ($p) => str_contains(strtolower($msg2['content']), $p));

            if ($agreed1 && $agreed2) {
                $agreements[] = mb_substr($msg1['content'], 0, 80);
            }
        }

        return array_slice(array_unique($agreements), -2);
    }

    /**
     * Detect the conversational tone from the most recent human messages.
     * Returns 'tense', 'progressing', or 'neutral'.
     */
    protected function detectTone(array $recentMessages): string
    {
        $text = strtolower(
            collect($recentMessages)
                ->where('sender_type', 'user')
                ->takeLast(4)
                ->pluck('content')
                ->implode(' ')
        );

        if (empty($text)) {
            return 'neutral';
        }

        foreach (['angry', 'upset', 'frustrated', 'ridiculous', 'unfair', 'wrong', 'hate', 'stupid', 'useless'] as $word) {
            if (str_contains($text, $word)) {
                return 'tense';
            }
        }

        $progressHits = 0;
        foreach (['agree', 'that makes sense', 'good point', 'exactly', 'fair', 'understood'] as $word) {
            if (str_contains($text, $word)) {
                $progressHits++;
            }
        }

        return $progressHits >= 2 ? 'progressing' : 'neutral';
    }

    /**
     * Build a full plain-text transcript for Claude's memory extraction.
     */
    protected function buildChatTranscript(Chat $chat): string
    {
        return $chat->messages()
            ->with('sender')
            ->get()
            ->map(function ($msg) {
                $label = $msg->sender_type === 'ai' ? 'AccordAI' : $msg->sender->name;

                return "[{$label}]: {$msg->content}";
            })
            ->implode("\n");
    }
}
