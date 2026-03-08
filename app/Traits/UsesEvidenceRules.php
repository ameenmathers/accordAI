<?php

namespace App\Traits;

use App\Models\Chat;

/**
 * Provides evidence-based rules and frameworks that the AI uses to validate
 * and enrich its mediation responses. These are injected into prompts as
 * additional context to keep the AI grounded in established methodologies.
 */
trait UsesEvidenceRules
{
    /**
     * Returns a set of evidence-based frameworks relevant to the context type.
     * These are appended to the system prompt to guide the AI's reasoning.
     */
    protected function getEvidenceFrameworks(string $contextType): string
    {
        $frameworks = $this->frameworksByContext($contextType);

        if (empty($frameworks)) {
            return '';
        }

        $list = implode("\n", array_map(fn ($f) => "- {$f}", $frameworks));

        return "\n\nApplicable evidence-based frameworks for this context:\n{$list}";
    }

    /**
     * Core mediation principles always applied regardless of context.
     * Based on Harvard Negotiation Project and interest-based bargaining.
     */
    protected function getCoreMediatonPrinciples(): array
    {
        return [
            'Separate the people from the problem (Fisher & Ury, Getting to Yes)',
            'Focus on interests, not positions',
            'Generate options for mutual gain before evaluating them',
            'Use objective criteria to evaluate solutions',
            'Active listening: reflect back what you hear before responding',
        ];
    }

    /**
     * Decides whether the AI should respond to this message.
     *
     * Skips:
     *  - Empty messages
     *  - Pure one-word acknowledgements when AI already just responded
     *  - AI stacking: last two messages are both from AI
     */
    protected function shouldAiRespond(string $messageContent, Chat $chat): bool
    {
        $trimmed = trim($messageContent);

        if (strlen($trimmed) < 1) {
            return false;
        }

        // Always respond to conflict indicators regardless of other rules
        foreach (['disagree', 'wrong', 'unfair', 'upset', 'angry', 'frustrated', 'problem', 'issue', 'never', 'always'] as $keyword) {
            if (stripos($trimmed, $keyword) !== false) {
                return true;
            }
        }

        // Skip pure acknowledgements when AI just responded (last message is AI)
        $acks = ['ok', 'okay', 'k', 'sure', 'yeah', 'yes', 'yep', 'got it', 'thanks', 'thank you', 'lol', 'haha', 'nice', 'great', 'cool', 'right', 'agreed', 'alright'];
        if (mb_strlen($trimmed) <= 20 && in_array(strtolower($trimmed), $acks, true)) {
            $lastMessage = $chat->messages()->latest()->first();
            if ($lastMessage && $lastMessage->sender_type === 'ai') {
                return false;
            }
        }

        // Both-responded gate: in a 2-person chat, wait for both participants to weigh in
        // after Accord's last response before responding again. This gives participants
        // space to actually talk to each other rather than Accord dominating.
        if ($chat->participants()->count() >= 2) {
            $lastAiMsg = $chat->messages()->where('sender_type', 'ai')->latest()->first();
            if ($lastAiMsg) {
                $uniqueSpeakers = $chat->messages()
                    ->where('sender_type', 'user')
                    ->where('id', '>', $lastAiMsg->id)
                    ->distinct('sender_id')
                    ->count('sender_id');

                if ($uniqueSpeakers < 2) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns context-specific frameworks and research references.
     */
    private function frameworksByContext(string $contextType): array
    {
        return match ($contextType) {
            'relationship' => [
                'Gottman Method: identify contempt, criticism, defensiveness, stonewalling',
                'Non-violent communication (NVC): observations, feelings, needs, requests',
                'Attachment theory: identify secure vs. anxious vs. avoidant patterns',
            ],
            'business' => [
                'BATNA (Best Alternative to Negotiated Agreement)',
                'Interest-based bargaining vs. positional bargaining',
                'Harvard Negotiation Project principled negotiation',
                'McKinsey conflict resolution: clarify roles, expectations, constraints',
            ],
            'family' => [
                'Systems theory: the family as an interconnected system',
                'Bowen Family Systems: differentiation, triangulation awareness',
                'Structural family therapy: hierarchy, boundaries, subsystems',
            ],
            'financial' => [
                'Behavioral economics: loss aversion, anchoring, status quo bias',
                'Dave Ramsey debt snowball vs. avalanche method',
                'Joint financial planning principles (CFP framework)',
            ],
            default => [
                'Active listening and reflective responding',
                'Interest-based negotiation principles',
            ],
        };
    }
}
