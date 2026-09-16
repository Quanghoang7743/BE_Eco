<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreMenu extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'store_menu';

    protected $fillable = [
        'store_id',
        'product_id',
        'variant_id',
        'item_code',
        'item_name',
        'description',
        'price',
        'original_price',
        'is_lucky_item',
        'category',
        'sub_category',
        'image_url',
        'is_available',
        'is_featured',
        'sort_order',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'is_lucky_item' => 'boolean',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function reviews()
    {
        return $this->hasMany(StoreReview::class, 'product_id', 'product_id');
    }
}
