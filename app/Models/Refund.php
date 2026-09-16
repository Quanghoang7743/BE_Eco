<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'refunds';
    const UPDATED_AT = null;

    protected $fillable = [
        'refund_code',
        'user_id',
        'ticket_id',
        'refund_type',
        'reason',
        'reason_code',
        'old_status',
        'new_status',
        'source_type',
        'source_id',
        'processed_by',
        'processed_at',
        'metadata',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function logs()
    {
        return $this->hasMany(RefundLog::class);
    }
}
