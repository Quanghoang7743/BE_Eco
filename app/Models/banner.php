<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'order',
        'image',
        'title',
        'headline',
        'description',
        'cta_label',
        'link',
        'position',
        'banner_type',
        'room_selection_mode',
        'room_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
