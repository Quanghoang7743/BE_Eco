<?php

namespace App\Observers;

use App\Models\Bank;
use App\Services\BankService;

class BankObserver
{
    public function created(Bank $bank): void
    {
        BankService::clearBankCaches();
    }

    public function updated(Bank $bank): void
    {
        BankService::clearBankCaches();
    }

    public function deleted(Bank $bank): void
    {
        BankService::clearBankCaches();
    }

    public function restored(Bank $bank): void
    {
        BankService::clearBankCaches();
    }

    public function forceDeleted(Bank $bank): void
    {
        BankService::clearBankCaches();
    }
}
