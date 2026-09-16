<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'order_items';

    public $timestamps = false;

    protected $fillable = [
        'id',
        // Legacy ecommerce fields used by current API place flow.
        'order_id',
        'product_id',
        'variant_id',
        'product_name',
        'thumbnail',
        'price',
        'referrer_id',
        'commission_amount',

        // Extended store menu fields.
        'menu_item_id',
        'item_name',
        'quantity',
        'called_quantity',
        'unit_price',
        'total_price',
        'special_instructions',
        'ticket_reward_quantity',
        'return_blocked_at',
        'return_blocked_by_ticket_id',
        'flash_sale_item_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'price' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'called_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'ticket_reward_quantity' => 'integer',
        'return_blocked_at' => 'datetime',
        'flash_sale_item_id' => 'integer',
    ];

    // Một item thuộc về một đơn hàng
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(StoreMenu::class, 'menu_item_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function returnItems()
    {
        return $this->hasMany(OrderReturnItem::class);
    }

}
