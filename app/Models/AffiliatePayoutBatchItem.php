<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliatePayoutBatchItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'affiliate_id',
        'commission_count',
        'gross_amount',
        'tax_withholding_amount',
        'net_payout_amount',
        'bank_account_id',
        'status',
        'payment_reference',
        'payment_proof_url',
        'paid_at',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'affiliate_id' => 'integer',
        'commission_count' => 'integer',
        'gross_amount' => 'decimal:2',
        'tax_withholding_amount' => 'decimal:2',
        'net_payout_amount' => 'decimal:2',
        'bank_account_id' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(AffiliatePayoutBatch::class, 'batch_id');
    }

    public function affiliate()
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
