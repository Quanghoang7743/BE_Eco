<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateCustomer extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_TRANSFER_PENDING = 'TRANSFER_PENDING';
    public const STATUS_TRANSFERRED = 'TRANSFERRED';
    public const STATUS_REMOVED = 'REMOVED';
    public const STATUS_LOCKED = 'LOCKED';

    public const CURRENT_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_TRANSFER_PENDING,
        self::STATUS_LOCKED,
    ];

    protected $fillable = [
        'affiliate_id',
        'customer_id',
        'source_type',
        'source_id',
        'first_order_id',
        'assigned_at',
        'status',
        'transferred_to_affiliate_id',
        'transferred_at',
        'transfer_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'affiliate_id' => 'integer',
        'customer_id' => 'integer',
        'transferred_to_affiliate_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'assigned_at' => 'datetime',
        'transferred_at' => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function transferredToAffiliate()
    {
        return $this->belongsTo(User::class, 'transferred_to_affiliate_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function firstOrder()
    {
        return $this->belongsTo(Order::class, 'first_order_id');
    }
}
