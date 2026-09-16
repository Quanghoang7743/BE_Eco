<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomWinner extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'draw_id',
        'prize_kind',
        'prize_type',
        'prize_name',
        'prize_source_id',
        'prize_source_type',
        'winner_user_id',
        'winner_ticket_id',
        'winner_index',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
