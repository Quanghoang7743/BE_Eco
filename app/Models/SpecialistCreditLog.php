<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistCreditLog extends Model
{
    public const ACTION_PLUS = 'PLUS';
    public const ACTION_MINUS = 'MINUS';
    public const ACTION_ADJUST = 'ADJUST';
    public const ACTION_CANCEL = 'CANCEL';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'specialist_id',
        'admin_user_id',
        'action_type',
        'points_change',
        'points_before',
        'points_after',
        'reason',
        'status',
        'source_log_id',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'metadata',
    ];

    protected $casts = [
        'specialist_id' => 'integer',
        'admin_user_id' => 'integer',
        'points_change' => 'integer',
        'points_before' => 'integer',
        'points_after' => 'integer',
        'source_log_id' => 'integer',
        'cancelled_by' => 'integer',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class, 'specialist_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function sourceLog(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_log_id');
    }
}
