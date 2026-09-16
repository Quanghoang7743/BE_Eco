<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreAdminNotification extends Model
{
    use HasFactory;

    protected $table = 'store_admin_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'store_id',
        'order_id',
        'order_code',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (self $notification) {
            try {
                if ($notification->store_id) {
                    event(new \App\Events\StoreAdminNotificationCreated((string) $notification->store_id, $notification->toArray()));
                }
            } catch (\Throwable $e) {}
        });
    }
}
