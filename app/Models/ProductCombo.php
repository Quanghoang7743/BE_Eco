<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCombo extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'bonus_product_id',
        'buy_quantity',
        'bonus_quantity',
    ];

    /**
     * Sản phẩm chính (mua)
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Sản phẩm được tặng
     */
    public function bonusProduct()
    {
        return $this->belongsTo(Product::class, 'bonus_product_id');
    }
}
