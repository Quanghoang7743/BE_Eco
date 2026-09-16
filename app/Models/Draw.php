<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Draw extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'draws';
    const UPDATED_AT = null;

    protected $fillable = [
        'room_id',
        'draw_code',
        'status',
        'random_seed',
        'algorithm',
        'block_hash',
        'block_number',
        'block_timestamp',
        'winner_ticket_id',
        'winner_user_id',
        'winner_index',
        'tx_hash',
        'tx_status',
        'tx_gas_fee',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'block_timestamp' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'tx_gas_fee' => 'decimal:2',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function winnerUser()
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    public function winnerTicket()
    {
        return $this->belongsTo(Ticket::class, 'winner_ticket_id');
    }

    public function logs()
    {
        return $this->hasMany(DrawLog::class);
    }
}
