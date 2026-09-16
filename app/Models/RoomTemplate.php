<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomTemplate extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $fillable = [
        'room_template_set_id',
        'room_number',
        'room_name',
        'status',
        'required_votes',
        'ticket_type_code',
        'open_time',
        'close_time',
        'draw_time',
        'prize_template_id',
        'prize_type',
        'prize_value',
        'prize_description',
        'prize_image_url',
        'secondary_prizes',
    ];

    protected $casts = [
        'room_number' => 'integer',
        'required_votes' => 'integer',
        'prize_value' => 'decimal:2',
        'secondary_prizes' => 'array',
    ];

    public function templateSet()
    {
        return $this->belongsTo(RoomTemplateSet::class, 'room_template_set_id');
    }

    public function prizeTemplate()
    {
        return $this->belongsTo(PrizeTemplate::class, 'prize_template_id');
    }
}
