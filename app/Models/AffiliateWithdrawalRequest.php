<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateWithdrawalRequest extends Model
{
    use HasFactory;

    protected $table = 'affiliate_withdrawal_requests';

    protected $fillable = [
        'user_id',
        'referrals_count',
        'status',
        'approved_by',
        'approved_at',
        'cccd_document',
        'tax_code',
        'social_channel',
        'identity_phone',
        'bank_account_name',
        'bank_account_number',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
