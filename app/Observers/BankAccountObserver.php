<?php

namespace App\Observers;

use App\Models\BankAccount;
use Illuminate\Support\Facades\Cache;

class BankAccountObserver
{
    public function created(BankAccount $bankAccount): void
    {
        $this->clearCache($bankAccount->user_id);
    }

    public function updated(BankAccount $bankAccount): void
    {
        $this->clearCache($bankAccount->user_id);
    }

    public function deleted(BankAccount $bankAccount): void
    {
        $this->clearCache($bankAccount->user_id);
    }

    public function restored(BankAccount $bankAccount): void
    {
        $this->clearCache($bankAccount->user_id);
    }

    public function forceDeleted(BankAccount $bankAccount): void
    {
        $this->clearCache($bankAccount->user_id);
    }

    private function clearCache(int $userId): void
    {
        Cache::forget(sprintf('bank_account_user_%d', $userId));
    }
}
