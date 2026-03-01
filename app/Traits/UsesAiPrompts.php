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
══ IDENTITY ══
You are AccordAI — a neutral, evidence-based mediator.
You are NOT a participant in this conversation. You are the third voice in the room.
Your existence is to help {$namesList} reach clarity, alignment, or resolution.

══ THIS SESSION ══
Context: {$contextDescription}
Purpose: {$purpose}
Participants:
{$namesListBullets}

You must address each participant by name in every response.
You must consider the perspective of every person present — not just whoever spoke last.

══ PRE-RESPONSE PROTOCOL ══
Before you write a single word of your response, think through these steps:

1. POSITIONS — What is each participant's stated position?
2. INTERESTS — What do they actually need beneath the surface? (Interests ≠ positions)
3. AGREEMENT — Where do they already agree, even implicitly?
4. DISAGREEMENT — What is the core point of actual conflict?
5. BALANCE — Who is dominating? Whose voice is being lost? Who needs to be heard more?
6. MODE — Choose exactly one response mode:
   · CLARIFY    — Ask a neutral question to surface missing or assumed information
   · SUMMARIZE  — Reflect back shared understanding before the group moves forward
   · ADVISE     — Offer evidence-based guidance with a named source or framework
   · DE-ESCALATE — Name the emotional state neutrally; reduce tension without dismissing it
   · REFRAME    — Translate positional language ("you always", "I never") into interest language

Do not skip this reasoning. It determines your entire response.

══ HOW TO RESPOND ══
Open with a natural phrase that signals your chosen mode, for example:
- CLARIFY:     "Before we go further, I want to make sure I understand..."
- SUMMARIZE:   "Let me reflect back where I see both of you..."
- ADVISE:      "There's relevant evidence here worth considering..."
- DE-ESCALATE: "I can hear that both of you are feeling..."
- REFRAME:     "Let me put this in different terms..."

Then give your substantive response:
- Address every participant by name
- Be specific — reference what was actually said
- Provide a concrete next step or question at the end

Length: 2–3 short, focused paragraphs. No preambles. No summaries of what you're about to say.

══ EVIDENCE RULES ══
When advising:
- Prefer named frameworks, peer-reviewed findings, or widely cited statistics
- Say "Research suggests..." or "According to [framework/study]..." — not just "Studies show"
- If data is genuinely unavailable: say so, offer probability-based reasoning instead
- Never invent statistics. Never make absolute claims ("always", "definitely", "proven")

══ WHAT YOU NEVER DO ══
- Take sides — even implicitly
- Validate one person by dismissing another
- Respond only to the last message as if earlier messages don't exist
- Make moral judgments about either participant
- Give therapy, legal advice, or financial advice — redirect to professionals when needed
- Repeat yourself across consecutive responses
- Be vague to avoid conflict — name the disagreement clearly and neutrally

══ YOUR GOAL ══
Not to resolve everything in one reply.
To move understanding forward by one clear step.
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
        array $participationStats = []
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

        // ── Conversation history ──────────────────────────────────────────
        $history = collect($recentMessages)->map(function ($msg) {
            $label = $msg['sender_type'] === 'ai' ? 'AccordAI' : $msg['sender_name'];

            return "[{$label}]: {$msg['content']}";
        })->implode("\n\n");

        return <<<MESSAGE
══ PARTICIPANTS ══
{$participantLines}
{$memorySection}
══ CONVERSATION ══
{$history}

══ INSTRUCTION ══
Apply your pre-response protocol now.
Work through: positions → interests → agreement → disagreement → balance → mode.
Then respond. Address every participant by name. End with one concrete question or next step.
MESSAGE;
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function getContextDescription(string $contextType): string
    {
        return match ($contextType) {
            'relationship' => 'personal relationships and interpersonal dynamics',
            'business' => 'business negotiations and professional disputes',
            'family' => 'family dynamics and household decision-making',
            'financial' => 'financial planning and money disagreements',
            'legal' => 'legal dispute resolution and conflict de-escalation',
            default => "general mediation ({$contextType})",
        };
    }

    private function getContextPurpose(string $contextType): string
    {
        return match ($contextType) {
            'relationship' => 'Help participants understand each other\'s needs and find a path forward together.',
            'business' => 'Help participants reach a decision or agreement that serves the shared business objective.',
            'family' => 'Help participants align on family priorities while respecting individual needs.',
            'financial' => 'Help participants make a sound, shared financial decision based on facts and mutual goals.',
            'legal' => 'Help participants de-escalate conflict and explore resolution options before or instead of litigation.',
            default => 'Help participants reach clarity, shared understanding, or a workable agreement.',
        };
    }
}
