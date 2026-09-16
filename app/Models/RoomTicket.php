<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomTicket extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'room_tickets';
    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'ticket_id',
        'user_id',
        'joined_at',
        'is_winner',
        'is_refunded',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'is_winner' => 'boolean',
        'is_refunded' => 'boolean',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
