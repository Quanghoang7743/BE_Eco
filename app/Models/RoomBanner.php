<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomBanner extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'order',
        'image_url',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
