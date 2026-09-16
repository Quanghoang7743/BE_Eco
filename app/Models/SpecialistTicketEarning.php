<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistTicketEarning extends Model
{
    protected $table = 'specialist_ticket_earnings';

    protected $fillable = [
        'specialist_id',
        'store_id',
        'ticket_id',
        'ticket_status',
        'order_id',
        'settlement_id',
        'adjustment_source_earning_id',
        'status',
        'earning_status',
        'is_eligible',
        'ticket_value_amount',
        'fee_per_ticket',
        'commission_rate',
        'earning_amount',
        'reason_not_eligible',
        'generated_at',
        'earned_at',
        'approved_at',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'is_eligible' => 'boolean',
        'fee_per_ticket' => 'float',
        'earning_amount' => 'float',
        'ticket_value_amount' => 'float',
        'commission_rate' => 'float',
        'generated_at' => 'datetime',
        'earned_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SpecialistSettlement::class, 'settlement_id');
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
