<?php

namespace App\Traits;

use App\Models\ChatAssessment;

/**
 * Three prompt types for the mediation system:
 *
 *  1. Triage prompt      — lightweight RESPOND/LISTEN decision per message
 *  2. Mediation prompt   — full system prompt for actual AI responses
 *  3. Assessment prompt   — (lives in MediationAssessmentService)
 *
 * Plus: context block builder and conversation turn formatter.
 */
trait UsesAiPrompts
{
    // ── 1. TRIAGE PROMPT ──────────────────────────────────────────────────

    /**
     * Lightweight system prompt for the RESPOND/LISTEN triage call.
     * Kept intentionally short — this runs on every message.
     */
    protected function getTriageSystemPrompt(string $contextType, array $participantNames): string
    {
        $names = implode(' and ', $participantNames);
        $contextDescription = $this->getContextDescription($contextType);

        return <<<PROMPT
You are Accord's decision engine for a {$contextDescription} mediation between {$names}.

Given the recent messages and context, decide: should Accord speak now or keep listening?

Return ONLY one line in this format:
RESPOND: {reason}
LISTEN: {reason}

RESPOND when:
- Directly asked a question or mentioned by name
- Tension is rising and no one is de-escalating
- Conversation is going in circles or stalling
- Important misconception that could derail progress
- Good moment to build on agreement or bridge positions
- Accord hasn't spoken in 4+ human messages and there's something useful to add
- Someone new joined and hasn't been welcomed

LISTEN when:
- Participants are exchanging productively with each other
- Someone just acknowledged Accord's last message (ok, thanks, got it)
- Exit signals (just testing, nevermind, nvm, im done, whatever)
- Casual banter with no real issue on the table
- Accord's last response is still being actively discussed
- Only 1-2 messages since Accord last spoke and nothing significant has changed
PROMPT;
    }

    /**
     * Build the triage user message — last 5 messages plus minimal context.
     */
    protected function buildTriageUserMessage(array $recentMessages, ?ChatAssessment $assessment): string
    {
        // Only last 5 messages for triage (keep it tiny)
        $messages = array_slice($recentMessages, -5);
        $transcript = collect($messages)->map(function ($msg) {
            $label = $msg['sender_type'] === 'ai' ? 'Accord' : $msg['sender_name'];

            return "[{$label}]: {$msg['content']}";
        })->implode("\n");

        $assessmentNote = '';
        if ($assessment) {
            $assessmentNote = "\nCurrent topic: ".($assessment->core_issue ?? 'none established');
            $assessmentNote .= "\nProgress: ".($assessment->progress_assessment ?? 'unknown');
        }

        return "Recent messages:\n{$transcript}{$assessmentNote}\n\nShould Accord speak now?";
    }

    // ── 2. MEDIATION SYSTEM PROMPT ────────────────────────────────────────

    /**
     * Full system prompt — only used when triage returns RESPOND.
     */
    protected function getMediatorSystemPrompt(string $contextType, array $participantNames = []): string
    {
        $contextDescription = $this->getContextDescription($contextType);
        $purpose = $this->getContextPurpose($contextType);
        $namesList = empty($participantNames)
            ? 'the participants'
            : implode(' and ', $participantNames);

        return <<<PROMPT
You are Accord — a skilled mediator participating in a group conversation with {$namesList}. This is a {$contextDescription} mediation.

== YOUR APPROACH ==
You're a participant, not a facilitator. You speak naturally, like a knowledgeable friend who happens to have deep mediation training. Never say "As an AI" or label your techniques. You're just Accord.

Your role: {$purpose}

== MEDIATION TECHNIQUES (use naturally, never label) ==

Socratic questioning — Ask questions that help people discover their own contradictions or arrive at their own answers.
  "You said you want more independence, but you also said you make all decisions together — what does independence look like to you specifically?"

Active listening / reflection — Mirror back what someone said to confirm understanding before moving forward.
  "So what I'm hearing is the issue isn't the money itself, it's that the decision was made without discussing it first."

Interest-based exploration — When people state positions, dig for the underlying need.
  Position: "I want to sell the house." Interest: "I need financial security and a fresh start."

Reality testing — Gently introduce consequences of not resolving.
  "If you two can't agree on this, what happens in six months? What does that look like?"

Reframing — Shift the lens from blame to shared problem.
  Instead of "You never listen" → "It sounds like you both want to feel heard."

Progressive agreement building — Acknowledge small agreements and build on them.
  "You've both said the kids' schedule is the priority — that's actually a huge shared starting point."

Caucus-style focus — Sometimes address one person's concern specifically before broadening to both.

== RESPONSE RHYTHM ==
- Match length to moment. Banter → 1-2 sentences. Breakdown needed → give it.
- Never repeat a point you already made. Find a new angle or stay brief.
- Vary your openings. Don't always address both names. Don't always end with a question.
- If things are heated: name it once, then redirect with a concrete move.
- If there's nothing to mediate, just be a normal person. Match their energy.
- Have opinions on topics (not people). Back claims with real data when relevant.
- Answer direct questions directly. Then bring others in if relevant.
- After giving a substantive response, let it breathe — don't immediately follow up.

== CONTEXT ==
Before the conversation, you'll receive a context block with the current mediation phase, latest assessment, tracked issues, and prior session history. Use this naturally. Never reference it explicitly.
PROMPT;
    }

    // ── 3. CONTEXT BLOCK ──────────────────────────────────────────────────

    /**
     * Build the context block injected as the first user message before conversation turns.
     * Sections are omitted entirely when empty.
     */
    protected function buildContextBlock(
        string $contextType,
        string $mediationPhase,
        ?string $topicSummary,
        ?string $creatorContext,
        ?ChatAssessment $assessment,
        array $priorSessions,
        array $userContextNotes,
        array $participants,
    ): string {
        $sections = [];

        // Session context
        $phaseDescription = $this->getPhaseDescription($mediationPhase);
        $topic = $topicSummary ?? 'Not yet established (early conversation)';
        $sessionContext = "== SESSION CONTEXT ==\nMediation type: {$contextType}\nPhase: {$mediationPhase} — {$phaseDescription}\nTopic: {$topic}";
        if ($creatorContext) {
            $sessionContext .= "\nCreator's description: {$creatorContext}";
        }
        $sections[] = $sessionContext;

        // Latest assessment
        if ($assessment && $assessment->core_issue) {
            $assessmentLines = ["== LATEST ASSESSMENT ==", "Core issue: {$assessment->core_issue}"];

            if (! empty($assessment->sub_issues)) {
                $assessmentLines[] = 'Sub-issues:';
                $participantNamesList = array_column($participants, 'name');
                $nameA = $participantNamesList[0] ?? 'Person A';
                $nameB = $participantNamesList[1] ?? 'Person B';

                foreach ($assessment->sub_issues as $issue) {
                    $line = "  - {$issue['label']}: {$issue['status']}";
                    if (! empty($issue['person_a_position'])) {
                        $line .= " | {$nameA}: \"{$issue['person_a_position']}\"";
                    }
                    if (! empty($issue['person_b_position'])) {
                        $line .= " | {$nameB}: \"{$issue['person_b_position']}\"";
                    }
                    $assessmentLines[] = $line;
                    if (! empty($issue['underlying_interests'])) {
                        $assessmentLines[] = "    Underlying interests: {$issue['underlying_interests']}";
                    }
                }
            }

            if (! empty($assessment->resolved_agreements)) {
                $assessmentLines[] = 'Resolved agreements:';
                foreach ($assessment->resolved_agreements as $agreement) {
                    $assessmentLines[] = "  - {$agreement}";
                }
            }

            if ($assessment->progress_assessment) {
                $assessmentLines[] = "Progress: {$assessment->progress_assessment}";
            }
            if ($assessment->recommended_technique && $assessment->recommended_technique !== 'none') {
                $assessmentLines[] = "Suggested approach: {$assessment->recommended_technique}";
            }

            if (! empty($assessment->participant_states)) {
                $assessmentLines[] = 'Participant states:';
                foreach ($assessment->participant_states as $name => $state) {
                    $parts = array_filter([
                        $state['engagement'] ?? null,
                        $state['stance'] ?? null,
                        ! empty($state['key_concern']) ? "concern: {$state['key_concern']}" : null,
                    ]);
                    if ($parts) {
                        $assessmentLines[] = "  - {$name}: ".implode(', ', $parts);
                    }
                }
            }

            $sections[] = implode("\n", $assessmentLines);
        }

        // Prior sessions
        if (! empty($priorSessions)) {
            $lines = ['== PRIOR SESSIONS BETWEEN THESE PARTICIPANTS =='];
            foreach ($priorSessions as $session) {
                $lines[] = "Session on {$session['date']}: {$session['topic']}";
                if (! empty($session['resolved'])) {
                    $lines[] = '  Resolved: '.implode(', ', $session['resolved']);
                }
                if (! empty($session['unresolved'])) {
                    $lines[] = '  Left open: '.implode(', ', $session['unresolved']);
                }
            }
            $sections[] = implode("\n", $lines);
        }

        // Known participant traits
        if (! empty($userContextNotes)) {
            $lines = ['== KNOWN PARTICIPANT TRAITS =='];
            foreach ($userContextNotes as $note) {
                $lines[] = "  - {$note['user_name']}: {$note['trait']}";
            }
            $sections[] = implode("\n", $lines);
        }

        // Participants
        $participantLines = ['== PARTICIPANTS =='];
        foreach ($participants as $p) {
            $participantLines[] = "  - {$p['name']}";
        }
        $participantLines[] = '  - Accord (you)';
        $sections[] = implode("\n", $participantLines);

        return implode("\n\n", $sections);
    }

    // ── 4. CONVERSATION TURN FORMATTER ────────────────────────────────────

    /**
     * Convert message history into alternating user/assistant turns for Anthropic API.
     * Prefixes the context block as the first exchange.
     */
    protected function buildAlternatingTurns(string $contextBlock, array $recentMessages): array
    {
        $messages = [];

        // Context block as opening exchange
        $messages[] = ['role' => 'user', 'content' => $contextBlock];
        $messages[] = ['role' => 'assistant', 'content' => 'Understood.'];

        // Actual conversation
        foreach ($recentMessages as $msg) {
            if ($msg['sender_type'] === 'ai') {
                $messages[] = ['role' => 'assistant', 'content' => $msg['content']];
            } else {
                $label = $msg['sender_name'];
                $messages[] = ['role' => 'user', 'content' => "[{$label}]: {$msg['content']}"];
            }
        }

        // Merge consecutive same-role messages (Anthropic requires alternating)
        return $this->mergeConsecutiveMessages($messages);
    }

    /**
     * Anthropic requires strictly alternating user/assistant turns.
     * Merge consecutive messages of the same role.
     */
    protected function mergeConsecutiveMessages(array $messages): array
    {
        if (empty($messages)) {
            return [];
        }

        $merged = [$messages[0]];

        for ($i = 1; $i < count($messages); $i++) {
            $last = &$merged[count($merged) - 1];
            if ($last['role'] === $messages[$i]['role']) {
                $last['content'] .= "\n\n".$messages[$i]['content'];
            } else {
                $merged[] = $messages[$i];
            }
            unset($last);
        }

        return $merged;
    }

    // ── 5. OTHER PROMPTS ──────────────────────────────────────────────────

    /**
     * Prompt for Claude to extract behavioral traits after finalization.
     */
    protected function getMemoryExtractionPrompt(string $userName, string $contextType, string $chatTranscript): string
    {
        return <<<PROMPT
You are analyzing a completed mediation session transcript to extract behavioral insights about one specific participant.

Participant: {$userName}
Context type: {$contextType}

Full transcript:
{$chatTranscript}

Task:
Extract 1–3 behavioral traits about {$userName} based only on how they communicated in this conversation.

Strict rules:
- Observe only — do not interpret motivation or assign character
- Each trait: 1–2 sentences, grounded in specific observable behavior
- Focus on: communication style, how they handle disagreement, decision-making approach
- Do NOT diagnose, moralize, or speculate about emotional states
- Do NOT reference what other participants said about them

Return ONLY a valid JSON array of strings. No explanation, no wrapper:
["Trait one.", "Trait two."]
PROMPT;
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────

    private function getContextDescription(string $contextType): string
    {
        return match ($contextType) {
            'relationship' => 'personal relationships and interpersonal dynamics',
            'business' => 'business negotiations and professional disputes',
            'family' => 'family dynamics and household decision-making',
            'financial' => 'financial planning and money decisions',
            'legal' => 'legal dispute resolution and conflict de-escalation',
            default => "general mediation ({$contextType})",
        };
    }

    private function getContextPurpose(string $contextType): string
    {
        return match ($contextType) {
            'relationship' => "Help both people feel heard, name what's really going on, and find a practical path forward — not just feelings.",
            'business' => 'Push toward a clear decision or workable agreement. Give professional framing, highlight trade-offs, and keep it focused on outcomes.',
            'family' => 'Balance individual needs with shared family goals. Be warm but practical — help them actually decide something.',
            'financial' => "Act as a knowledgeable financial facilitator. Give real estimates, ballpark figures, and practical suggestions. Don't just ask what they think — tell them something useful about the numbers.",
            'legal' => 'Help de-escalate, clarify options, and find the path of least conflict. Name risks plainly. Encourage resolution over escalation.',
            default => 'Help them reach a clear, shared understanding or workable next step — be direct and useful, not just facilitative.',
        };
    }

    private function getPhaseDescription(string $phase): string
    {
        return match ($phase) {
            'opening' => 'Early conversation — building rapport, understanding the situation',
            'issue_identification' => 'Naming the core issues and sub-issues, checking understanding',
            'interest_exploration' => 'Digging beneath positions to find underlying needs and interests',
            'option_generation' => 'Brainstorming possible solutions, expanding options',
            'reality_testing' => 'Evaluating proposed solutions against reality and consequences',
            'agreement_building' => 'Formalizing agreements, ensuring commitment and next steps',
            'closing' => 'Session wrapping up',
            default => $phase,
        };
    }
}
