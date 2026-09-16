<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRankingScore extends Model
{
    protected $table = 'product_ranking_scores';

    protected $fillable = [
        'product_id',
        'store_id',
        'base_ranking_score',
        'commission_rate',
        'commission_score',
        'lucky_ticket_quantity',
        'lucky_ticket_score',
        'purchase_qty',
        'purchase_score',
        'rating_value',
        'rating_score',
        'reputation_score',
        'risk_penalty_score',
        'last_calculated_at',
        'meta',
    ];

    protected $casts = [
        'base_ranking_score' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_score' => 'decimal:2',
        'lucky_ticket_quantity' => 'integer',
        'lucky_ticket_score' => 'decimal:2',
        'purchase_qty' => 'integer',
        'purchase_score' => 'decimal:2',
        'rating_value' => 'decimal:2',
        'rating_score' => 'decimal:2',
        'reputation_score' => 'decimal:2',
        'risk_penalty_score' => 'decimal:2',
        'last_calculated_at' => 'datetime',
        'meta' => 'array',
    ];
}
