<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'image',
        'probability',
        'quantity',
        'type',
        'value',
        'product_id',
        'voucher_id'
    ];

    // Một phần thưởng có nhiều lượt quay trúng
    public function logs()
    {
        return $this->hasMany(RewardLog::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
