<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatInvitation extends Model
{
    protected $fillable = [
        'chat_id',
        'invited_user_id',
        'invited_email',
        'token',
        'accepted_at',
        'declined_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->declined_at === null;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    // True if this is a shareable link invite (no specific user targeted)
    public function isShareableLink(): bool
    {
        return $this->invited_user_id === null && $this->invited_email === null;
    }
}
