<?php

namespace App\Traits;

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
     * Validates that a message is substantive enough to warrant AI mediation.
     * Short/trivial messages (greetings, acknowledgements) may not need a response.
     */
    protected function shouldAiRespond(string $messageContent): bool
    {
        $trimmed = trim($messageContent);

        // Skip purely social messages below 10 characters
        if (strlen($trimmed) < 10) {
            return false;
        }

        // Always respond to messages containing conflict indicators
        $conflictKeywords = ['disagree', 'wrong', 'unfair', 'upset', 'angry', 'frustrated', 'problem', 'issue', 'never', 'always'];
        foreach ($conflictKeywords as $keyword) {
            if (stripos($trimmed, $keyword) !== false) {
                return true;
            }
        }

        // Default: respond to every user message for now (can be tuned)
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
