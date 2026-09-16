<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Order extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'orders';

    protected $fillable = [
        'id',
        // Legacy ecommerce fields used by current API place flow.
        'name',
        'phone',
        'address',
        'payment_method',
        'status_payment',
        'total',
        'vat_amount',
        'discount_amount',
        'voucher_code',
        'referrer_id',
        'transaction_id',

        // Extended order fields.
        'order_code',
        'user_id',
        'store_id',
        'parent_order_id',
        'status',
        'total_tickets_used',
        'total_amount',
        'discount_amount',
        'final_amount',
        'delivery_address',
        'delivery_location',
        'delivery_phone',
        'delivery_note',
        'confirmed_at',
        'preparing_at',
        'ready_at',
        'delivered_at',
        'returned_at',
        'cancelled_at',
        'stock_restored_at',
        'timeout_at',
        'cancel_reason',
        'cancelled_by',
        'sla_timeout_minutes',
        'sla_warning_sent',
        'shipping_method',
        'zalo_notification_sent',
        'tickets_earned',
        'required_topup',
        'ticket_fee_vnd',
        'credit_checked_at',
        'credit_snapshot_version',
        'credit_status',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stock_restored_at' => 'datetime',
        'timeout_at' => 'datetime',
        'sla_warning_sent' => 'boolean',
        'zalo_notification_sent' => 'boolean',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'required_topup' => 'decimal:2',
        'ticket_fee_vnd' => 'decimal:2',
        'credit_checked_at' => 'datetime',
        'credit_snapshot_version' => 'integer',
    ];

    // Một đơn hàng có nhiều item
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets()
    {
        return $this->hasMany(OrderTicket::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    // Nếu có user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function getTicketsEarnedAttribute()
    {
        return (int) $this->items->sum(function ($item) {
            return (int) ($item->ticket_reward_quantity ?? 0);
        });
    }

    protected $appends = ['tickets_earned'];

    /**
     * Calculate timeout_at based on sla_timeout_minutes
     */
    public function calculateTimeoutAt(): \Carbon\Carbon
    {
        return $this->created_at->copy()->addSeconds($this->resolveSlaTimeoutSeconds());
    }

    private function resolveSlaTimeoutSeconds(): int
    {
        try {
            $dbSeconds = (int) optional(appSetting::first())->order_sla_timeout_seconds;
            if ($dbSeconds > 0) {
                $customMinutes = $this->resolveStoreCustomSlaMinutes();
                if ($customMinutes !== null) {
                    return max(60, $customMinutes * 60);
                }

                if (!empty($this->sla_timeout_minutes)) {
                    return max(60, (int) $this->sla_timeout_minutes * 60);
                }

                return $dbSeconds;
            }
        } catch (\Throwable $e) {
            // Fall back to config when app_settings is unavailable.
        }

        $customMinutes = $this->resolveStoreCustomSlaMinutes();
        if ($customMinutes !== null) {
            return max(60, $customMinutes * 60);
        }

        if (!empty($this->sla_timeout_minutes)) {
            return max(60, (int) $this->sla_timeout_minutes * 60);
        }

        $configuredSeconds = (int) config('ecoluck.order_sla_timeout_seconds', 0);
        if ($configuredSeconds > 0) {
            return $configuredSeconds;
        }

        $fallbackMinutes = (int) ($this->sla_timeout_minutes ?? config('ecoluck.order_sla_timeout_minutes', 3));
        return max(60, $fallbackMinutes * 60);
    }

    private function resolveSlaTimeoutMinutesForStorage(): int
    {
        $customMinutes = $this->resolveStoreCustomSlaMinutes();
        if ($customMinutes !== null) {
            return $customMinutes;
        }

        if (!empty($this->sla_timeout_minutes)) {
            return max(1, (int) $this->sla_timeout_minutes);
        }

        try {
            $dbSeconds = (int) optional(appSetting::first())->order_sla_timeout_seconds;
            if ($dbSeconds > 0) {
                return max(1, (int) ceil($dbSeconds / 60));
            }
        } catch (\Throwable $e) {
            // Ignore and fall back to config.
        }

        $configuredSeconds = (int) config('ecoluck.order_sla_timeout_seconds', 0);
        if ($configuredSeconds > 0) {
            return max(1, (int) ceil($configuredSeconds / 60));
        }

        $configuredMinutes = (int) config('ecoluck.order_sla_timeout_minutes', 0);
        if ($configuredMinutes > 0) {
            return $configuredMinutes;
        }

        return max(1, (int) ceil($this->resolveSlaTimeoutSeconds() / 60));
    }

    private function resolveStoreCustomSlaMinutes(): ?int
    {
        if (empty($this->store_id)) {
            return null;
        }

        if (!Schema::hasTable('stores') || !Schema::hasColumn('stores', 'custom_sla_minutes')) {
            return null;
        }

        $minutes = Store::query()
            ->where('id', (string) $this->store_id)
            ->value('custom_sla_minutes');

        if ($minutes === null) {
            return null;
        }

        $minutes = (int) $minutes;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * Check if order is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if order is close to timeout (within 2-3 minutes)
     */
    public function isCloseToTimeout(int $warningMinutes = 2): bool
    {
        if (!$this->timeout_at) {
            return false;
        }

        $now = now();
        $secondsRemaining = $now->diffInSeconds($this->timeout_at, false);

        return $secondsRemaining > 0 && $secondsRemaining <= ($warningMinutes * 60);
    }

    /**
     * Check if order has timed out
     */
    public function hasTimedOut(): bool
    {
        if (!$this->timeout_at) {
            return false;
        }

        return now()->greaterThan($this->timeout_at) && $this->status === 'pending';
    }

    /**
     * Get remaining minutes until timeout
     */
    public function getRemainingMinutes(): int
    {
        if (!$this->timeout_at) {
            return 0;
        }

        $remainingSeconds = now()->diffInSeconds($this->timeout_at, false);
        if ($remainingSeconds <= 0) {
            return 0;
        }

        return (int) ceil($remainingSeconds / 60);
    }

    /**
     * Boot method to set timeout_at when creating order
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->sla_timeout_minutes)) {
                $model->sla_timeout_minutes = $model->resolveSlaTimeoutMinutesForStorage();
            }

            if (!$model->timeout_at) {
                $model->timeout_at = now()->addSeconds($model->resolveSlaTimeoutSeconds());
            }
        });

        static::created(function ($model) {
            // Recalculate timeout_at based on actual created_at timestamp
            if ($model->created_at && !$model->wasChanged('timeout_at')) {
                $calculatedTimeout = $model->calculateTimeoutAt();
                
                // Update if it differs significantly (more than 5 seconds)
                if ($model->timeout_at->diffInSeconds($calculatedTimeout, false) > 5) {
                    $model->update(['timeout_at' => $calculatedTimeout]);
                }
            }
        });
    }
}
