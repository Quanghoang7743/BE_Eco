<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BankAccountService
{
    public const CACHE_KEY_FORMAT = 'bank_account_user_%d';

    public function getByUser(User $user): ?BankAccount
    {
        return Cache::remember(sprintf(self::CACHE_KEY_FORMAT, $user->id), now()->addMinutes(60), function () use ($user) {
            return BankAccount::with(['bank' => function ($query) {
                $query->select('id', 'code', 'name', 'image');
            }])
                ->where('user_id', $user->id)
                ->select('id', 'user_id', 'account_name', 'account_number', 'bank_id')
                ->first();
        });
    }

    public function createOrUpdateForUser(User $user, array $data): BankAccount
    {
        DB::beginTransaction();

        try {
            // If this account should be default, clear other defaults first
            $setDefault = isset($data['is_default']) ? (bool) $data['is_default'] : false;

            if ($setDefault) {
                BankAccount::where('user_id', $user->id)->update(['is_default' => false]);
            }

            $account = BankAccount::updateOrCreate(
                ['user_id' => $user->id],
                $data
            );

            BankAccount::where('user_id', $user->id)
                ->where('id', '!=', $account->id)
                ->delete();

            DB::commit();

            return $account;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }
}
