<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrizeTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'prize_name',
        'monetary_value',
        'image_path',
        'description',
        'is_active',
    ];

    protected $casts = [
        'monetary_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
