<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderReturn extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RETURNING = 'returning';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_PLATFORM_REVIEWING = 'platform_reviewing';
    public const STATUS_REFUND_PROCESSING = 'refund_processing';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CLOSED = 'closed';

    public const OPEN_QUANTITY_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_RETURNING,
        self::STATUS_RECEIVED,
        self::STATUS_DISPUTED,
        self::STATUS_PLATFORM_REVIEWING,
        self::STATUS_REFUND_PROCESSING,
        self::STATUS_REFUNDED,
    ];

    protected $fillable = [
        'complaint_id',
        'return_code',
        'order_id',
        'user_id',
        'store_id',
        'status',
        'return_type',
        'refund_method',
        'refund_bank_info',
        'return_method',
        'reason_code',
        'reason',
        'images',
        'video',
        'refund_amount_estimated',
        'refund_amount_final',
        'refund_currency',
        'refund_funding_source',
        'funding_breakdown',
        'platform_advanced_amount',
        'refund_idempotency_key',
        'customer_wallet_ledger_id',
        'store_liability_ledger_id',
        'refund_processed_at',
        'refund_processed_by',
        'rejection_reason',
        'tracking_code',
        'carrier_name',
        'shipping_receipt_images',
        'shop_received_images',
        'shop_received_video',
        'shop_received_note',
        'dispute_reason',
        'dispute_evidence_images',
        'admin_resolution',
        'admin_resolution_note',
        'auto_approve_at',
    ];

    protected $casts = [
        'images' => 'array',
        'shipping_receipt_images' => 'array',
        'shop_received_images' => 'array',
        'dispute_evidence_images' => 'array',
        'funding_breakdown' => 'array',
        'refund_bank_info' => 'array',
        'refund_amount_estimated' => 'decimal:2',
        'refund_amount_final' => 'decimal:2',
        'platform_advanced_amount' => 'decimal:2',
        'refund_processed_at' => 'datetime',
        'auto_approve_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    public function customerWalletLedger()
    {
        return $this->belongsTo(MoneyWalletLedgerEntry::class, 'customer_wallet_ledger_id');
    }

    public function storeLiabilityLedger()
    {
        return $this->belongsTo(StoreLiabilityLedgerEntry::class, 'store_liability_ledger_id');
    }
}
