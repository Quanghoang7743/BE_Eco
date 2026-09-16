<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrawBlockchain extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'draw_blockchain';
    public $timestamps = false;

    protected $fillable = [
        'draw_id',
        'room_id',
        'blockchain_network',
        'contract_address',
        'draw_hash',
        'transaction_hash',
        'block_explorer_url',
        'published_at',
        'verified_at',
        'created_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function draw()
    {
        return $this->belongsTo(Draw::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
