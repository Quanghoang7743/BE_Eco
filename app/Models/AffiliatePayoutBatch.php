<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliatePayoutBatch extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PAID = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'batch_code',
        'payout_period_from',
        'payout_period_to',
        'total_affiliates',
        'total_commission',
        'total_tax_withholding',
        'total_payout',
        'status',
        'created_by',
        'approved_by',
        'paid_by',
        'approved_at',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'payout_period_from' => 'date',
        'payout_period_to' => 'date',
        'total_affiliates' => 'integer',
        'total_commission' => 'decimal:2',
        'total_tax_withholding' => 'decimal:2',
        'total_payout' => 'decimal:2',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'paid_by' => 'integer',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(AffiliatePayoutBatchItem::class, 'batch_id');
    }

    public function commissions()
    {
        return $this->hasMany(AffiliateCommission::class, 'payout_batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
