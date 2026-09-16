<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreReview extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'store_reviews';

    protected $fillable = [
        'store_id',
        'user_id',
        'order_id',
        'product_name',
        'menu_item_id',
        'product_id',
        'rating',
        'comment',
        'images',
        'replied_by',
        'reply_text',
        'replied_at',
        'is_hidden',
        'hidden_reason',
    ];

    protected $casts = [
        'images' => 'array',
        'replied_at' => 'datetime',
        'is_hidden' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(StoreMenu::class, 'product_id', 'product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function replier()
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
