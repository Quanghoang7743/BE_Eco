<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpecialistRating extends Model
{
    protected $fillable = [
        'specialist_id',
        'rating_period_id',
        'store_id',
        'period_from',
        'period_to',
        'stars',
        'score',
        'total_score',
        'star_level',
        'previous_star_level',
        'status',
        'reviewed_by',
        'reviewed_at',
        'calculated_at',
        'approved_by',
        'approved_at',
        'notified_at',
        'notes',
        'data_source_json',
        'adjustment_reason',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'reviewed_at' => 'datetime',
        'calculated_at' => 'datetime',
        'approved_at' => 'datetime',
        'notified_at' => 'datetime',
        'data_source_json' => 'array',
        'total_score' => 'float',
        'score' => 'float',
        'star_level' => 'integer',
        'previous_star_level' => 'integer',
    ];

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SpecialistRatingPeriod::class, 'rating_period_id');
    }

    public function criteriaScores(): HasMany
    {
        return $this->hasMany(SpecialistRatingCriteriaScore::class, 'rating_id');
    }

    public function reviewRequests(): HasMany
    {
        return $this->hasMany(SpecialistRatingReviewRequest::class, 'rating_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SpecialistRatingAuditLog::class, 'rating_id');
    }
}
