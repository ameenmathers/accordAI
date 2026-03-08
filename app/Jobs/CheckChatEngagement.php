<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\AiReasoningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fired 10 minutes after each user message.
 * If no new messages have arrived and the chat is still active,
 * Accord sends a brief re-engagement nudge.
 */
class CheckChatEngagement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $chatId,
        public readonly int $lastMessageId
    ) {}

    public function handle(AiReasoningService $aiReasoningService): void
    {
        $chat = Chat::find($this->chatId);

        if (! $chat || $chat->status !== 'active') {
            return;
        }

        // If any new message has arrived since we were queued, no nudge needed
        $latestMessage = $chat->messages()->latest()->first();
        if (! $latestMessage || $latestMessage->id !== $this->lastMessageId) {
            return;
        }

        $aiReasoningService->sendEngagementNudge($chat);
    }
}
