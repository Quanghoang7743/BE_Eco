<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreLiabilityLedgerEntry extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'store_id',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'entry_type',
        'funding_source',
        'source_type',
        'source_id',
        'idempotency_key',
        'created_by',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
