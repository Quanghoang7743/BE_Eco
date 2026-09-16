<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RefundLog extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'refund_logs';
    public $timestamps = false;

    protected $fillable = [
        'refund_id',
        'action',
        'details',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }
}
