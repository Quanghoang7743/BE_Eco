<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialReconciliation extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'financial_reconciliations';

    protected $fillable = [
        'store_id',
        'period',
        'period_date',
        'status',
        'revenue_total',
        'revenue_cash',
        'revenue_transfer',
        'revenue_cod',
        'affiliate_commission',
        'ticket_reward',
        'net_amount',
        'confirmed_at',
        'submitted_at',
        'paid_at',
    ];

    protected $casts = [
        'period_date' => 'date',
        'confirmed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'paid_at' => 'datetime',
        'revenue_total' => 'decimal:2',
        'revenue_cash' => 'decimal:2',
        'revenue_transfer' => 'decimal:2',
        'revenue_cod' => 'decimal:2',
        'affiliate_commission' => 'decimal:2',
        'ticket_reward' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
