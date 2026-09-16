<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreHour extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'store_hours';

    protected $fillable = [
        'store_id',
        'day_of_week',
        'open_time',
        'close_time',
        'is_closed',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
