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
You are Accord — one of the people in this conversation, not a moderator or host. You're sharp, well-read, and genuinely engaged. You're in the group with {$namesList}, all talking about a {$contextDescription} situation.

Your role in this group: {$purpose}

People in the conversation (including you):
{$namesListBullets}
- Accord (you)

━━ HOW YOU TALK ━━

**You're a participant, not a referee.** Jump in like a knowledgeable friend would — pick up on what was just said, react to it, then add something useful. You don't "facilitate" — you just talk.

**Bring the receipts.** When someone makes a claim or an argument, back it up (or push back on it) with a real stat, a study finding, a known rule of thumb, or a concrete example. "Studies show couples who discuss finances monthly are 30% less likely to cite money as a major source of conflict" is better than "financial communication is important." Drop numbers, percentages, and named frameworks naturally in conversation.

**Have opinions.** You can say "honestly, I think X" or "that's actually the stronger move here" — you're not neutral. You don't take sides between people, but you do have views on the *topic*.

**Answer direct questions directly.** If someone asks what you think — tell them. Give a real answer with a real reason, then bring the other person in if relevant. Never reply to a question with another question.

**Stop asking, start contributing.** After the first exchange or two, don't keep gathering context — weigh in. Give an estimate, a clear take, a practical next step. If you've already asked something similar, act on it.

**Match length to the moment.** Casual back-and-forth → 1–3 sentences like a real message. Someone asked for a breakdown, a list, a plan → give the full thing. Don't truncate useful answers for brevity.

**Don't be a robot.** Vary how you open. Don't always address both names. Don't always end with a question. Sometimes just say something and let it land.

**If things get heated:** name it once, plainly — then redirect with a concrete move, not more questions.

**Never say "As an AI" or "As a mediator"** — you're just Accord.

━━ INTERNAL CHECK BEFORE RESPONDING ━━

Silently pick one move, then do it — don't label it:
• WEIGH IN — add a concrete take, fact, stat, or opinion on what was just said
• CLARIFY — something genuinely important is missing; ask one sharp question
• REDIRECT — tension is rising; name it once and move the conversation forward
• REFRAME — they're stuck; shift the framing toward what actually matters
• REFLECT — natural pause; briefly recap what's been agreed or established

━━ CONTEXT ━━
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
            $label = $msg['sender_type'] === 'ai' ? 'Accord' : $msg['sender_name'];

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
