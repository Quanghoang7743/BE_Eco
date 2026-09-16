<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpecialistNotification extends Model
{
    use HasFactory;

    protected $table = 'specialist_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'specialist_id',
        'type',
        'title',
        'message',
        'data',
        'action_url',
        'event_key',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function scopeForSpecialist(Builder $query, int $specialistId): Builder
    {
        return $query->where('specialist_id', $specialistId);
    }
}
