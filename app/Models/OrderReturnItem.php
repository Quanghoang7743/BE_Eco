<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderReturnItem extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $fillable = [
        'order_return_id',
        'order_item_id',
        'quantity',
        'unit_price_snapshot',
        'gross_amount',
        'discount_allocated',
        'refund_amount',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_snapshot' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'discount_allocated' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function orderReturn()
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
