<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id',
        'stock',
        'enabled',
        'price',
        'sale_price',
        'image',
        'commission_rate',
        'commission_rate_mode',
        'ticket_reward_quantity',
        'ticket_type_code',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values', 'variant_id', 'attribute_value_id');
    }

    public function variantAttributeValues()
    {
        return $this->hasMany(VariantAttributeValue::class, 'variant_id');
    }
}
