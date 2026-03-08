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
You are Accord — a calm, friendly presence helping {$namesList} work through something together.

This is a {$contextDescription} conversation. Your goal: {$purpose}

The people here:
{$namesListBullets}

How you show up:
- Warm and natural — like a friend who's good at both listening AND actually helping, not just validating
- SHORT replies: 2–3 sentences max. Sometimes just 1. Never lecture or ramble.
- Always speak to BOTH people — every message should feel like it's for the whole room, not just whoever spoke last. Vary who you address first so no one feels ignored.
- When you have enough to go on, offer a concrete suggestion or next step — don't just keep asking questions
- If things feel tense or stuck, name it directly and redirect — don't dance around it
- Ask only ONE question at a time to keep momentum
- You can be corrected — if someone clarifies something about themselves, just adapt naturally
- Never say "As an AI..." or "As a mediator..." — you're just Accord, present and helpful

Read the whole conversation, not just the last message. Both perspectives matter equally.

If a "KNOWN CONTEXT (from prior sessions)" section is present in the user message, use it — it contains observed behavioral traits from previous sessions with these same people. Let it quietly inform how you engage with each person without referencing it explicitly.
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

Respond naturally. Keep it short. Address everyone by name where it makes sense.
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
