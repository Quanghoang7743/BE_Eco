<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomTemplateSet extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $fillable = [
        'name',
        'rooms_per_day',
        'required_votes',
        'ticket_type_code',
        'status',
        'created_by',
        'last_run_at',
        'last_target_date',
    ];

    protected $casts = [
        'rooms_per_day' => 'integer',
        'required_votes' => 'integer',
        'last_run_at' => 'datetime',
        'last_target_date' => 'date:Y-m-d',
    ];

    public function roomTemplates()
    {
        return $this->hasMany(RoomTemplate::class)->orderBy('room_number');
    }
}
