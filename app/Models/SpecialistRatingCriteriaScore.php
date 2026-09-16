<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistRatingCriteriaScore extends Model
{
    protected $fillable = [
        'rating_id',
        'criteria_code',
        'raw_score',
        'weight_percent',
        'weighted_score',
        'data_source_json',
    ];

    protected $casts = [
        'raw_score' => 'float',
        'weight_percent' => 'float',
        'weighted_score' => 'float',
        'data_source_json' => 'array',
    ];

    public function rating(): BelongsTo
    {
        return $this->belongsTo(SpecialistRating::class, 'rating_id');
    }
}
