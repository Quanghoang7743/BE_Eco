<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOperatingHistory extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'store_operating_histories';

    protected $fillable = [
        'store_id',
        'action_code',
        'action_label',
        'status_after',
        'reason',
        'changed_by',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
