<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AiMemoryService;
use App\Traits\UsesAiPrompts;
use App\Traits\UsesEvidenceRules;
use Exception;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Drives AI mediation using OpenAI GPT-4o.
 *
 * The intelligence is in the prompt, not this class.
 * This class handles:
 *  - assembling context (participants, messages, memory, participation balance)
 *  - building the system + user prompts via UsesAiPrompts
 *  - calling OpenAI and persisting the response
 *
 * The mediation prompt enforces 6 principles:
 *  1. Multi-user awareness
 *  2. Purpose anchoring
 *  3. Mediator identity (not a participant)
 *  4. Evidence-grounded advice
 *  5. Participation balance monitoring
 *  6. Response mode selection (CLARIFY/SUMMARIZE/ADVISE/DE-ESCALATE/REFRAME)
 */
class AiReasoningService
{
    use UsesAiPrompts, UsesEvidenceRules;

    public function __construct(
        private readonly ChatContextBuilder $contextBuilder,
        private readonly WebPushService $webPushService,
    ) {}

    /**
     * Generate a warm, readable summary of a finalized session.
     * Covers what was discussed, what each person expressed, and any agreed next steps.
     */
    public function generateSummary(Chat $chat): string
    {
        $context = $this->contextBuilder->buildForMemoryExtraction($chat);
        $names = implode(' and ', array_column($context['participants'], 'name'));

        if (empty(trim($context['transcript'] ?? ''))) {
            return '';
        }

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You write warm, constructive summaries of mediation sessions. Be concise — 2–3 short paragraphs.',
                ],
                [
                    'role' => 'user',
                    'content' => "Summarize this mediation session between {$names}.\n\nCover:\n- What the session was about\n- What each person expressed\n- Any agreements or next steps suggested by Accord\n\nKeep it positive and forward-looking.\n\n{$context['transcript']}",
                ],
            ],
            'max_tokens' => 350,
            'temperature' => 0.7,
        ]);

        return trim($response->choices[0]->message->content ?? '');
    }

    /**
     * Stream a mediation response token-by-token via a callback, then persist the complete message.
     * Use inside Laravel's response()->stream() for real-time AI output.
     */
    public function mediateStreaming(Chat $chat, \Closure $onToken): void
    {
        $context = $this->contextBuilder->build($chat);
        $participantNames = array_column($context['participants'], 'name');

        $this->handleToneCacheAndMemory($chat, $context);

        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames)
            . $this->getEvidenceFrameworks($chat->context_type);

        $rollingSummary = $this->maybeGetRollingSummary($chat, $context);

        $userMessage = $this->buildMediationUserMessage(
            $context['participants'],
            $context['messages'],
            $context['context_notes'],
            $context['participation_stats'],
            $context['stage'],
            $context['tone'],
            $context['total_message_count'],
            $context['agreements'],
            $rollingSummary
        );

        $stream = OpenAI::chat()->createStreamed([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 700,
            'temperature' => 0.85,
        ]);

        $fullContent = '';
        foreach ($stream as $response) {
            $token = $response->choices[0]->delta->content ?? '';
            if ($token !== '') {
                $fullContent .= $token;
                $onToken($token);
            }
        }

        if ($fullContent !== '') {
            $aiMessage = Message::create([
                'chat_id' => $chat->id,
                'sender_type' => 'ai',
                'sender_id' => null,
                'content' => $fullContent,
            ]);

            try {
                broadcast(new MessageSent($chat->id, [
                    'id' => $aiMessage->id,
                    'sender_type' => 'ai',
                    'sender' => null,
                    'content' => $fullContent,
                    'created_at' => $aiMessage->created_at->toISOString(),
                ]));
            } catch (\Exception) { /* non-fatal: Reverb may not be running */ }

            // Background push to all participants (for those not currently online)
            $participantIds = $chat->participants()->pluck('users.id')->toArray();
            try {
                $this->webPushService->sendToUsers(
                    $participantIds,
                    'Accord',
                    mb_substr($fullContent, 0, 120),
                    ['url' => '/chats/'.$chat->id]
                );
            } catch (\Exception) { /* non-fatal */ }
        }
    }

    /**
     * Generate and persist an AI mediation response.
     *
     * @throws Exception if OpenAI call fails
     */
    public function mediate(Chat $chat): Message
    {
        $context = $this->contextBuilder->build($chat);
        $participantNames = array_column($context['participants'], 'name');

        $this->handleToneCacheAndMemory($chat, $context);

        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames)
            . $this->getEvidenceFrameworks($chat->context_type);

        $rollingSummary = $this->maybeGetRollingSummary($chat, $context);

        $userMessage = $this->buildMediationUserMessage(
            $context['participants'],
            $context['messages'],
            $context['context_notes'],
            $context['participation_stats'],
            $context['stage'],
            $context['tone'],
            $context['total_message_count'],
            $context['agreements'],
            $rollingSummary
        );

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 700,
            'temperature' => 0.85,
        ]);

        $aiContent = $response->choices[0]->message->content;

        $aiMessage = Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'ai',
            'sender_id' => null,
            'content' => $aiContent,
        ]);

        try {
            broadcast(new MessageSent($chat->id, [
                'id' => $aiMessage->id,
                'sender_type' => 'ai',
                'sender' => null,
                'content' => $aiContent,
                'created_at' => $aiMessage->created_at->toISOString(),
            ]));
        } catch (\Exception) { /* non-fatal: Reverb may not be running */ }

        // Background push to all participants
        $participantIds = $chat->participants()->pluck('users.id')->toArray();
        try {
            $this->webPushService->sendToUsers(
                $participantIds,
                'Accord',
                mb_substr($aiContent, 0, 120),
                ['url' => '/chats/'.$chat->id]
            );
        } catch (\Exception) { /* non-fatal */ }

        return $aiMessage;
    }

    /**
     * Send a short nudge when both participants have gone quiet for 10+ minutes.
     * Called by CheckChatEngagement job.
     */
    public function sendEngagementNudge(Chat $chat): void
    {
        $context = $this->contextBuilder->build($chat);
        $participantNames = array_column($context['participants'], 'name');

        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames)
            ."\n\nIMPORTANT: Both participants have gone quiet. Send a single warm sentence that gently re-engages them — reference something specific from the conversation. No question needed.";

        $userMessage = $this->buildMediationUserMessage(
            $context['participants'],
            $context['messages'],
            $context['context_notes'],
            $context['participation_stats'],
            $context['stage'],
            $context['tone'],
            $context['total_message_count'],
            $context['agreements']
        );

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 60,
            'temperature' => 0.8,
        ]);

        $content = trim($response->choices[0]->message->content ?? '');
        if (empty($content)) {
            return;
        }

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
            $this->webPushService->sendToUsers($participantIds, 'Accord', $content, ['url' => '/chats/'.$chat->id]);
        } catch (\Exception) { /* non-fatal */ }
    }

    /**
     * For chats longer than the recent-message window, generate (and cache) a
     * 2–3 sentence summary of the older context so nothing important is lost.
     */
    private function maybeGetRollingSummary(Chat $chat, array $context): string
    {
        if ($context['older_boundary_id'] === null) {
            return '';
        }

        $cacheKey = "chat_rolling_summary_{$chat->id}_{$context['older_boundary_id']}";

        return Cache::remember($cacheKey, 3600, function () use ($chat, $context) {
            $olderMessages = $chat->messages()
                ->with('sender')
                ->where('id', '<', $context['older_boundary_id'])
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => '['.($m->sender_type === 'ai' ? 'Accord' : ($m->sender?->name ?? 'User')).'] '.$m->content)
                ->implode("\n");

            if (empty($olderMessages)) {
                return '';
            }

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'Summarise this conversation excerpt in 2–3 sentences. Cover: what was discussed, any tensions raised, and any agreements reached. Be factual and neutral.'],
                    ['role' => 'user', 'content' => $olderMessages],
                ],
                'max_tokens' => 120,
                'temperature' => 0.3,
            ]);

            return trim($response->choices[0]->message->content ?? '');
        });
    }

    /**
     * Cache the current tone and trigger memory extraction on a tension→resolution shift.
     * This is smarter than the fixed every-20-messages extraction.
     */
    private function handleToneCacheAndMemory(Chat $chat, array $context): void
    {
        $previousTone = Cache::get("chat_tone_{$chat->id}", 'neutral');
        Cache::put("chat_tone_{$chat->id}", $context['tone'], 3600);

        // Tension resolved → good moment to capture behavioral memory
        if ($previousTone === 'tense' && in_array($context['tone'], ['neutral', 'progressing'])) {
            $lastExtractKey = "last_memory_extraction_{$chat->id}";
            $lastExtractCount = Cache::get($lastExtractKey, 0);

            if ($context['total_message_count'] - $lastExtractCount >= 8) {
                Cache::put($lastExtractKey, $context['total_message_count'], 3600);
                try {
                    app(AiMemoryService::class)->extractAndStoreMemory($chat);
                } catch (\Exception) { /* non-fatal */ }
            }
        }
    }

    /**
     * Send a brief closing message as the session is finalized.
     * Acknowledges what was accomplished and wishes them well.
     */
    public function closingRitual(Chat $chat): ?Message
    {
        $context = $this->contextBuilder->buildForMemoryExtraction($chat);
        $names = implode(' and ', array_column($context['participants'], 'name'));

        if (empty(trim($context['transcript'] ?? ''))) {
            return null;
        }

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are Accord. This session is closing. Write a brief, warm closing message (2–3 sentences) that: acknowledges what was discussed or accomplished, names any concrete next step if one emerged, and wishes them well. Be specific — reference what actually came up. Never be generic.',
                ],
                [
                    'role' => 'user',
                    'content' => "Write the closing message for this session between {$names}.\n\n{$context['transcript']}",
                ],
            ],
            'max_tokens' => 150,
            'temperature' => 0.7,
        ]);

        $content = trim($response->choices[0]->message->content ?? '');

        if (empty($content)) {
            return null;
        }

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

        return $message;
    }
}
