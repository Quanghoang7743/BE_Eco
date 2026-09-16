<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemConfig extends Model
{
    public const KEY_TICKET_CALC_TYPE = 'ticket_calc_type';
    public const KEY_TICKET_VALUE = 'ticket_value';
    public const KEY_TICKET_TYPES = 'ticket_types';
    public const KEY_TICKET_DEFAULT_TYPE = 'ticket_default_type';

    // Manual top-up security
    public const KEY_MANUAL_TOPUP_ACCESS_CODE = 'manual_topup_access_code';
    public const KEY_OTP_EXPIRY_MINUTES       = 'otp_expiry_minutes';
    public const KEY_OTP_LENGTH               = 'otp_length';
    public const KEY_MANUAL_TOPUP_OTP_EMAIL    = 'manual_topup_otp_email';

    protected $table = 'system_configs';

    protected $fillable = [
        'config_key',
        'config_value',
        'value_type',
        'description',
        'updated_by',
    ];
}
