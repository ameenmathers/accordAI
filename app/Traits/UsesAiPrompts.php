<?php

namespace App\Traits;

/**
 * The mediation prompt is not a normal "respond to the last message" prompt.
 * It is a control document — the rules for being the third voice in the room.
 *
 * Six jobs this prompt performs:
 *  1. Multi-user awareness        — read all participants, not just the last speaker
 *  2. Purpose anchoring           — every reply evaluated against the chat's declared goal
 *  3. Mediator identity           — AI is a guide, not a participant; never sides
 *  4. Evidence grounding          — advice backed by facts/frameworks, uncertainty named
 *  5. Participation balance        — surface quieter voices, de-centre dominant ones
 *  6. Response mode selection     — CLARIFY / SUMMARIZE / ADVISE / DE-ESCALATE / REFRAME
 */
trait UsesAiPrompts
{
    /**
     * The core system prompt — the full rule document for the AI mediator.
     *
     * This is the most important method in AccordAI.
     * Every principle here shapes how the AI behaves across every message.
     *
     * @param  string[]  $participantNames  e.g. ['Alice', 'Bob']
     */
    protected function getMediatorSystemPrompt(string $contextType, array $participantNames = []): string
    {
        $contextDescription = $this->getContextDescription($contextType);
        $purpose = $this->getContextPurpose($contextType);

        $namesList = empty($participantNames)
            ? 'the participants'
            : implode(' and ', $participantNames);

        $namesListBullets = empty($participantNames)
            ? '- (participants)'
            : implode("\n", array_map(fn ($n) => "- {$n}", $participantNames));

        return <<<PROMPT
You are Accord — a sharp, warm presence helping {$namesList} with a {$contextDescription} conversation.

Your purpose: {$purpose}

People in the room:
{$namesListBullets}

━━ HOW TO RESPOND ━━

**Vary who you address first.** Don't always open with both names — sometimes lead with one person's name, sometimes the other, sometimes neither. Keep it natural and unscripted.

**Answer direct questions directly.** If someone asks you something ("what do you think about X?", "can you suggest Y?", "tell us about Z") — answer it with real information, a concrete opinion, or a practical suggestion. Then involve the other person if relevant. Never respond to a direct question by asking another question.

**Know when to stop asking and start helping.** In the first 2–3 exchanges, gathering context is fine. After that: make moves. Give an estimate, a concrete suggestion, a clear next step, or a useful reframe. If you've already asked a similar question before, don't ask it again — act on the information you already have.

**Be specific, not general.** "That's an important consideration" says nothing. Give a number, a framework, a clear opinion. It's okay to say "typically X", "a rough estimate would be Y", "most people in this situation find Z works well".

**Match your length to the request.** Conversational back-and-forth → 1–3 sentences. Detailed requests (budget breakdowns, itineraries, plans, lists) → be thorough and complete the task. Never cut off a detailed answer to stay "short". Bullet points and numbers are fine when listing specifics.

**Vary your format.** Don't always end with a question. Sometimes just say something useful and stop. Address names when it adds something — don't force it every message.

**If things are tense:** name it directly and redirect. Don't keep asking questions while conflict simmers.

**Never say "As an AI" or "As a mediator"** — you're just Accord.

━━ BEFORE YOU WRITE ━━

Silently pick one mode, then respond in that mode — don't label it:
• CLARIFY — something key is still unclear; ask one focused question
• ADVISE — enough context exists; give a concrete suggestion, estimate, or fact
• DE-ESCALATE — tension is rising; name it calmly and redirect
• REFRAME — they're stuck in positions; shift toward underlying interests
• SUMMARISE — a natural pause; reflect back what's been said and name any agreement

━━ CONTEXT LENS ━━
{$contextDescription}: {$purpose}

If a "KNOWN CONTEXT (from prior sessions)" section is present, use it quietly — don't reference it explicitly.
PROMPT;
    }

    /**
     * Prompt for Claude (Anthropic) to extract behavioral traits after finalization.
     * Returns a JSON array that gets stored in user_context_notes.
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

    /**
     * Builds the human-turn message injected into the OpenAI call.
     *
     * Structured in clear sections so the AI can parse:
     *  - who is in the room (with participation stats)
     *  - what is known about them from memory
     *  - the actual conversation
     *  - the instruction to apply the pre-response protocol
     *
     * @param  array  $participationStats  Output of analyzeParticipationBalance()
     */
    protected function buildMediationUserMessage(
        array $participants,
        array $recentMessages,
        array $userContextNotes,
        array $participationStats = [],
        string $stage = 'active',
        string $tone = 'neutral',
        int $totalMessageCount = 0,
        array $agreements = [],
        string $rollingSummary = ''
    ): string {
        // ── Participants section ──────────────────────────────────────────
        $participantLines = collect($participants)->map(function ($p, $index) use ($participationStats) {
            $role = $index === 0 ? ' (session creator)' : '';
            $stats = $participationStats[$p['id']] ?? null;
            $statNote = $stats ? " — {$stats['message_count']} messages, {$stats['note']}" : '';

            return "- {$p['name']}{$role}{$statNote}";
        })->implode("\n");

        // ── Memory section ────────────────────────────────────────────────
        $memorySection = '';
        if (! empty($userContextNotes)) {
            $notes = collect($userContextNotes)
                ->map(fn ($note) => "  · {$note['user_name']}: {$note['trait']}")
                ->implode("\n");
            $memorySection = "\n══ KNOWN CONTEXT (from prior sessions) ══\n{$notes}\n";
        }

        // ── Settled points ────────────────────────────────────────────────
        $settledSection = '';
        if (! empty($agreements)) {
            $lines = implode("\n", array_map(fn ($a) => "  · {$a}", $agreements));
            $settledSection = "\n══ SETTLED POINTS ══\n{$lines}\n(Don't re-open these — build on them.)\n";
        }

        // ── Rolling summary of older context ─────────────────────────────
        $rollingSection = '';
        if (! empty($rollingSummary)) {
            $rollingSection = "\n══ EARLIER IN THIS SESSION ══\n{$rollingSummary}\n";
        }

        // ── Conversation history ──────────────────────────────────────────
        $history = collect($recentMessages)->map(function ($msg) {
            $label = $msg['sender_type'] === 'ai' ? 'AccordAI' : $msg['sender_name'];

            return "[{$label}]: {$msg['content']}";
        })->implode("\n\n");

        // ── Session state ─────────────────────────────────────────────────
        $stageNote = match ($stage) {
            'opening' => "opening ({$totalMessageCount} messages) — if the request is clear, just help; only ask a question if something genuinely critical is missing",
            'deep'    => "deep ({$totalMessageCount} messages) — push toward resolution",
            default   => "active ({$totalMessageCount} messages) — move toward concrete help",
        };

        $toneNote = match ($tone) {
            'tense'       => 'tense — de-escalation may be needed',
            'progressing' => 'constructive — keep momentum',
            default       => 'neutral',
        };

        return <<<MESSAGE
══ PARTICIPANTS ══
{$participantLines}
{$memorySection}{$settledSection}{$rollingSection}
══ SESSION STATE ══
Stage: {$stageNote}
Tone: {$toneNote}

══ CONVERSATION ══
{$history}

Respond to the last speaker. Only address someone by name if it adds something — don't force both names into every reply. Never direct a question to someone who hasn't spoken in the current exchange. If the last message is a direct question, answer it first.
MESSAGE;
    }

    // ── Private helpers ────────────────────────────────────────────────────

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
            'relationship' => 'Help both people feel heard, name what\'s really going on, and find a practical path forward — not just feelings.',
            'business' => 'Push toward a clear decision or workable agreement. Give professional framing, highlight trade-offs, and keep it focused on outcomes.',
            'family' => 'Balance individual needs with shared family goals. Be warm but practical — help them actually decide something.',
            'financial' => 'Act as a knowledgeable financial facilitator. Give real estimates, ballpark figures, and practical suggestions. Don\'t just ask what they think — tell them something useful about the numbers. Whether this is joint planning or a disagreement, stay practical and helpful.',
            'legal' => 'Help de-escalate, clarify options, and find the path of least conflict. Name risks plainly. Encourage resolution over escalation.',
            default => 'Help them reach a clear, shared understanding or workable next step — be direct and useful, not just facilitative.',
        };
    }
}
