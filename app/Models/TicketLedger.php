<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketLedger extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'ticket_ledger';
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'action_type',
        'amount_change',
        'balance_before',
        'balance_after',
        'reference_id',
        'reference_type',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
