<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpecialistSettlement extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PAID = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'specialist_settlements';

    protected $fillable = [
        'specialist_id',
        'period_from',
        'period_to',
        'status',
        'total_eligible_tickets',
        'fee_per_ticket',
        'gross_income',
        'adjustment_amount',
        'final_income',
        'amount',
        'created_by',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'notes',
        'note',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'fee_per_ticket' => 'float',
        'gross_income' => 'float',
        'adjustment_amount' => 'float',
        'final_income' => 'float',
        'amount' => 'float',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(SpecialistTicketEarning::class, 'settlement_id');
    }
}
