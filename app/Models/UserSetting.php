<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    use HasFactory;

    protected $table = 'user_settings';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'notif_winner',
        'notif_order',
        'notif_room',
        'notif_ticket_expiry',
        'notif_promotion',
        'notif_via_zalo_oa',
        'notif_via_zalo_miniapp',
        'gps_enabled',
        'share_phone',
        'language',
        'theme',
        'hide_review_banner',
    ];

    protected $casts = [
        'notif_winner' => 'boolean',
        'notif_order' => 'boolean',
        'notif_room' => 'boolean',
        'notif_ticket_expiry' => 'boolean',
        'notif_promotion' => 'boolean',
        'notif_via_zalo_oa' => 'boolean',
        'notif_via_zalo_miniapp' => 'boolean',
        'gps_enabled' => 'boolean',
        'share_phone' => 'boolean',
        'hide_review_banner' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
