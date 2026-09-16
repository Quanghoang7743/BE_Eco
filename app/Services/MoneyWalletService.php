<?php

namespace App\Services;

use App\Events\MoneyWalletUpdated;
use App\Models\MoneyWalletAccount;
use App\Models\MoneyWalletLedgerEntry;
use App\Models\MoneyWalletWithdrawalRequest;
use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MoneyWalletService
{
    public function accountFor(string $ownerType, string $ownerId, string $currency = 'VND'): MoneyWalletAccount
    {
        $lookup = [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'currency' => $currency,
        ];

        try {
            $account = MoneyWalletAccount::query()->firstOrCreate(
                $lookup,
                [
                    'available_balance' => 0,
                    'locked_balance' => 0,
                    'version' => 0,
                ]
            );
        } catch (QueryException $exception) {
            $account = MoneyWalletAccount::query()->where($lookup)->first();
            if (! $account) {
                throw $exception;
            }
        }

        return MoneyWalletAccount::query()->whereKey((string) $account->id)->firstOrFail();
    }

    public function creditRefund(User $user, OrderReturn $return, float $amount, string $idempotencyKey, mixed $actor = null): MoneyWalletLedgerEntry
    {
        $ledger = DB::transaction(function () use ($user, $return, $amount, $idempotencyKey, $actor): MoneyWalletLedgerEntry {
            $amount = $this->money($amount);
            if ($amount <= 0) {
                throw new RuntimeException('Refund amount must be greater than zero.');
            }

            $existing = MoneyWalletLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertRefundLedgerMatches($existing, $return, $amount);

                return $existing;
            }

            $duplicateSource = MoneyWalletLedgerEntry::query()
                ->where('entry_type', 'refund_credit')
                ->where('source_type', 'order_return')
                ->where('source_id', (string) $return->id)
                ->lockForUpdate()
                ->first();
            if ($duplicateSource) {
                throw new RuntimeException('DUPLICATE_REFUND_SOURCE');
            }

            $account = $this->accountFor('user', (string) $user->id, 'VND');
            $account = MoneyWalletAccount::query()->whereKey((string) $account->id)->lockForUpdate()->firstOrFail();
            $balanceBefore = $this->money($account->available_balance ?? 0);
            $balanceAfter = $this->money($balanceBefore + $amount);

            $account->forceFill([
                'available_balance' => $balanceAfter,
                'version' => ((int) ($account->version ?? 0)) + 1,
            ])->save();

            return MoneyWalletLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'wallet_account_id' => (string) $account->id,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'entry_type' => 'refund_credit',
                'source_type' => 'order_return',
                'source_id' => (string) $return->id,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->actorId($actor),
                'metadata' => [
                    'return_code' => $return->return_code,
                    'order_id' => (string) $return->order_id,
                    'store_id' => (string) $return->store_id,
                ],
                'created_at' => now(),
            ]);
        });

        $this->broadcastWalletUpdated((string) $ledger->wallet_account_id, 'refund_credit', [
            'ledger_id' => (string) $ledger->id,
            'source_type' => 'order_return',
            'source_id' => (string) $return->id,
        ]);

        return $ledger;
    }

    public function lockWithdrawal(User $user, MoneyWalletWithdrawalRequest $request): MoneyWalletLedgerEntry
    {
        $ledger = DB::transaction(function () use ($user, $request): MoneyWalletLedgerEntry {
            $key = 'withdrawal:' . (string) $request->id . ':lock';
            $existing = MoneyWalletLedgerEntry::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $account = MoneyWalletAccount::query()
                ->whereKey((string) $request->wallet_account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = $this->money($request->amount ?? 0);
            $availableBefore = $this->money($account->available_balance ?? 0);
            if ($amount <= 0 || $availableBefore < $amount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $account->forceFill([
                'available_balance' => $this->money($availableBefore - $amount),
                'locked_balance' => $this->money(($account->locked_balance ?? 0) + $amount),
                'version' => ((int) ($account->version ?? 0)) + 1,
            ])->save();

            return MoneyWalletLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'wallet_account_id' => (string) $account->id,
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $availableBefore,
                'balance_after' => $this->money($account->available_balance),
                'entry_type' => 'withdrawal_lock',
                'source_type' => 'withdrawal_request',
                'source_id' => (string) $request->id,
                'idempotency_key' => $key,
                'created_by' => (string) $user->id,
                'metadata' => ['locked_balance_after' => $this->money($account->locked_balance)],
                'created_at' => now(),
            ]);
        });

        $this->broadcastWalletUpdated((string) $ledger->wallet_account_id, 'withdrawal_lock', [
            'ledger_id' => (string) $ledger->id,
            'withdrawal_id' => (string) $request->id,
        ]);

        return $ledger;
    }

    public function markWithdrawalPaid(MoneyWalletWithdrawalRequest $request, User $admin): MoneyWalletLedgerEntry
    {
        $ledger = DB::transaction(function () use ($request, $admin): MoneyWalletLedgerEntry {
            $key = 'withdrawal:' . (string) $request->id . ':paid';
            $request = MoneyWalletWithdrawalRequest::query()
                ->whereKey((string) $request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = MoneyWalletLedgerEntry::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            if ((string) $request->status === 'paid' && $request->paid_ledger_id) {
                $existing = MoneyWalletLedgerEntry::query()
                    ->whereKey((string) $request->paid_ledger_id)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            if (! in_array((string) $request->status, ['pending', 'approved'], true)) {
                throw new RuntimeException('INVALID_WITHDRAWAL_STATE');
            }

            $account = MoneyWalletAccount::query()
                ->whereKey((string) $request->wallet_account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = $this->money($request->amount ?? 0);
            $lockedBefore = $this->money($account->locked_balance ?? 0);
            if ($amount <= 0 || $lockedBefore < $amount) {
                throw new RuntimeException('INSUFFICIENT_LOCKED_BALANCE');
            }

            $availableBefore = $this->money($account->available_balance ?? 0);
            $account->forceFill([
                'locked_balance' => $this->money($lockedBefore - $amount),
                'version' => ((int) ($account->version ?? 0)) + 1,
            ])->save();

            $ledger = MoneyWalletLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'wallet_account_id' => (string) $account->id,
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $availableBefore,
                'balance_after' => $availableBefore,
                'entry_type' => 'withdrawal_paid',
                'source_type' => 'withdrawal_request',
                'source_id' => (string) $request->id,
                'idempotency_key' => $key,
                'created_by' => (string) $admin->id,
                'metadata' => ['locked_balance_after' => $this->money($account->locked_balance)],
                'created_at' => now(),
            ]);

            $request->forceFill([
                'status' => 'paid',
                'paid_ledger_id' => (string) $ledger->id,
            ])->save();

            return $ledger;
        });

        $this->broadcastWalletUpdated((string) $ledger->wallet_account_id, 'withdrawal_paid', [
            'ledger_id' => (string) $ledger->id,
            'withdrawal_id' => (string) $request->id,
        ]);

        return $ledger;
    }

    public function cancelWithdrawal(MoneyWalletWithdrawalRequest $request, User $actor, string $status = 'cancelled', ?string $reason = null): MoneyWalletLedgerEntry
    {
        $ledger = DB::transaction(function () use ($request, $actor, $status, $reason): MoneyWalletLedgerEntry {
            if (! in_array($status, ['cancelled', 'rejected'], true)) {
                throw new RuntimeException('INVALID_WITHDRAWAL_CANCEL_STATUS');
            }

            $key = 'withdrawal:' . (string) $request->id . ':' . $status;
            $request = MoneyWalletWithdrawalRequest::query()
                ->whereKey((string) $request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = MoneyWalletLedgerEntry::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            if (! in_array((string) $request->status, ['pending', 'approved'], true)) {
                throw new RuntimeException('INVALID_WITHDRAWAL_STATE');
            }

            $account = MoneyWalletAccount::query()
                ->whereKey((string) $request->wallet_account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = $this->money($request->amount ?? 0);
            $availableBefore = $this->money($account->available_balance ?? 0);
            $lockedBefore = $this->money($account->locked_balance ?? 0);
            if ($amount <= 0 || $lockedBefore < $amount) {
                throw new RuntimeException('INSUFFICIENT_LOCKED_BALANCE');
            }

            $account->forceFill([
                'available_balance' => $this->money($availableBefore + $amount),
                'locked_balance' => $this->money($lockedBefore - $amount),
                'version' => ((int) ($account->version ?? 0)) + 1,
            ])->save();

            $ledger = MoneyWalletLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'wallet_account_id' => (string) $account->id,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $availableBefore,
                'balance_after' => $this->money($account->available_balance),
                'entry_type' => $status === 'rejected' ? 'withdrawal_rejected' : 'withdrawal_cancelled',
                'source_type' => 'withdrawal_request',
                'source_id' => (string) $request->id,
                'idempotency_key' => $key,
                'created_by' => (string) $actor->id,
                'metadata' => [
                    'locked_balance_after' => $this->money($account->locked_balance),
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);

            $request->forceFill([
                'status' => $status,
                'admin_note' => $reason,
            ])->save();

            return $ledger;
        });

        $this->broadcastWalletUpdated((string) $ledger->wallet_account_id, 'withdrawal_' . $status, [
            'ledger_id' => (string) $ledger->id,
            'withdrawal_id' => (string) $request->id,
        ]);

        return $ledger;
    }

    private function actorId(mixed $actor): ?string
    {
        if ($actor instanceof User) {
            return (string) $actor->id;
        }

        if (is_scalar($actor) && trim((string) $actor) !== '') {
            return (string) $actor;
        }

        return null;
    }

    private function assertRefundLedgerMatches(MoneyWalletLedgerEntry $ledger, OrderReturn $return, float $amount): void
    {
        if (
            (string) $ledger->entry_type !== 'refund_credit'
            || (string) $ledger->source_type !== 'order_return'
            || (string) $ledger->source_id !== (string) $return->id
        ) {
            throw new RuntimeException('REFUND_IDEMPOTENCY_CONFLICT');
        }

        if ($this->money($ledger->amount) !== $amount) {
            throw new RuntimeException('REFUND_LEDGER_AMOUNT_MISMATCH');
        }
    }

    private function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    private function broadcastWalletUpdated(string $accountId, string $action, array $extra = []): void
    {
        $dispatch = function () use ($accountId, $action, $extra): void {
            $account = MoneyWalletAccount::query()->whereKey($accountId)->first();
            if (! $account || (string) $account->owner_type !== 'user') {
                return;
            }

            event(MoneyWalletUpdated::fromAccount($account, $action, $extra));
        };

        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
