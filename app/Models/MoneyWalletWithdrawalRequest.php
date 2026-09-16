<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MoneyWalletWithdrawalRequest extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $fillable = [
        'user_id',
        'wallet_account_id',
        'amount',
        'status',
        'bank_id',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'idempotency_key',
        'locked_ledger_id',
        'paid_ledger_id',
        'admin_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
