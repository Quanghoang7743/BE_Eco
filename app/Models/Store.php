<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected static function booted()
    {
        static::updated(function ($store) {
            if ($store->isDirty('is_open') || $store->isDirty('status')) {
                event(new \App\Events\StoreAdminProductUpdated(
                    (string) $store->id,
                    '',
                    'store_status_updated'
                ));
            }
        });
    }

    public const PUBLIC_STATUSES = ['active', 'approved'];

    protected $table = 'stores';

    protected $fillable = [
        'store_code',
        'owner_id',
        'sale_id',
        'sale_zalo_phone',
        'sale_support_notes',
        'sale_commission_rate',
        'name',
        'slogan',
        'slug',
        'public_url',
        'qr_code_custom',
        'brand_color',
        'description',
        'address',
        'location',
        'phone',
        'email',
        'logo_url',
        'banner_url',
        'banner_urls',
        'cover_image',
        'license_number',
        'license_image',
        'cccd_front_url',
        'cccd_back_url',
        'zalo_link',
        'facebook_link',
        'status',
        'is_open',
        'open_time',
        'close_time',
        'commission_rate',
        'rating_avg',
        'rating_count',
        'min_order_tickets',
        'max_order_tickets',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'representative',
        'business_type_id',
        'business_type',
        'bank_name',
        'bank_account',
        'custom_sla_minutes',
        'credit_limit',
        'ticket_debt',
        'wallet_balance',
        'credit_level',
        'credit_score',
        'is_debt_locked',
        'credit_version',
        'debt_locked_at',
        'debt_lock_reason',
    ];

    protected $casts = [
        'location' => 'array',
        'banner_urls' => 'array',
        'submitted_at' => 'datetime',
        'sale_id' => 'integer',
        'business_type_id' => 'integer',
        'sale_commission_rate' => 'decimal:2',
        'is_open' => 'boolean',
        'approved_at' => 'datetime',
        'commission_rate' => 'decimal:2',
        'rating_avg' => 'decimal:2',
        'custom_sla_minutes' => 'integer',
        'credit_limit' => 'decimal:2',
        'ticket_debt' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'credit_level' => 'integer',
        'credit_score' => 'integer',
        'is_debt_locked' => 'boolean',
        'credit_version' => 'integer',
        'debt_locked_at' => 'datetime',
    ];

    protected $appends = [
        'is_operating',
        'is_near_limit',
    ];

    /**
     * Computed attribute to determine if store is currently operating.
     * Uses Asia/Ho_Chi_Minh timezone for comparisons against store_hours.
     * Returns boolean.
     */
    public function getIsOperatingAttribute(): bool
    {
        // If manually closed via is_open flag, treat as not operating
        if (isset($this->attributes['is_open']) && ! (bool) $this->attributes['is_open']) {
            return false;
        }

        // If store_hours table not present, fall back to is_open flag
        if (!\Illuminate\Support\Facades\Schema::hasTable('store_hours')) {
            return (bool) ($this->attributes['is_open'] ?? true);
        }

        try {
            $now = \Carbon\Carbon::now('Asia/Ho_Chi_Minh');
            $day = (int) $now->dayOfWeek;

            $storeHour = \Illuminate\Support\Facades\DB::table('store_hours')
                ->where('store_id', $this->getKey())
                ->where('day_of_week', $day)
                ->first();

            if (! $storeHour) {
                return false;
            }

            if (! empty($storeHour->is_closed)) {
                return false;
            }

            $open = $storeHour->open_time;
            $close = $storeHour->close_time;
            if (! $open || ! $close) {
                return false;
            }

            $current = $now->format('H:i:s');
            return ! ($current < $open || $current >= $close);
        } catch (\Throwable $e) {
            return (bool) ($this->attributes['is_open'] ?? true);
        }
    }

    public function getIsNearLimitAttribute(): bool
    {
        try {
            return app(\App\Services\StoreCreditService::class)->isNearLimit($this);
        } catch (\Throwable $e) {
            $limit = max(1.0, (float) ($this->attributes['credit_limit'] ?? 200000));
            $debt = max(0.0, (float) ($this->attributes['ticket_debt'] ?? 0));
            $wallet = max(0.0, (float) ($this->attributes['wallet_balance'] ?? 0));

            return (max(0.0, $debt - $wallet) / $limit) >= 0.8;
        }
    }

    public function hasPublicStatus(): bool
    {
        if (! array_key_exists('status', $this->attributes)) {
            return true;
        }

        $status = strtolower(trim((string) ($this->attributes['status'] ?? '')));

        return in_array($status, self::PUBLIC_STATUSES, true);
    }

    public function isDebtLocked(): bool
    {
        return (bool) ($this->attributes['is_debt_locked'] ?? false);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->hasPublicStatus()
            && (bool) ($this->attributes['is_open'] ?? true)
            && ! $this->isDebtLocked();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function salePerson()
    {
        return $this->belongsTo(User::class, 'sale_id');
    }

    public function businessType()
    {
        return $this->belongsTo(StoreBusinessType::class, 'business_type_id');
    }

    public function menus()
    {
        return $this->hasMany(StoreMenu::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function reviews()
    {
        return $this->hasMany(StoreReview::class);
    }

    public function hours()
    {
        return $this->hasMany(StoreHour::class);
    }

    public function serviceAreas()
    {
        return $this->hasMany(StoreServiceArea::class);
    }

    public function licenses()
    {
        return $this->hasMany(StoreLicense::class, 'store_id');
    }

    public function approvalHistories()
    {
        return $this->hasMany(StoreApprovalHistory::class, 'store_id')->orderBy('attempt_number');
    }

    public function latestApprovalHistory()
    {
        return $this->hasOne(StoreApprovalHistory::class, 'store_id')->latestOfMany('attempt_number');
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'store_id');
    }

    public function userPreferences()
    {
        return $this->hasMany(UserStorePreference::class, 'store_id');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'specialist_store_assignments',
            'store_id',
            'user_id'
        );
    }

    public function admins()
    {
        return $this->belongsToMany(
            User::class,
            'specialist_store_assignments',
            'store_id',
            'user_id'
        )->where('role', 'store_admin');
    }

    public function walletTransactions()
    {
        return $this->hasMany(StoreWalletTransaction::class, 'store_id')->orderByDesc('created_at');
    }
}
