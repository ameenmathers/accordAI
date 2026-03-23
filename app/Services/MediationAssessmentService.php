<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\ChatAssessment;
use Illuminate\Support\Facades\Log;

/**
 * Periodically analyses the conversation and produces a structured assessment.
 *
 * Triggered every ~8 human messages. The assessment captures:
 *  - Core issue and sub-issues with positions/interests
 *  - Resolution progress and recommended next technique
 *  - Phase transition recommendations
 *  - Per-participant engagement and stance
 *
 * The latest assessment is injected into the AI's context for every response.
 */
class MediationAssessmentService
{
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly ChatContextBuilder $contextBuilder,
    ) {}

    /**
     * Run an assessment if the threshold has been reached.
     * Returns the assessment if created, null if skipped.
     */
    public function assessIfDue(Chat $chat): ?ChatAssessment
    {
        $count = $chat->human_message_count;
        $lastAssessment = $chat->latestAssessment;
        $lastCount = $lastAssessment?->at_message_count ?? 0;

        if ($count < 8 || ($count - $lastCount) < 8) {
            return null;
        }

        return $this->assess($chat, $lastAssessment);
    }

    /**
     * Force an assessment (used during finalization).
     */
    public function assess(Chat $chat, ?ChatAssessment $previousAssessment = null): ?ChatAssessment
    {
        $previousAssessment ??= $chat->latestAssessment;

        $participants = $this->contextBuilder->loadParticipants($chat);
        $names = array_column($participants, 'name');
        $nameA = $names[0] ?? 'Participant A';
        $nameB = $names[1] ?? 'Participant B';

        // Load last 30 messages for assessment context
        $messages = $chat->messages()
            ->with('sender')
            ->latest()
            ->limit(30)
            ->get()
            ->reverse()
            ->map(fn ($m) => '['.($m->sender_type === 'ai' ? 'Accord' : ($m->sender?->name ?? 'User')).'] '.$m->content)
            ->implode("\n");

        if (empty(trim($messages))) {
            return null;
        }

        $previousContext = '';
        if ($previousAssessment) {
            $previousContext = "\nPrevious assessment (at message {$previousAssessment->at_message_count}):\n"
                .json_encode([
                    'core_issue' => $previousAssessment->core_issue,
                    'sub_issues' => $previousAssessment->sub_issues,
                    'resolved_agreements' => $previousAssessment->resolved_agreements,
                    'progress_assessment' => $previousAssessment->progress_assessment,
                    'mediation_phase' => $previousAssessment->mediation_phase,
                ], JSON_PRETTY_PRINT)."\n";
        }

        $prompt = <<<PROMPT
Analyze this mediation conversation and produce a structured assessment.
You are the mediator (Accord). This is a {$chat->context_type} mediation between {$nameA} and {$nameB}.
{$previousContext}
Conversation (recent messages):
{$messages}

Return ONLY valid JSON with this structure:
{
  "core_issue": "One sentence — what this mediation is fundamentally about",
  "sub_issues": [
    {
      "label": "Short name for this sub-issue",
      "status": "unresolved|progressing|resolved",
      "person_a_position": "What {$nameA} wants/believes",
      "person_b_position": "What {$nameB} wants/believes",
      "underlying_interests": "What needs/fears drive these positions",
      "linked_to": []
    }
  ],
  "resolved_agreements": ["Things both parties have agreed on"],
  "progress_assessment": "progressing|circular|stalled|escalating",
  "recommended_technique": "socratic|reflection|interest_exploration|reality_testing|reframing|agreement_building",
  "recommended_phase": "opening|issue_identification|interest_exploration|option_generation|reality_testing|agreement_building",
  "participant_states": {
    "{$nameA}": {"engagement": "high|moderate|low", "stance": "defensive|open|collaborative|withdrawn", "key_concern": "..."},
    "{$nameB}": {"engagement": "high|moderate|low", "stance": "defensive|open|collaborative|withdrawn", "key_concern": "..."}
  }
}

If the conversation has no real mediation topic (just testing, banter), return:
{"core_issue": null, "sub_issues": [], "resolved_agreements": [], "progress_assessment": "neutral", "recommended_technique": "none", "recommended_phase": "opening", "participant_states": {}}
PROMPT;

        try {
            $response = $this->client->message(
                system: 'You are a mediation analysis engine. Return only valid JSON. No explanation.',
                messages: [['role' => 'user', 'content' => $prompt]],
                maxTokens: 800,
                temperature: 0.3,
            );

            $data = json_decode($response, true);

            if (! is_array($data) || ! array_key_exists('core_issue', $data)) {
                Log::warning('[Assessment] Invalid JSON response', ['chat_id' => $chat->id, 'response' => $response]);

                return null;
            }

            $assessment = ChatAssessment::create([
                'chat_id' => $chat->id,
                'at_message_count' => $chat->human_message_count,
                'mediation_phase' => $chat->mediation_phase,
                'core_issue' => $data['core_issue'],
                'sub_issues' => $data['sub_issues'] ?? [],
                'resolved_agreements' => $data['resolved_agreements'] ?? [],
                'progress_assessment' => $data['progress_assessment'] ?? null,
                'recommended_technique' => $data['recommended_technique'] ?? null,
                'recommended_phase' => $data['recommended_phase'] ?? null,
                'participant_states' => $data['participant_states'] ?? [],
            ]);

            // Lock topic on first assessment if not already locked
            if (! $chat->topic_locked && ! empty($data['core_issue'])) {
                $chat->update([
                    'topic_summary' => $data['core_issue'],
                    'topic_locked' => true,
                ]);
            }

            // Phase transition (forward only)
            $this->maybeAdvancePhase($chat, $data['recommended_phase'] ?? null);

            Log::info('[Assessment] Created', [
                'chat_id' => $chat->id,
                'at_message_count' => $chat->human_message_count,
                'core_issue' => $data['core_issue'],
                'progress' => $data['progress_assessment'] ?? null,
            ]);

            return $assessment;
        } catch (\Exception $e) {
            Log::error('[Assessment] Failed', ['chat_id' => $chat->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function maybeAdvancePhase(Chat $chat, ?string $recommendedPhase): void
    {
        if (! $recommendedPhase) {
            return;
        }

        $phaseOrder = [
            'opening', 'issue_identification', 'interest_exploration',
            'option_generation', 'reality_testing', 'agreement_building', 'closing',
        ];

        $currentIndex = array_search($chat->mediation_phase, $phaseOrder);
        $recommendedIndex = array_search($recommendedPhase, $phaseOrder);

        if ($currentIndex !== false && $recommendedIndex !== false && $recommendedIndex > $currentIndex) {
            $chat->update(['mediation_phase' => $recommendedPhase]);
        }
    }
}
