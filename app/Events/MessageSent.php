<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast when a message is saved to a chat.
 * Listened to by the Echo client in Show.vue.
 *
 * ShouldBroadcastNow: fires synchronously (no queue needed for local dev).
 * Switch to ShouldBroadcast if you add queue workers.
 */
class MessageSent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $chatId,
        public readonly array $message
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}
