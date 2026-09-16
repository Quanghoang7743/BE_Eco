<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialistRatingPeriod extends Model
{
    protected $fillable = [
        'period_type',
        'period_from',
        'period_to',
        'name',
        'status',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
    ];
}
