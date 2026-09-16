<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class appSetting extends Model
{
    use HasFactory;

    public const DEFAULT_TICKET_EXPIRY_DAYS = 30;

    protected $fillable = [
        'app_name',
        'address',
        'latitude',
        'longitude',
        'phone',
        'email',
        'default_color',
        'description',
        'logo_path',
        'banner_path',
        'favicon_path',
        'donated',
        'ticket_expiry_days',
        'order_sla_timeout_seconds',
        'order_sla_warning_seconds',
        // VietQR Payment
        'vietqr_client_id',
        'vietqr_api_key',
        'vietqr_bank_id',
        'vietqr_account_no',
        'vietqr_account_name',
        // Homepage block configuration (JSON)
        'homepage_block',
        'homepage_product_blocks',
        'homepage_news_blocks',
    ];

    protected $casts = [
        'homepage_block' => 'array',
        'homepage_product_blocks' => 'array',
        'homepage_news_blocks' => 'array',
        'order_sla_timeout_seconds' => 'integer',
        'order_sla_warning_seconds' => 'integer',
    ];

    public function getTicketExpiryDaysAttribute($value): int
    {
        $days = (int) $value;

        return $days > 0 ? $days : self::DEFAULT_TICKET_EXPIRY_DAYS;
    }
}
