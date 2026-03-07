<?php

use App\Models\Chat;
use Illuminate\Support\Facades\Broadcast;

/*
 * Presence channel for a chat room.
 * Users may only join if they are an active participant.
 */
Broadcast::channel('chat.{chatId}', function ($user, int $chatId) {
    $chat = Chat::find($chatId);

    if (! $chat || ! $chat->participants()->where('user_id', $user->id)->exists()) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
