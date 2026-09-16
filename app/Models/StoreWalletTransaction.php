<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreWalletTransaction extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'store_wallet_transactions';

    protected $fillable = [
        'store_id',
        'type',
        'amount',
        'wallet_balance_before',
        'wallet_balance_after',
        'note',
        'reference_code',
        'created_by',
    ];

    protected $casts = [
        'amount'                 => 'decimal:2',
        'wallet_balance_before'  => 'decimal:2',
        'wallet_balance_after'   => 'decimal:2',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    /**
     * Nhãn hiển thị cho loại giao dịch.
     */
    public static array $typeLabels = [
        'topup'      => 'Nạp tiền',
        'withdraw'   => 'Rút tiền',
        'adjustment' => 'Điều chỉnh',
    ];

    public function getTypeLabelAttribute(): string
    {
        return static::$typeLabels[$this->type] ?? $this->type;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
