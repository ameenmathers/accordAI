<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'chat_id',
        'sender_type',
        'sender_id',
        'content',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    // The human sender — null for AI messages
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isFromAi(): bool
    {
        return $this->sender_type === 'ai';
    }

    public function isFromUser(): bool
    {
        return $this->sender_type === 'user';
    }
}
