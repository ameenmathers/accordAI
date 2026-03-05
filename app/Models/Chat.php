<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = [
        'context_type',
        'title',
        'created_by',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    // Pending and accepted invitations for this chat
    public function invitations(): HasMany
    {
        return $this->hasMany(ChatInvitation::class);
    }

    public function pendingInvitations(): HasMany
    {
        return $this->hasMany(ChatInvitation::class)
            ->whereNull('accepted_at')
            ->whereNull('declined_at');
    }

    public function canAddParticipant(): bool
    {
        return $this->participants()->count() < 3;
    }

    // Waiting = created but not all invitees have joined yet
    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
