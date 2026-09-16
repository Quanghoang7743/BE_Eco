<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankingConfig extends Model
{
    protected $table = 'ranking_configs';

    protected $fillable = [
        'screen_key',
        'weight_distance',
        'weight_keyword',
        'weight_lucky_ticket',
        'weight_commission',
        'weight_purchase',
        'weight_rating',
        'weight_history',
        'weight_reputation',
        'weight_risk_penalty',
    ];

    protected $casts = [
        'weight_distance' => 'decimal:2',
        'weight_keyword' => 'decimal:2',
        'weight_lucky_ticket' => 'decimal:2',
        'weight_commission' => 'decimal:2',
        'weight_purchase' => 'decimal:2',
        'weight_rating' => 'decimal:2',
        'weight_history' => 'decimal:2',
        'weight_reputation' => 'decimal:2',
        'weight_risk_penalty' => 'decimal:2',
    ];
}
