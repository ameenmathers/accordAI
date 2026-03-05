<?php

namespace App\Services;

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
        private readonly ChatContextBuilder $contextBuilder
    ) {}

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

        return Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'ai',
            'sender_id' => null,
            'content' => $aiContent,
        ]);
    }
}
