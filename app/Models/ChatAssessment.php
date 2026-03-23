<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAssessment extends Model
{
    protected $fillable = [
        'chat_id',
        'at_message_count',
        'mediation_phase',
        'core_issue',
        'sub_issues',
        'resolved_agreements',
        'progress_assessment',
        'recommended_technique',
        'recommended_phase',
        'participant_states',
    ];

    protected $casts = [
        'sub_issues' => 'array',
        'resolved_agreements' => 'array',
        'participant_states' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
