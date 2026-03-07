<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UserTyping implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $chatId,
        public readonly int $userId,
        public readonly string $userName
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }
}
