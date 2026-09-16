<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistRatingAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rating_id',
        'specialist_id',
        'actor_id',
        'action',
        'old_values',
        'new_values',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function rating(): BelongsTo
    {
        return $this->belongsTo(SpecialistRating::class, 'rating_id');
    }
}
