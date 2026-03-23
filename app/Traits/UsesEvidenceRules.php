<?php

namespace App\Traits;

use App\Models\Chat;
use Illuminate\Support\Facades\Log;

/**
 * Provides evidence-based frameworks that can be injected into AI prompts
 * when the assessment recommends a specific technique.
 *
 * Also contains the hard PHP guard for AI stacking (last 2 messages both AI).
 * All other response-gating intelligence has moved to Claude's triage system.
 */
trait UsesEvidenceRules
{
    /**
     * Returns evidence-based frameworks relevant to the context type.
     * Only injected when the latest assessment recommends a specific technique.
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
     * Hard PHP guard: prevents AI from stacking 3+ messages in a row.
     * This is the only PHP-level response gate — everything else is Claude's triage.
     */
    protected function isAiStacking(Chat $chat): bool
    {
        $lastTwo = $chat->messages()->latest()->take(2)->get();

        if ($lastTwo->count() === 2 && $lastTwo->every(fn ($m) => $m->sender_type === 'ai')) {
            Log::info('[AI] isAiStacking=true', ['chat_id' => $chat->id]);

            return true;
        }

        return false;
    }

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
            ],
            'family' => [
                'Systems theory: the family as an interconnected system',
                'Bowen Family Systems: differentiation, triangulation awareness',
                'Structural family therapy: hierarchy, boundaries, subsystems',
            ],
            'financial' => [
                'Behavioral economics: loss aversion, anchoring, status quo bias',
                'Joint financial planning principles (CFP framework)',
            ],
            default => [
                'Active listening and reflective responding',
                'Interest-based negotiation principles',
            ],
        };
    }
}
