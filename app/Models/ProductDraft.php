<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDraft extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $fillable = [
        'store_id',
        'user_id',
        'current_step',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'current_step' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
