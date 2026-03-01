<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores short behavioral traits extracted from finalized chats by Claude.
 * Acts as our simple structured memory store, scoped by user + context_type.
 */
class UserContextNote extends Model
{
    protected $fillable = [
        'user_id',
        'context_type',
        'trait',
        'source_chat_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
