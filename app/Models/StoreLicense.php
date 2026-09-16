<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreLicense extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'store_licenses';

    protected $fillable = [
        'store_id',
        'license_type',
        'license_number',
        'image_url',
        'status',
        'rejection_reason',
        'expiry_date',
    ];

    protected $casts = [
        'expiry_date' => 'date',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
