<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketBatch extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'ticket_batches';
    const UPDATED_AT = null;

    protected $fillable = [
        'batch_code',
        'user_id',
        'quantity',
        'total_amount',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
