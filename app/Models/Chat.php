<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Chat extends Model
{
    protected $fillable = [
        'context_type',
        'title',
        'topic_summary',
        'topic_locked',
        'mediation_phase',
        'session_summary',
        'human_message_count',
        'creator_context',
        'created_by',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
        'topic_locked' => 'boolean',
        'human_message_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants')
            ->withPivot('last_read_message_id')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
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

    public function assessments(): HasMany
    {
        return $this->hasMany(ChatAssessment::class)->orderBy('at_message_count');
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(ChatAssessment::class)->latestOfMany();
    }

    public function session(): HasOne
    {
        return $this->hasOne(ChatSession::class);
    }
}
