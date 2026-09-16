<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlashSaleItem extends Model
{
    use HasFactory;

    protected $table = 'flash_sale_items';

    protected $fillable = [
        'flash_sale_id',
        'product_id',
        'variant_id',
        'flash_sale_price',
        'sale_quantity',
        'sold_quantity',
        'per_user_limit',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'flash_sale_price' => 'decimal:2',
        'sale_quantity' => 'integer',
        'sold_quantity' => 'integer',
        'per_user_limit' => 'integer',
    ];

    public function flashSale()
    {
        return $this->belongsTo(FlashSale::class, 'flash_sale_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
