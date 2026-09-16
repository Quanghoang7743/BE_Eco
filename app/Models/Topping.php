<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topping extends Model
{
    use HasFactory;

    protected $table = 'toppings';

    protected $fillable = [
        'store_id',
        'name',
        'price',
        'sale_price',
        'stock',
        'sku',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_toppings', 'topping_id', 'product_id')->withTimestamps();
    }
}
