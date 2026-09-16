<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistFeeConfig extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_SPECIALIST = 'specialist';
    public const SCOPE_REGION = 'region';
    public const SCOPE_STORE_TYPE = 'store_type';

    protected $table = 'specialist_fee_configs';

    protected $fillable = [
        'config_name',
        'ticket_type_code',
        'fee_per_ticket',
        'apply_scope',
        'specialist_id',
        'region_id',
        'store_type',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'notes',
    ];

    protected $casts = [
        'fee_per_ticket' => 'float',
        'specialist_id' => 'integer',
        'region_id' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
