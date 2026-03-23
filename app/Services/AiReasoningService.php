<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\Message;
use App\Traits\UsesAiPrompts;
use Illuminate\Support\Facades\Log;

/**
 * Drives AI mediation using Anthropic Claude Sonnet.
 *
 * Core flow per message:
 *  1. Hard PHP guard: skip if last 2 messages are both AI
 *  2. Triage call: lightweight RESPOND/LISTEN decision
 *  3. If LISTEN: send listening event to frontend, return
 *  4. If RESPOND: build full context, stream mediation response
 *
 * Periodic assessment (MediationAssessmentService) runs every ~8 human messages
 * and provides structured context about sub-issues, progress, and techniques.
 */
class AiReasoningService
{
    use UsesAiPrompts;

    public function __construct(
        private readonly ChatContextBuilder $contextBuilder,
        private readonly AnthropicClient $anthropic,
        private readonly CrossSessionMemoryService $crossSession,
        private readonly WebPushService $webPushService,
    ) {}

    // ── TRIAGE ────────────────────────────────────────────────────────────

    /**
     * Decide whether Accord should respond or listen.
     * Returns 'respond', 'listen', or 'skip' (hard PHP guard).
     */
    public function triage(Chat $chat): string
    {
        // Hard guard: if last 2 messages are both AI, never stack
        $lastTwo = $chat->messages()->latest()->take(2)->get();
        if ($lastTwo->count() === 2 && $lastTwo->every(fn ($m) => $m->sender_type === 'ai')) {
            Log::info('[AI] triage=skip (AI stacking guard)', ['chat_id' => $chat->id]);

            return 'skip';
        }

        $context = $this->contextBuilder->buildLightweight($chat);
        $assessment = $chat->latestAssessment;

        $triagePrompt = $this->getTriageSystemPrompt(
            $chat->context_type,
            array_column($context['participants'], 'name'),
        );

        $triageMessage = $this->buildTriageUserMessage($context['messages'], $assessment);

        try {
            $response = $this->anthropic->triage(
                $triagePrompt,
                [['role' => 'user', 'content' => $triageMessage]],
            );

            $response = trim($response);
            Log::info('[AI] triage decision', ['chat_id' => $chat->id, 'response' => $response]);

            if (str_starts_with(strtoupper($response), 'RESPOND')) {
                return 'respond';
            }

            return 'listen';
        } catch (\Exception $e) {
            Log::error('[AI] triage failed, defaulting to respond', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            // On triage failure, default to responding (safer than silence)
            return 'respond';
        }
    }

    // ── STREAMING MEDIATION ───────────────────────────────────────────────

    /**
     * Stream a mediation response token-by-token via a callback, then persist.
     * Only called when triage returns 'respond'.
     */
    public function mediateStreaming(Chat $chat, \Closure $onToken): void
    {
        $context = $this->contextBuilder->build($chat);
        $participantNames = array_column($context['participants'], 'name');
        $assessment = $chat->latestAssessment;
        $priorSessions = $this->crossSession->getPriorSessions($chat);

        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames);

        $contextBlock = $this->buildContextBlock(
            $chat->context_type,
            $chat->mediation_phase,
            $chat->topic_summary,
            $chat->creator_context,
            $assessment,
            $priorSessions,
            $context['context_notes'],
            $context['participants'],
        );

        $messages = $this->buildAlternatingTurns($contextBlock, $context['messages']);

        Log::info('[AI] mediateStreaming: calling Anthropic', [
            'chat_id' => $chat->id,
            'participants' => $participantNames,
            'phase' => $chat->mediation_phase,
            'has_assessment' => $assessment !== null,
            'turn_count' => count($messages),
        ]);

        $fullContent = $this->anthropic->stream(
            $systemPrompt,
            $messages,
            maxTokens: 1024,
            temperature: 0.7,
            onToken: $onToken,
        );

        Log::info('[AI] mediateStreaming: stream finished', [
            'chat_id' => $chat->id,
            'response_length' => strlen($fullContent),
        ]);

        if ($fullContent !== '' && $this->isRepetitive($chat, $fullContent)) {
            Log::info('[AI] mediateStreaming: repetitive, regenerating', ['chat_id' => $chat->id]);
            $varied = $this->regenerateWithVariation($systemPrompt, $messages);
            if (! empty($varied)) {
                $fullContent = $varied;
            }
        }

        if ($fullContent !== '') {
            $this->persistAndBroadcast($chat, $fullContent);
        }
    }

    // ── NON-STREAMING MEDIATION ───────────────────────────────────────────

    /**
     * Generate and persist an AI mediation response (non-streaming).
     */
    public function mediate(Chat $chat): ?Message
    {
        $context = $this->contextBuilder->build($chat);
        $participantNames = array_column($context['participants'], 'name');
        $assessment = $chat->latestAssessment;
        $priorSessions = $this->crossSession->getPriorSessions($chat);

        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames);

        $contextBlock = $this->buildContextBlock(
            $chat->context_type,
            $chat->mediation_phase,
            $chat->topic_summary,
            $chat->creator_context,
            $assessment,
            $priorSessions,
            $context['context_notes'],
            $context['participants'],
        );

        $messages = $this->buildAlternatingTurns($contextBlock, $context['messages']);

        Log::info('[AI] mediate: calling Anthropic', [
            'chat_id' => $chat->id,
            'participants' => $participantNames,
            'phase' => $chat->mediation_phase,
        ]);

        $aiContent = $this->anthropic->message($systemPrompt, $messages, maxTokens: 1024);

        if ($aiContent && $this->isRepetitive($chat, $aiContent)) {
            Log::info('[AI] mediate: repetitive, regenerating', ['chat_id' => $chat->id]);
            $varied = $this->regenerateWithVariation($systemPrompt, $messages);
            if (! empty($varied)) {
                $aiContent = $varied;
            }
        }

        if (empty($aiContent)) {
            return null;
        }

        return $this->persistAndBroadcast($chat, $aiContent);
    }

    // ── ENGAGEMENT NUDGE ──────────────────────────────────────────────────

    /**
     * Send a short nudge when both participants have gone quiet for 10+ minutes.
     */
    public function sendEngagementNudge(Chat $chat): void
    {
        $context = $this->contextBuilder->buildLightweight($chat);
        $participantNames = array_column($context['participants'], 'name');

        $system = $this->getMediatorSystemPrompt($chat->context_type, $participantNames)
            ."\n\nIMPORTANT: Both participants have gone quiet. Send a single warm sentence that gently re-engages them — reference something specific from the conversation. No question needed.";

        $transcript = collect($context['messages'])->map(function ($msg) {
            $label = $msg['sender_type'] === 'ai' ? 'Accord' : $msg['sender_name'];

            return "[{$label}]: {$msg['content']}";
        })->implode("\n");

        $content = $this->anthropic->message(
            $system,
            [['role' => 'user', 'content' => $transcript]],
            maxTokens: 80,
            temperature: 0.8,
        );

        $content = trim($content);
        if (empty($content)) {
            return;
        }

        $this->persistAndBroadcast($chat, $content);
    }

    // ── SUMMARY & CLOSING ─────────────────────────────────────────────────

    /**
     * Generate a warm, readable summary of a finalized session.
     */
    public function generateSummary(Chat $chat): string
    {
        $context = $this->contextBuilder->buildForMemoryExtraction($chat);
        $names = implode(' and ', array_column($context['participants'], 'name'));

        if (empty(trim($context['transcript'] ?? ''))) {
            return '';
        }

        return trim($this->anthropic->message(
            'You write warm, constructive summaries of mediation sessions. Be concise — 2–3 short paragraphs.',
            [['role' => 'user', 'content' => "Summarize this mediation session between {$names}.\n\nCover:\n- What the session was about\n- What each person expressed\n- Any agreements or next steps suggested by Accord\n\nKeep it positive and forward-looking.\n\n{$context['transcript']}"]],
            maxTokens: 400,
        ));
    }

    /**
     * Send a brief closing message as the session is finalized.
     */
    public function closingRitual(Chat $chat): ?Message
    {
        $context = $this->contextBuilder->buildForMemoryExtraction($chat);
        $names = implode(' and ', array_column($context['participants'], 'name'));

        if (empty(trim($context['transcript'] ?? ''))) {
            return null;
        }

        $content = trim($this->anthropic->message(
            'You are Accord. This session is closing. Write a brief, warm closing message (2–3 sentences) that: acknowledges what was discussed or accomplished, names any concrete next step if one emerged, and wishes them well. Be specific — reference what actually came up. Never be generic.',
            [['role' => 'user', 'content' => "Write the closing message for this session between {$names}.\n\n{$context['transcript']}"]],
            maxTokens: 150,
        ));

        if (empty($content)) {
            return null;
        }

        return $this->persistAndBroadcast($chat, $content);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────

    private function persistAndBroadcast(Chat $chat, string $content): Message
    {
        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'ai',
            'sender_id' => null,
            'content' => $content,
        ]);

        try {
            broadcast(new MessageSent($chat->id, [
                'id' => $message->id,
                'sender_type' => 'ai',
                'sender' => null,
                'content' => $content,
                'created_at' => $message->created_at->toISOString(),
            ]));
        } catch (\Exception) { /* non-fatal */ }

        $participantIds = $chat->participants()->pluck('users.id')->toArray();

        try {
            $this->webPushService->sendToUsers(
                $participantIds,
                'Accord',
                mb_substr($content, 0, 120),
                ['url' => '/chats/'.$chat->id],
            );
        } catch (\Exception) { /* non-fatal */ }

        return $message;
    }

    /**
     * Check if new content is too similar to recent AI messages.
     */
    private function isRepetitive(Chat $chat, string $newContent): bool
    {
        $recentAi = $chat->messages()
            ->where('sender_type', 'ai')
            ->latest()
            ->take(5)
            ->pluck('content')
            ->toArray();

        $normalizedNew = $this->normalizeForComparison($newContent);

        foreach ($recentAi as $existing) {
            similar_text($normalizedNew, $this->normalizeForComparison($existing), $percent);
            if ($percent > 50) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForComparison(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\w\s]/u', '', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Re-generate with an anti-repetition nudge.
     */
    private function regenerateWithVariation(string $systemPrompt, array $messages): string
    {
        return $this->anthropic->message(
            $systemPrompt."\n\nIMPORTANT: Your last few responses were very similar. Say something genuinely different, or if there's nothing new to add, keep it to one brief sentence.",
            $messages,
            maxTokens: 1024,
        );
    }
}
