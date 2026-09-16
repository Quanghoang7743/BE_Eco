<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OTP verification record used for the manual top-up security flow.
 *
 * @property int         $id
 * @property int         $admin_id
 * @property string      $context    e.g. "manual_topup:42"
 * @property string      $token      plaintext OTP (only stored transiently)
 * @property string      $token_hash bcrypt hash
 * @property int         $attempts   failed verification attempts
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $used_at
 */
class OtpVerification extends Model
{
    protected $table = 'otp_verifications';

    protected $fillable = [
        'admin_id',
        'context',
        'token',
        'token_hash',
        'attempts',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    /** The admin who requested this OTP. */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** Whether the OTP is still valid and unused. */
    public function isValid(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < 5;
    }
}
