<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasApiTokens;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // Chats this user participates in
    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_participants')
            ->withTimestamps();
    }

    // Messages sent by this user
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    // Extracted behavioral context notes stored by Claude (Anthropic)
    public function contextNotes(): HasMany
    {
        return $this->hasMany(UserContextNote::class);
    }

    // Pending invitations sent to this user (by username)
    public function pendingInvitations(): HasMany
    {
        return $this->hasMany(ChatInvitation::class, 'invited_user_id')
            ->whereNull('accepted_at')
            ->whereNull('declined_at');
    }
}
