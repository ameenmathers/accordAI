<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\Message;
use App\Traits\UsesAiPrompts;
use App\Traits\UsesEvidenceRules;
use Exception;
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

        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames)
            . $this->getEvidenceFrameworks($chat->context_type);

        $userMessage = $this->buildMediationUserMessage(
            $context['participants'],
            $context['messages'],
            $context['context_notes'],
            $context['participation_stats']
        );

        $stream = OpenAI::chat()->createStreamed([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 200,
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
        // Assemble full context including participation balance stats
        $context = $this->contextBuilder->build($chat);

        $participantNames = array_column($context['participants'], 'name');

        // System prompt = mediator identity + evidence frameworks for this context type
        $systemPrompt = $this->getMediatorSystemPrompt($chat->context_type, $participantNames)
            . $this->getEvidenceFrameworks($chat->context_type);

        // User message = structured context block the AI reads before responding
        $userMessage = $this->buildMediationUserMessage(
            $context['participants'],
            $context['messages'],
            $context['context_notes'],
            $context['participation_stats']   // new — balance data
        );

        // GPT-4o with enough tokens for a structured 2-3 paragraph response.
        // 350 tokens ≈ ~260 words — substantial enough to be useful, short enough for chat UI.
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 200, // enough for 2-3 sentences + concrete suggestion
            'temperature' => 0.85, // warmer, more natural
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
}
