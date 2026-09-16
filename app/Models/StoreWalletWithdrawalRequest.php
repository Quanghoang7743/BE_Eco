<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreWalletWithdrawalRequest extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'store_wallet_withdrawal_requests';

    protected $fillable = [
        'store_id',
        'financial_reconciliation_id',
        'amount',
        'status',
        'bank_name',
        'account_number',
        'account_name',
        'idempotency_key',
        'paid_at',
        'paid_by',
        'rejected_at',
        'rejected_by',
        'admin_note',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'rejected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function financialReconciliation(): BelongsTo
    {
        return $this->belongsTo(FinancialReconciliation::class, 'financial_reconciliation_id');
    }

    public function getVietQrBankCode(): string
    {
        $bankName = $this->bank_name;
        if (empty($bankName)) {
            return '';
        }
        
        $clean = mb_strtolower($this->stripAccents($bankName), 'UTF-8');
        $cleanAlpha = preg_replace('/[^a-z0-9]/', '', $clean);
        
        if (str_contains($cleanAlpha, 'vcb') || str_contains($cleanAlpha, 'vietcom') || str_contains($cleanAlpha, 'combank') || str_contains($cleanAlpha, 'ngoaithuong') || str_contains($cleanAlpha, 'ngoaithng')) {
            return 'VCB';
        }
        if (str_contains($cleanAlpha, 'tcb') || str_contains($cleanAlpha, 'techcom') || str_contains($cleanAlpha, 'kythuong') || str_contains($cleanAlpha, 'kthng')) {
            return 'TCB';
        }
        if (str_contains($cleanAlpha, 'ctg') || str_contains($cleanAlpha, 'icb') || str_contains($cleanAlpha, 'vietin') || str_contains($cleanAlpha, 'congthuong') || str_contains($cleanAlpha, 'congthng')) {
            return 'ICB';
        }
        if (str_contains($cleanAlpha, 'bidv') || str_contains($cleanAlpha, 'dautu') || str_contains($cleanAlpha, 'dut') || str_contains($cleanAlpha, 'utvaphat')) {
            return 'BIDV';
        }
        if (str_contains($cleanAlpha, 'agribank') || str_contains($cleanAlpha, 'nongnghiep') || str_contains($cleanAlpha, 'nongnghip') || str_contains($cleanAlpha, 'vba') || str_contains($cleanAlpha, 'phattriennongthon')) {
            return 'VBA';
        }
        if (str_contains($cleanAlpha, 'mbb') || str_contains($cleanAlpha, 'mbbank') || $cleanAlpha === 'mb' || str_contains($cleanAlpha, 'quandoi')) {
            return 'MB';
        }
        if (str_contains($cleanAlpha, 'acb') || str_contains($cleanAlpha, 'achau')) {
            return 'ACB';
        }
        if (str_contains($cleanAlpha, 'tpb') || str_contains($cleanAlpha, 'tienphong') || str_contains($cleanAlpha, 'tpbank')) {
            return 'TPB';
        }
        if (str_contains($cleanAlpha, 'sacombank') || str_contains($cleanAlpha, 'stb') || str_contains($cleanAlpha, 'saigonthuongtin')) {
            return 'STB';
        }
        if (str_contains($cleanAlpha, 'vpbank') || str_contains($cleanAlpha, 'vpb') || str_contains($cleanAlpha, 'vietnamthinhvuong')) {
            return 'VPB';
        }
        if (str_contains($cleanAlpha, 'shb') || str_contains($cleanAlpha, 'saigonhanoi')) {
            return 'SHB';
        }
        if (str_contains($cleanAlpha, 'hdbank') || str_contains($cleanAlpha, 'hdb') || str_contains($cleanAlpha, 'phattrienthanhpho')) {
            return 'HDB';
        }
        if (str_contains($cleanAlpha, 'scb') || str_contains($cleanAlpha, 'saigonbank')) {
            return 'SCB';
        }
        if (str_contains($cleanAlpha, 'vib') || str_contains($cleanAlpha, 'quocte')) {
            return 'VIB';
        }
        
        return strtoupper($bankName);
    }

    private function stripAccents(string $str): string
    {
        if (!class_exists('\Normalizer')) {
            return $str;
        }
        $normalized = \Normalizer::normalize($str, \Normalizer::FORM_D);
        $stripped = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized);
        return str_replace(['đ', 'Đ'], ['d', 'D'], $stripped);
    }

    public function getVietQrUrl(): string
    {
        $bankCode = $this->getVietQrBankCode();
        $accNo = preg_replace('/\s+/', '', $this->account_number);
        $amount = (int) $this->amount;
        
        $addInfo = 'Rut vi Ecoluck ' . substr($this->id, 0, 8);
        $accountName = strtoupper($this->stripAccents($this->account_name));
        
        return 'https://img.vietqr.io/image/' . rawurlencode($bankCode) . '-' . rawurlencode($accNo) . '-compact2.png' .
               '?amount=' . rawurlencode($amount) .
               '&addInfo=' . rawurlencode($addInfo) .
               '&accountName=' . rawurlencode($accountName);
    }
}
