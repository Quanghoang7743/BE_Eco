<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistRatingReviewRequest extends Model
{
    protected $fillable = [
        'rating_id',
        'specialist_id',
        'request_reason',
        'status',
        'old_score',
        'new_score',
        'admin_response',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'old_score' => 'float',
        'new_score' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function rating(): BelongsTo
    {
        return $this->belongsTo(SpecialistRating::class, 'rating_id');
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }
}
