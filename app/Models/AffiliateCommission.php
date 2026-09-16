<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_WAITING_ORDER_COMPLETE = 'WAITING_ORDER_COMPLETE';
    public const STATUS_WAITING_RETURN_PERIOD = 'WAITING_RETURN_PERIOD';
    public const STATUS_ELIGIBLE = 'ELIGIBLE';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_HELD = 'HELD';
    public const STATUS_PAYOUT_PENDING = 'PAYOUT_PENDING';
    public const STATUS_PAID = 'PAID';
    public const STATUS_CLAWBACK = 'CLAWBACK';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_WAITING_ORDER_COMPLETE,
        self::STATUS_WAITING_RETURN_PERIOD,
        self::STATUS_ELIGIBLE,
        self::STATUS_APPROVED,
        self::STATUS_HELD,
        self::STATUS_PAYOUT_PENDING,
    ];

    protected $fillable = [
        'affiliate_id',
        'customer_id',
        'order_id',
        'order_item_id',
        'product_id',
        'seller_id',
        'commission_type',
        'commission_value',
        'commission_base_amount',
        'pending_amount',
        'approved_amount',
        'tax_withholding_amount',
        'payout_amount',
        'status',
        'reason_cancelled',
        'held_reason',
        'approved_by',
        'approved_at',
        'eligible_at',
        'paid_at',
        'payout_batch_id',
        'source_assignment_id',
        'policy_snapshot_json',
    ];

    protected $casts = [
        'affiliate_id' => 'integer',
        'customer_id' => 'integer',
        'product_id' => 'integer',
        'commission_value' => 'decimal:2',
        'commission_base_amount' => 'decimal:2',
        'pending_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'tax_withholding_amount' => 'decimal:2',
        'payout_amount' => 'decimal:2',
        'approved_by' => 'integer',
        'source_assignment_id' => 'integer',
        'approved_at' => 'datetime',
        'eligible_at' => 'datetime',
        'paid_at' => 'datetime',
        'policy_snapshot_json' => 'array',
    ];

    public function affiliate()
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function seller()
    {
        return $this->belongsTo(Store::class, 'seller_id');
    }

    public function payoutBatch()
    {
        return $this->belongsTo(AffiliatePayoutBatch::class, 'payout_batch_id');
    }

    public function sourceAssignment()
    {
        return $this->belongsTo(AffiliateCustomer::class, 'source_assignment_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
