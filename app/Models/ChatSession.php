<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSession extends Model
{
    protected $fillable = [
        'chat_id',
        'topic_summary',
        'session_summary',
        'resolved_issues',
        'unresolved_issues',
        'participant_ids',
    ];

    protected $casts = [
        'resolved_issues' => 'array',
        'unresolved_issues' => 'array',
        'participant_ids' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
