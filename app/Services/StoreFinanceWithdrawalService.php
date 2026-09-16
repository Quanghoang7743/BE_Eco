<?php

namespace App\Services;

use App\Events\StoreWalletUpdated;
use App\Events\StoreFinanceReconciliationUpdated;
use App\Models\FinancialReconciliation;
use App\Models\Store;
use App\Models\StoreWalletWithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class StoreFinanceWithdrawalService
{
    private const ACTIVE_WITHDRAWAL_STATUSES = ['pending', 'approved', 'processing'];
    private const CONSUMING_WITHDRAWAL_STATUSES = ['pending', 'approved', 'processing', 'paid'];

    public function __construct(private readonly StoreFinanceReconciliationService $reconciliationService)
    {
    }

    public function status(Store $store, string $period, Carbon $baseDate): array
    {
        $currentSnapshot = $this->reconciliationService->buildSnapshot((string) $store->id, $period, $baseDate);
        $record = $this->reconciliationService->findLatestForPeriod((string) $store->id, $period, $baseDate);

        if ($record) {
            $record = $this->reconciliationService->ensureApprovedSourceIsFresh($record, $currentSnapshot);
        }

        $activeRequestAmount = $record ? $this->activeRequestAmount((string) $record->id) : 0.0;
        $consumedRequestAmount = $record ? $this->consumedRequestAmount((string) $record->id) : 0.0;
        $latestRequest = $record ? $this->latestRequest((string) $record->id) : null;
        $approvedRemaining = $record && $record->status === StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT
            ? $this->money(max(0.0, $this->approvedAmount($record) - $consumedRequestAmount))
            : 0.0;
        $walletBalance = $this->signedMoney($store->wallet_balance ?? 0);
        $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
        $accountBalance = $this->signedMoney($walletBalance - $ticketDebt);
        $walletAvailable = $this->money(max(0.0, min($walletBalance, $accountBalance)));
        $snapshotWithdrawable = $this->signedMoney($currentSnapshot['withdrawable_amount'] ?? 0);
        $currentWithdrawable = $record && $record->status === StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT
            ? $accountBalance
            : $snapshotWithdrawable;
        $currentRemaining = $this->money(max(0.0, $currentWithdrawable));
        $amountCanWithdraw = $this->money(min($approvedRemaining, $currentRemaining, $walletAvailable));

        $blockers = [];
        if (! $record) {
            $blockers[] = 'RECONCILIATION_NOT_FOUND';
        } elseif ($record->status === StoreFinanceReconciliationService::STATUS_PENDING_ADMIN) {
            $blockers[] = 'WAITING_SUPER_ADMIN_APPROVAL';
        } elseif ($record->status !== StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT) {
            $blockers[] = 'RECONCILIATION_NOT_APPROVED';
        }

        if ((bool) ($store->is_debt_locked ?? false)) {
            $blockers[] = 'DEBT_LOCKED';
        }

        if ($accountBalance < 0) {
            $blockers[] = 'ACCOUNT_BALANCE_NEGATIVE';
        }

        if ($ticketDebt > 0) {
            $blockers[] = 'TICKET_DEBT_EXISTS';
        }

        if (! $this->hasBank($store)) {
            $blockers[] = 'MISSING_BANK_ACCOUNT';
        }

        if ($record && $this->hasActiveRequest((string) $record->id)) {
            $blockers[] = 'PENDING_WITHDRAWAL_EXISTS';
        }

        if ($amountCanWithdraw <= 0) {
            $blockers[] = 'NON_POSITIVE_WITHDRAWABLE';
        }

        $warnings = [];
        if ($record && $record->status === StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT) {
            if ($approvedRemaining > 0 && $amountCanWithdraw < $approvedRemaining) {
                $warnings[] = 'WITHDRAWABLE_REDUCED_AFTER_APPROVAL';
            }
        }

        return [
            'period' => $period,
            'date' => $baseDate->toDateString(),
            'currency' => 'VND',
            'reconciliation' => $this->reconciliationService->serialize($record),
            'snapshot' => $currentSnapshot,
            'current_withdrawable_amount' => $currentWithdrawable,
            'approved_remaining_amount' => $approvedRemaining,
            'active_withdrawal_amount' => $activeRequestAmount,
            'wallet_available' => $walletAvailable,
            'amount_can_withdraw' => $amountCanWithdraw,
            'credit' => [
                'wallet_balance' => $walletBalance,
                'ticket_debt' => $ticketDebt,
                'account_balance' => $accountBalance,
                'is_debt_locked' => (bool) ($store->is_debt_locked ?? false),
            ],
            'bank_account' => [
                'bank_name' => $store->bank_name ?? null,
                'account_number' => $store->bank_account ?? null,
                'account_name' => $this->accountName($store),
                'is_complete' => $this->hasBank($store),
            ],
            'latest_request' => $latestRequest ? $this->serializeWithdrawal($latestRequest) : null,
            'eligibility' => [
                'can_withdraw' => $blockers === [],
                'blockers' => array_values(array_unique($blockers)),
                'warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    public function createWithdrawal(Store $store, string $period, Carbon $baseDate, array $input = [], mixed $actor = null): StoreWalletWithdrawalRequest
    {
        return DB::transaction(function () use ($store, $period, $baseDate, $input, $actor): StoreWalletWithdrawalRequest {
            $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existing = StoreWalletWithdrawalRequest::query()
                    ->where('store_id', (string) $store->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $lockedStore = Store::query()->whereKey((string) $store->id)->lockForUpdate()->firstOrFail();
            $status = $this->status($lockedStore, $period, $baseDate);
            if (! ($status['eligibility']['can_withdraw'] ?? false)) {
                throw new RuntimeException((string) ($status['eligibility']['blockers'][0] ?? 'WITHDRAWAL_NOT_ALLOWED'));
            }

            $recordId = (string) ($status['reconciliation']['id'] ?? '');
            if ($recordId === '') {
                throw new RuntimeException('RECONCILIATION_NOT_FOUND');
            }

            $maxAmount = $this->money($status['amount_can_withdraw'] ?? 0);
            $requestedAmount = $this->money($input['amount'] ?? $maxAmount);

            if ($requestedAmount <= 0) {
                throw new RuntimeException('INVALID_WITHDRAWAL_AMOUNT');
            }

            if ($requestedAmount > $maxAmount + 0.009) {
                throw new RuntimeException('WITHDRAWAL_AMOUNT_EXCEEDS_ALLOWED');
            }

            $walletBalance = $this->signedMoney($lockedStore->wallet_balance ?? 0);
            $ticketDebt = $this->signedMoney($lockedStore->ticket_debt ?? 0);
            $walletAfter = $this->signedMoney($walletBalance - $requestedAmount);

            $request = StoreWalletWithdrawalRequest::query()->create([
                'store_id' => (string) $lockedStore->id,
                'financial_reconciliation_id' => $recordId,
                'amount' => $requestedAmount,
                'status' => 'pending',
                'bank_name' => $lockedStore->bank_name,
                'account_number' => (string) $lockedStore->bank_account,
                'account_name' => $this->accountName($lockedStore),
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : (string) Str::uuid(),
                'metadata' => [
                    'period' => $period,
                    'date' => $baseDate->toDateString(),
                    'created_by' => $this->actorId($actor),
                    'calculation' => [
                        'current_withdrawable_amount' => $status['current_withdrawable_amount'],
                        'approved_remaining_amount' => $status['approved_remaining_amount'],
                        'wallet_available' => $status['wallet_available'],
                        'amount_can_withdraw' => $maxAmount,
                    ],
                ],
            ]);

            $lockedStore->forceFill([
                'wallet_balance' => $walletAfter,
                'credit_version' => (int) ($lockedStore->credit_version ?? 0) + 1,
            ])->save();

            $ledgerKey = 'store-withdrawal:' . (string) $request->id . ':requested';
            if ($this->ledgerSchemaReady() && ! DB::table('store_ticket_transactions')->where('idempotency_key', $ledgerKey)->exists()) {
                DB::table('store_ticket_transactions')->insert($this->ledgerPayload(
                    (string) $lockedStore->id,
                    $requestedAmount,
                    -1 * $requestedAmount,
                    $walletAfter,
                    $ticketDebt,
                    $ledgerKey,
                    (string) $request->id,
                    $actor,
                    'WALLET_WITHDRAW'
                ));
            }

            DB::afterCommit(fn () => $this->broadcastWalletUpdated(
                (string) $lockedStore->id,
                $walletAfter,
                $ticketDebt,
                'wallet_withdrawal_requested'
            ));

            return $request->refresh();
        });
    }

    public function withdrawals(Store $store, array $filters = [])
    {
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? 20)));

        return StoreWalletWithdrawalRequest::query()
            ->where('store_id', (string) $store->id)
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($pageSize);
    }

    public function markPaid(StoreWalletWithdrawalRequest $withdrawal, mixed $actor = null): StoreWalletWithdrawalRequest
    {
        $blockedReason = null;

        $updated = DB::transaction(function () use ($withdrawal, $actor, &$blockedReason): StoreWalletWithdrawalRequest {
            /** @var StoreWalletWithdrawalRequest $lockedRequest */
            $lockedRequest = StoreWalletWithdrawalRequest::query()
                ->whereKey((string) $withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status === 'paid') {
                return $lockedRequest;
            }

            if (! in_array((string) $lockedRequest->status, ['pending', 'approved', 'processing'], true)) {
                throw new RuntimeException('WITHDRAWAL_NOT_PAYABLE');
            }

            // Kiểm tra đơn này có phải đơn mới nhất chưa duyệt không
            if (! $this->reconciliationService->isLatestUnapproved(
                (string) $lockedRequest->store_id,
                'withdrawal',
                (string) $lockedRequest->id
            )) {
                throw new RuntimeException('LATEST_WITHDRAWAL_REQUIRED');
            }

            /** @var Store $store */
            $store = Store::query()->whereKey((string) $lockedRequest->store_id)->lockForUpdate()->firstOrFail();
            /** @var FinancialReconciliation|null $record */
            $record = FinancialReconciliation::query()
                ->whereKey((string) $lockedRequest->financial_reconciliation_id)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new RuntimeException('RECONCILIATION_NOT_FOUND');
            }

            if ($record->status === StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT) {
                $record = $this->reconciliationService->ensureApprovedSourceIsFresh(
                    $record,
                    $this->reconciliationService->buildSnapshot(
                        (string) $store->id,
                        (string) $record->period,
                        Carbon::parse($record->period_date)
                    )
                );
            }

            if ($record->status !== StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT) {
                $blockedReason = $record->status === StoreFinanceReconciliationService::STATUS_PENDING_ADMIN
                    ? 'RECONCILIATION_REAPPROVAL_REQUIRED'
                    : 'RECONCILIATION_NOT_APPROVED';

                return $lockedRequest;
            }

            $amount = $this->money($lockedRequest->amount);
            $walletBalance = $this->signedMoney($store->wallet_balance ?? 0);
            $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
            $accountBalance = $this->signedMoney($walletBalance - $ticketDebt);
            $consumedExcludingCurrent = $this->consumedRequestAmount(
                (string) $record->id,
                (string) $lockedRequest->id
            );
            $approvedRemainingForRequest = $this->money(max(0.0, $this->approvedAmount($record) - $consumedExcludingCurrent));

            if ((bool) ($store->is_debt_locked ?? false) || $ticketDebt > 0 || $accountBalance < 0) {
                throw new RuntimeException('STORE_WALLET_BALANCE_CHANGED');
            }

            if ($amount > $approvedRemainingForRequest + 0.009) {
                throw new RuntimeException('WITHDRAWAL_AMOUNT_EXCEEDS_CURRENT_ALLOWED');
            }

            $lockedRequest->forceFill([
                'status' => 'paid',
                'paid_at' => $lockedRequest->paid_at ?: now(),
                'paid_by' => $this->actorId($actor),
            ])->save();

            $this->syncPaidReconciliationStatus(
                (string) $lockedRequest->financial_reconciliation_id,
                $store
            );

            return $lockedRequest->refresh();
        });

        if ($blockedReason !== null) {
            throw new RuntimeException($blockedReason);
        }

        return $updated;
    }

    public function reject(StoreWalletWithdrawalRequest $withdrawal, mixed $actor = null, ?string $note = null): StoreWalletWithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawal, $actor, $note): StoreWalletWithdrawalRequest {
            /** @var StoreWalletWithdrawalRequest $lockedRequest */
            $lockedRequest = StoreWalletWithdrawalRequest::query()
                ->whereKey((string) $withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status === 'rejected') {
                return $lockedRequest;
            }

            if (! in_array((string) $lockedRequest->status, self::ACTIVE_WITHDRAWAL_STATUSES, true)) {
                throw new RuntimeException('WITHDRAWAL_NOT_REJECTABLE');
            }

            // Kiểm tra đơn này có phải đơn mới nhất chưa duyệt không
            if (! $this->reconciliationService->isLatestUnapproved(
                (string) $lockedRequest->store_id,
                'withdrawal',
                (string) $lockedRequest->id
            )) {
                throw new RuntimeException('LATEST_WITHDRAWAL_REQUIRED');
            }

            /** @var Store $store */
            $store = Store::query()->whereKey((string) $lockedRequest->store_id)->lockForUpdate()->firstOrFail();
            $amount = $this->money($lockedRequest->amount);
            $walletBalance = $this->signedMoney($store->wallet_balance ?? 0);
            $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
            $walletAfter = $this->signedMoney($walletBalance + $amount);

            $store->forceFill([
                'wallet_balance' => $walletAfter,
                'credit_version' => (int) ($store->credit_version ?? 0) + 1,
            ])->save();

            $ledgerKey = 'store-withdrawal:' . (string) $lockedRequest->id . ':refund';
            if ($this->ledgerSchemaReady() && ! DB::table('store_ticket_transactions')->where('idempotency_key', $ledgerKey)->exists()) {
                DB::table('store_ticket_transactions')->insert($this->ledgerPayload(
                    (string) $store->id,
                    $amount,
                    $amount,
                    $walletAfter,
                    $ticketDebt,
                    $ledgerKey,
                    (string) $lockedRequest->id,
                    $actor,
                    'WALLET_WITHDRAW_REFUND'
                ));
            }

            $lockedRequest->forceFill([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $this->actorId($actor),
                'admin_note' => $note,
            ])->save();

            DB::afterCommit(fn () => $this->broadcastWalletUpdated(
                (string) $store->id,
                $walletAfter,
                $ticketDebt,
                'wallet_withdrawal_refunded'
            ));

            return $lockedRequest->refresh();
        });
    }

    public function serializeWithdrawal(StoreWalletWithdrawalRequest $request): array
    {
        return [
            'id' => (string) $request->id,
            'store_id' => (string) $request->store_id,
            'financial_reconciliation_id' => (string) $request->financial_reconciliation_id,
            'amount' => (float) $request->amount,
            'status' => (string) $request->status,
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
            'admin_note' => $request->admin_note,
            'paid_at' => optional($request->paid_at)?->toIso8601String(),
            'rejected_at' => optional($request->rejected_at)?->toIso8601String(),
            'created_at' => optional($request->created_at)?->toIso8601String(),
            'updated_at' => optional($request->updated_at)?->toIso8601String(),
        ];
    }

    private function latestRequest(string $reconciliationId): ?StoreWalletWithdrawalRequest
    {
        return StoreWalletWithdrawalRequest::query()
            ->where('financial_reconciliation_id', $reconciliationId)
            ->orderByDesc('created_at')
            ->first();
    }

    private function activeRequestAmount(string $reconciliationId): float
    {
        return $this->money(StoreWalletWithdrawalRequest::query()
            ->where('financial_reconciliation_id', $reconciliationId)
            ->whereIn('status', self::ACTIVE_WITHDRAWAL_STATUSES)
            ->sum('amount'));
    }

    private function paidRequestAmount(string $reconciliationId): float
    {
        return $this->money(StoreWalletWithdrawalRequest::query()
            ->where('financial_reconciliation_id', $reconciliationId)
            ->where('status', 'paid')
            ->sum('amount'));
    }

    private function consumedRequestAmount(string $reconciliationId, ?string $exceptRequestId = null): float
    {
        return $this->money(StoreWalletWithdrawalRequest::query()
            ->where('financial_reconciliation_id', $reconciliationId)
            ->when($exceptRequestId !== null && $exceptRequestId !== '', fn ($query) => $query->where('id', '<>', $exceptRequestId))
            ->whereIn('status', self::CONSUMING_WITHDRAWAL_STATUSES)
            ->sum('amount'));
    }

    private function hasActiveRequest(string $reconciliationId): bool
    {
        return StoreWalletWithdrawalRequest::query()
            ->where('financial_reconciliation_id', $reconciliationId)
            ->whereIn('status', self::ACTIVE_WITHDRAWAL_STATUSES)
            ->exists();
    }

    private function approvedAmount(FinancialReconciliation $record): float
    {
        $approvedAmount = $this->signedMoney($record->approved_withdrawable_amount ?? 0);
        if ($approvedAmount > 0) {
            return $approvedAmount;
        }

        return $this->signedMoney($record->net_amount ?? $record->withdrawable_amount ?? 0);
    }

    private function syncPaidReconciliationStatus(string $reconciliationId, Store $store): void
    {
        /** @var FinancialReconciliation|null $record */
        $record = FinancialReconciliation::query()
            ->whereKey($reconciliationId)
            ->lockForUpdate()
            ->first();

        if (! $record) {
            return;
        }

        $paidTotal = $this->paidRequestAmount($reconciliationId);
        $approvedRemaining = $this->money(max(0.0, $this->approvedAmount($record) - $paidTotal));
        $walletBalance = $this->signedMoney($store->wallet_balance ?? 0);
        $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
        $accountBalance = $this->signedMoney($walletBalance - $ticketDebt);
        $walletAvailable = $this->money(max(0.0, min($walletBalance, $accountBalance)));
        $isFullyPaid = $approvedRemaining <= 0 || $walletAvailable <= 0;

        $record->forceFill([
            'status' => $isFullyPaid
                ? StoreFinanceReconciliationService::STATUS_PAID
                : StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT,
            'paid_at' => $isFullyPaid ? ($record->paid_at ?: now()) : null,
            'updated_at' => now(),
        ])->save();

        $this->reconciliationService->syncAdminFromFinancial(
            $record,
            $isFullyPaid ? 'paid' : 'approved'
        );

        DB::afterCommit(fn () => $this->broadcastReconciliationUpdated(
            $record->fresh(),
            $isFullyPaid ? 'paid' : 'payment_partial'
        ));
    }

    private function hasBank(Store $store): bool
    {
        return trim((string) ($store->bank_name ?? '')) !== ''
            && trim((string) ($store->bank_account ?? '')) !== ''
            && $this->accountName($store) !== '';
    }

    private function accountName(Store $store): string
    {
        return trim((string) ($store->representative ?: $store->name ?: ''));
    }

    private function ledgerSchemaReady(): bool
    {
        return Schema::hasTable('store_ticket_transactions')
            && Schema::hasColumn('store_ticket_transactions', 'transaction_id')
            && Schema::hasColumn('store_ticket_transactions', 'idempotency_key')
            && Schema::hasColumn('store_ticket_transactions', 'amount_vnd')
            && Schema::hasColumn('store_ticket_transactions', 'wallet_delta');
    }

    private function ledgerPayload(
        string $storeId,
        float $amount,
        float $walletDelta,
        float $walletAfter,
        float $ticketDebt,
        string $idempotencyKey,
        string $withdrawalId,
        mixed $actor,
        string $eventType
    ): array {
        $now = now();
        $payload = [
            'transaction_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'event_type' => $eventType,
            'tickets_consumed' => 0,
            'amount_vnd' => $amount,
            'wallet_delta' => $walletDelta,
            'debt_delta' => 0,
            'wallet_balance_after' => $walletAfter,
            'ticket_debt_after' => $ticketDebt,
            'idempotency_key' => $idempotencyKey,
            'source_type' => 'STORE_WITHDRAWAL',
            'source_id' => $withdrawalId,
            'metadata' => json_encode([
                'created_by' => $this->actorId($actor),
            ], JSON_UNESCAPED_UNICODE),
            'timestamp' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return array_filter(
            $payload,
            static fn ($value, string $column): bool => Schema::hasColumn('store_ticket_transactions', $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function broadcastWalletUpdated(
        string $storeId,
        float $walletBalance,
        float $ticketDebt,
        string $action
    ): void
    {
        try {
            event(new StoreWalletUpdated($storeId, [
                'action' => $action,
                'wallet_balance' => $walletBalance,
                'ticket_debt' => $ticketDebt,
                'account_balance' => $this->signedMoney($walletBalance - $ticketDebt),
            ]));
        } catch (\Throwable $exception) {
            logger()->warning('Failed to broadcast store wallet withdrawal update.', [
                'store_id' => $storeId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function broadcastReconciliationUpdated(?FinancialReconciliation $record, string $action): void
    {
        if (! $record) {
            return;
        }

        try {
            event(new StoreFinanceReconciliationUpdated(
                (string) $record->store_id,
                (string) $record->period,
                $record->period_date?->toDateString() ?? (string) $record->period_date,
                $action,
                $this->reconciliationService->step($record),
                $this->reconciliationService->serialize($record) ?? [],
            ));
        } catch (\Throwable $exception) {
            logger()->warning('Failed to broadcast store withdrawal reconciliation update.', [
                'store_id' => (string) $record->store_id,
                'reconciliation_id' => (string) $record->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function actorId(mixed $actor): ?string
    {
        if (is_object($actor) && isset($actor->id)) {
            return (string) $actor->id;
        }

        if (is_scalar($actor) && trim((string) $actor) !== '') {
            return (string) $actor;
        }

        return null;
    }

    private function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    private function signedMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
