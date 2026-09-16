<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MoneyWalletAccount extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'currency',
        'available_balance',
        'locked_balance',
        'version',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'locked_balance' => 'decimal:2',
        'version' => 'integer',
    ];

    public function ledgerEntries()
    {
        return $this->hasMany(MoneyWalletLedgerEntry::class, 'wallet_account_id');
    }
}
