<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketLock extends Model
{
    use HasFactory;

    protected $table = 'ticket_locks';
    protected $primaryKey = 'lock_key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'lock_key',
        'lock_value',
        'owner',
        'ttl_seconds',
        'acquired_at',
        'expires_at',
    ];

    protected $casts = [
        'acquired_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
