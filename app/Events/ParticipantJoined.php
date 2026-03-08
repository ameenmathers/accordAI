<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Fired when a new participant accepts an invitation and joins the chat.
 * Show.vue listens for '.participant.joined' on the presence channel to
 * show a live banner without requiring a page refresh.
 */
class ParticipantJoined implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $chatId,
        public readonly int $userId,
        public readonly string $userName,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'participant.joined';
    }
}
