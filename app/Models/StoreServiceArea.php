<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreServiceArea extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'store_service_areas';
    const UPDATED_AT = null;

    protected $fillable = [
        'store_id',
        'area_name',
        'area_polygon',
        'delivery_fee',
        'min_order_amount',
        'is_active',
    ];

    protected $casts = [
        'delivery_fee' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
