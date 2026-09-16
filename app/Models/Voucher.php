<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;
    protected $fillable = [
        'code',
        'description',
        'type',
        'discount_value',
        'max_discount',
        'min_order_value',
        'quantity',
        'used',
        'store_id',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    // Optional: helper để tính giảm giá thực tế
    public function calculateDiscount($orderAmount)
    {
        if ($this->type === 'percentage') {
            $discount = $orderAmount * ($this->discount_value / 100);
            if ($this->max_discount !== null && $discount > $this->max_discount) {
                return $this->max_discount;
            }
            return $discount;
        }

        if ($this->type === 'fixed') {
            return min($this->discount_value, $orderAmount);
        }

        return 0;
    }

    // Optional: Kiểm tra còn hiệu lực
    public function isValid()
    {
        $now = now();
        return $this->is_active &&
            $this->quantity > $this->used &&
            $this->start_date <= $now &&
            $this->end_date >= $now;
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_vouchers')
            ->withTimestamps()
            ->withPivot('used_at');
    }
}
