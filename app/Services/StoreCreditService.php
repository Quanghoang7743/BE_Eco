<?php

namespace App\Services;

use App\Events\StoreAdminProductUpdated;
use App\Events\StoreWalletUpdated;
use App\Models\AffiliateCommission;
use App\Models\FinancialReconciliation;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Store;
use App\Models\StoreAdminNotification;
use App\Models\appSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class StoreCreditService
{
    private const DEFAULT_CREDIT_LIMIT = 200000.0;
    private const DEFAULT_CREDIT_LEVEL = 1;
    private const DEFAULT_CREDIT_SCORE = 100;
    private const NEAR_LIMIT_RATIO = 0.8;

    private StoreLiabilityService $storeLiabilityService;

    public function __construct(
        private readonly TicketPricingService $ticketPricingService,
        ?StoreLiabilityService $storeLiabilityService = null
    ) {
        $this->storeLiabilityService = $storeLiabilityService ?? app(StoreLiabilityService::class);
    }

    public function creditSchemaReady(): bool
    {
        return Schema::hasTable('stores')
            && Schema::hasColumn('stores', 'credit_limit')
            && Schema::hasColumn('stores', 'ticket_debt')
            && Schema::hasColumn('stores', 'wallet_balance')
            && Schema::hasColumn('stores', 'credit_version')
            && Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'required_topup')
            && Schema::hasColumn('orders', 'ticket_fee_vnd')
            && Schema::hasColumn('orders', 'credit_checked_at')
            && Schema::hasColumn('orders', 'credit_snapshot_version')
            && Schema::hasColumn('orders', 'credit_status');
    }

    public function ledgerSchemaReady(): bool
    {
        return Schema::hasTable('store_ticket_transactions')
            && Schema::hasColumn('store_ticket_transactions', 'idempotency_key')
            && Schema::hasColumn('store_ticket_transactions', 'amount_vnd')
            && Schema::hasColumn('store_ticket_transactions', 'wallet_delta')
            && Schema::hasColumn('store_ticket_transactions', 'debt_delta');
    }

    private function returnTicketRefundSchemaReady(): bool
    {
        return Schema::hasTable('stores')
            && Schema::hasColumn('stores', 'ticket_debt')
            && Schema::hasColumn('stores', 'wallet_balance')
            && Schema::hasColumn('stores', 'credit_version')
            && $this->ledgerSchemaReady();
    }

    private function affiliateCommissionWalletSchemaReady(): bool
    {
        return Schema::hasTable('stores')
            && Schema::hasColumn('stores', 'wallet_balance')
            && Schema::hasColumn('stores', 'credit_version')
            && $this->ledgerSchemaReady();
    }

    public function syncWalletBalanceNotificationsForStore(string $storeId, int $limit = 200): void
    {
        $storeId = trim($storeId);
        if (
            $storeId === ''
            || ! Schema::hasTable('store_ticket_transactions')
            || ! Schema::hasTable('store_admin_notifications')
            || ! Schema::hasColumn('store_ticket_transactions', 'transaction_id')
            || ! Schema::hasColumn('store_ticket_transactions', 'store_id')
            || ! Schema::hasColumn('store_ticket_transactions', 'wallet_delta')
        ) {
            return;
        }

        $query = DB::table('store_ticket_transactions')
            ->where('store_id', $storeId)
            ->whereNotNull('transaction_id')
            ->where('wallet_delta', '!=', 0);

        if (Schema::hasColumn('store_ticket_transactions', 'timestamp')) {
            $query->orderByDesc('timestamp');
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($rows as $row) {
            $this->createWalletBalanceNotificationAfterCommit((array) $row);
        }
    }

    public function precheckPendingOrder(Order $order): array
    {
        if (! $this->creditSchemaReady() || ! $order->store_id || $this->normalizeStatus($order->status) !== 'pending') {
            return [];
        }

        $store = Store::query()->whereKey((string) $order->store_id)->first();
        if (! $store) {
            return [];
        }

        return $this->refreshOrderCreditSnapshot($order, $store);
    }

    public function refreshOrderCreditSnapshot(Order $order, Store $store): array
    {
        if (! $this->creditSchemaReady()) {
            return [];
        }

        $ticketFee = $this->calculateOrderTicketFee($order);
        $snapshot = $this->buildCapacitySnapshot($store, $ticketFee, $order);
        $now = now();

        $order->forceFill([
            'required_topup' => $snapshot['required_topup'],
            'ticket_fee_vnd' => $snapshot['ticket_fee_vnd'],
            'credit_checked_at' => $now,
            'credit_snapshot_version' => $snapshot['credit_version'],
            'credit_status' => $snapshot['credit_status'],
        ])->save();

        return $snapshot;
    }

    public function isSnapshotFresh(Order $order, Store $store): bool
    {
        if (! $this->creditSchemaReady()) {
            return true;
        }

        return $order->credit_checked_at !== null
            && (int) ($order->credit_snapshot_version ?? -1) === (int) ($store->credit_version ?? 0);
    }

    public function buildCapacitySnapshot(Store $store, float $ticketFee, ?Order $order = null): array
    {
        $creditLimit = $this->money($store->credit_limit ?? self::DEFAULT_CREDIT_LIMIT);
        $ticketDebt = $this->money($store->ticket_debt ?? 0);
        $walletBalance = $this->money($store->wallet_balance ?? 0);
        $displayRemainingCapacity = $this->signedMoney($creditLimit - $ticketDebt + $walletBalance);

        if ((bool) ($store->is_debt_locked ?? false) && $ticketDebt <= 0) {
            try {
                if (Schema::hasColumn('stores', 'is_debt_locked')) {
                    Store::query()->whereKey((string) $store->id)->update([
                        'is_debt_locked' => false,
                        'debt_locked_at' => null,
                        'debt_lock_reason' => null,
                        'updated_at' => now(),
                    ]);
                    $store->is_debt_locked = false;
                    $store->debt_locked_at = null;
                    $store->debt_lock_reason = null;

                    $this->broadcastStoreCreditVisibilityChanged((string) $store->id, 'store_debt_unlocked');
                }
            } catch (\Throwable $e) {
                try {
                    Log::warning('Self-healing failed to unlock store.', [
                        'store_id' => $store->id,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // buildCapacitySnapshot is also used by pure unit tests where Laravel facades are not booted.
                }
            }
        }

        $remainingCapacity = max(0.0, $displayRemainingCapacity);
        $accountBalance = $this->signedMoney($walletBalance - $ticketDebt);
        $debtBalance = $this->signedMoney(-1 * $ticketDebt);
        $ticketFee = $this->money($ticketFee);
        $requiredTopup = max(0.0, $ticketFee - $displayRemainingCapacity);
        $isDebtLocked = (bool) ($store->is_debt_locked ?? false);
        $creditStatus = $isDebtLocked ? 'locked' : ($requiredTopup > 0 ? 'insufficient' : 'ok');

        return [
            'order_id' => $order ? (string) $order->id : null,
            'store_id' => (string) $store->id,
            'ticket_fee_vnd' => $ticketFee,
            'remaining_capacity' => $this->money($remainingCapacity),
            'display_remaining_capacity' => $displayRemainingCapacity,
            'capacity_deficit' => $this->money(abs(min(0.0, $displayRemainingCapacity))),
            'required_topup' => $this->money($requiredTopup),
            'credit_limit' => $creditLimit,
            'ticket_debt' => $ticketDebt,
            'debt_balance' => $debtBalance,
            'wallet_balance' => $walletBalance,
            'account_balance' => $accountBalance,
            'credit_version' => (int) ($store->credit_version ?? 0),
            'credit_status' => $creditStatus,
            'credit_checked_at' => now()->toIso8601String(),
            'is_debt_locked' => $isDebtLocked,
        ];
    }

    public function insufficientCreditDetails(Order $order, Store $store, array $snapshot): array
    {
        $debtLockedAt = $store->debt_locked_at ?? null;
        $details = [
            'order_id' => (string) $order->id,
            'store_id' => (string) $store->id,
            'ticket_fee_vnd' => $this->money($snapshot['ticket_fee_vnd'] ?? $order->ticket_fee_vnd ?? 0),
            'remaining_capacity' => $this->money($snapshot['remaining_capacity'] ?? 0),
            'display_remaining_capacity' => $this->signedMoney($snapshot['display_remaining_capacity'] ?? $snapshot['remaining_capacity'] ?? 0),
            'capacity_deficit' => $this->money($snapshot['capacity_deficit'] ?? 0),
            'required_topup' => $this->money($snapshot['required_topup'] ?? $order->required_topup ?? 0),
            'credit_limit' => $this->money($snapshot['credit_limit'] ?? $store->credit_limit ?? self::DEFAULT_CREDIT_LIMIT),
            'ticket_debt' => $this->money($snapshot['ticket_debt'] ?? $store->ticket_debt ?? 0),
            'debt_balance' => $this->signedMoney($snapshot['debt_balance'] ?? -1 * $this->money($store->ticket_debt ?? 0)),
            'wallet_balance' => $this->money($snapshot['wallet_balance'] ?? $store->wallet_balance ?? 0),
            'account_balance' => $this->signedMoney($snapshot['account_balance'] ?? ($this->money($store->wallet_balance ?? 0) - $this->money($store->ticket_debt ?? 0))),
            'credit_status' => (string) ($snapshot['credit_status'] ?? 'insufficient'),
            'is_debt_locked' => (bool) ($snapshot['is_debt_locked'] ?? $store->is_debt_locked ?? false),
            'debt_locked_at' => $debtLockedAt instanceof Carbon
                ? $debtLockedAt->toIso8601String()
                : ($debtLockedAt ? (string) $debtLockedAt : null),
            'debt_lock_reason' => $store->debt_lock_reason ?? null,
            'credit_checked_at' => $order->credit_checked_at instanceof Carbon
                ? $order->credit_checked_at->toIso8601String()
                : ($snapshot['credit_checked_at'] ?? now()->toIso8601String()),
        ];

        $depositInstruction = $this->buildDepositInstruction($order, $store, $snapshot);
        if ($depositInstruction !== null) {
            $details['deposit'] = $depositInstruction;
        }

        return $details;
    }

    public function calculateOrderTicketFee(Order $order): float
    {
        return $this->money($this->calculateOrderTicketSnapshot($order)['ticket_fee_vnd'] ?? 0);
    }

    public function calculateOrderTicketSnapshot(Order $order): array
    {
        $orderId = (string) $order->id;
        $snapshots = $this->calculateOrderTicketSnapshotsForOrderIds([$orderId]);

        return $snapshots[$orderId] ?? $this->emptyTicketSnapshot();
    }

    private function calculateOrderTicketSnapshotsForOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn ($orderId): string => trim((string) $orderId), $orderIds),
            static fn (string $orderId): bool => $orderId !== ''
        )));

        $snapshots = [];
        $emptySnapshot = $this->emptyTicketSnapshot();
        foreach ($orderIds as $orderId) {
            $snapshots[$orderId] = $emptySnapshot;
        }

        if (
            empty($orderIds)
            || ! Schema::hasTable('order_items')
            || ! Schema::hasColumn('order_items', 'order_id')
        ) {
            return $snapshots;
        }

        $query = DB::table('order_items as oi')->whereIn('oi.order_id', $orderIds);
        $hasProducts = Schema::hasTable('products')
            && Schema::hasColumn('order_items', 'product_id')
            && Schema::hasColumn('products', 'id');
        $hasVariants = Schema::hasTable('product_variants')
            && Schema::hasColumn('order_items', 'variant_id')
            && Schema::hasColumn('product_variants', 'id');

        if ($hasProducts) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }
        if ($hasVariants) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        $selects = [
            'oi.order_id as order_id',
            'COALESCE(oi.quantity, 0) as quantity',
        ];

        $unitPriceCandidates = [];
        foreach (['unit_price', 'price'] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                $unitPriceCandidates[] = 'oi.' . $column;
            }
        }
        $selects[] = (empty($unitPriceCandidates)
            ? '0'
            : 'COALESCE(' . implode(', ', $unitPriceCandidates) . ', 0)'
        ) . ' as unit_price';

        if (Schema::hasColumn('order_items', 'ticket_reward_quantity')) {
            $selects[] = 'COALESCE(oi.ticket_reward_quantity, 0) as ticket_quantity_snapshot';
        } else {
            $selects[] = '0 as ticket_quantity_snapshot';
        }

        $rewardCandidates = [];
        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'pv.ticket_reward_quantity';
        }
        if ($hasProducts && Schema::hasColumn('products', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'p.ticket_reward_quantity';
        }
        $selects[] = (empty($rewardCandidates) ? '0' : 'COALESCE(' . implode(', ', $rewardCandidates) . ', 0)')
            . ' as ticket_reward_per_unit';

        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code') && $hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $selects[] = "COALESCE(NULLIF(pv.ticket_type_code, ''), NULLIF(p.ticket_type_code, ''), '') as ticket_type_code";
        } elseif ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code')) {
            $selects[] = "COALESCE(NULLIF(pv.ticket_type_code, ''), '') as ticket_type_code";
        } elseif ($hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $selects[] = "COALESCE(NULLIF(p.ticket_type_code, ''), '') as ticket_type_code";
        } else {
            $selects[] = "'' as ticket_type_code";
        }

        $rowsByOrder = $query
            ->selectRaw(implode(', ', $selects))
            ->get()
            ->groupBy(static fn ($row): string => (string) ($row->order_id ?? ''));
        $pricingContext = $this->buildTicketPricingContext();

        foreach ($orderIds as $orderId) {
            $rows = $rowsByOrder->get($orderId, collect());
            $snapshots[$orderId] = $this->buildTicketSnapshotFromRows($rows, $pricingContext);
        }

        return $snapshots;
    }

    private function emptyTicketSnapshot(): array
    {
        return [
            'ticket_fee_vnd' => 0.0,
            'ticket_quantity' => 0,
            'ticket_type_code' => null,
            'ticket_type_name' => null,
            'ticket_calc_type' => $this->ticketPricingService->getConfig()['calc_type'] ?? null,
            'ticket_value_snapshot' => null,
            'breakdown' => [],
        ];
    }

    private function buildTicketPricingContext(): array
    {
        $ticketTypes = collect($this->ticketPricingService->getTicketTypes())
            ->mapWithKeys(function (array $type): array {
                $code = strtoupper(trim((string) ($type['code'] ?? '')));

                return $code === '' ? [] : [$code => [
                    'code' => $code,
                    'name' => (string) ($type['name'] ?? $code),
                    'price' => (float) ($type['price'] ?? 0),
                ]];
            })
            ->all();
        $defaultTicketTypeCode = strtoupper(trim($this->ticketPricingService->getDefaultTicketTypeCode()));

        return [
            'ticket_types' => $ticketTypes,
            'default_ticket_type_code' => $defaultTicketTypeCode,
            'default_ticket_type' => $ticketTypes[$defaultTicketTypeCode] ?? null,
            'config' => $this->ticketPricingService->getConfig(),
        ];
    }

    private function buildTicketSnapshotFromRows(iterable $rows, array $pricingContext): array
    {
        $ticketTypes = $pricingContext['ticket_types'] ?? [];
        $defaultTicketTypeCode = (string) ($pricingContext['default_ticket_type_code'] ?? '');
        $defaultTicketType = $pricingContext['default_ticket_type'] ?? null;
        $config = $pricingContext['config'] ?? $this->ticketPricingService->getConfig();
        $total = 0.0;
        $totalTickets = 0;
        $breakdown = [];

        foreach ($rows as $row) {
            $snapshotTickets = max(0, (int) ($row->ticket_quantity_snapshot ?? 0));
            $tickets = $snapshotTickets > 0
                ? $snapshotTickets
                : max(0, (int) ($row->quantity ?? 0)) * max(0, (int) ($row->ticket_reward_per_unit ?? 0));
            if ($tickets < 1) {
                continue;
            }

            $unitPrice = $this->money($row->unit_price ?? 0);
            $ticketTypeCode = strtoupper(trim((string) ($row->ticket_type_code ?? '')));
            $effectiveTicketTypeCode = $ticketTypeCode !== '' ? $ticketTypeCode : $defaultTicketTypeCode;
            $resolvedTicketType = $ticketTypes[$effectiveTicketTypeCode] ?? $defaultTicketType;
            $resolvedTicketTypeCode = strtoupper(trim((string) ($resolvedTicketType['code'] ?? $effectiveTicketTypeCode)));
            $resolvedTicketTypeName = (string) ($resolvedTicketType['name'] ?? ($resolvedTicketTypeCode ?: 'Ticket'));
            $perTicketValue = $resolvedTicketType !== null
                ? (float) ($resolvedTicketType['price'] ?? 0)
                : (float) $this->ticketPricingService->calculatePerTicketValue($unitPrice);

            $lineTotal = $tickets * max(0.0, $perTicketValue);
            $total += $lineTotal;
            $totalTickets += $tickets;

            $breakdownKey = $resolvedTicketTypeCode !== '' ? $resolvedTicketTypeCode : 'UNSPECIFIED';
            if (! isset($breakdown[$breakdownKey])) {
                $breakdown[$breakdownKey] = [
                    'ticket_type_code' => $resolvedTicketTypeCode !== '' ? $resolvedTicketTypeCode : null,
                    'ticket_type_name' => $resolvedTicketTypeName,
                    'ticket_quantity' => 0,
                    'ticket_value_snapshot' => round(max(0.0, $perTicketValue), 4),
                    'ticket_fee_vnd' => 0.0,
                ];
            }

            $breakdown[$breakdownKey]['ticket_quantity'] += $tickets;
            $breakdown[$breakdownKey]['ticket_fee_vnd'] = $this->money(
                $breakdown[$breakdownKey]['ticket_fee_vnd'] + $lineTotal
            );
        }

        $breakdownRows = array_values($breakdown);
        $singleType = count($breakdownRows) === 1 ? $breakdownRows[0] : null;
        $ticketValueSnapshot = $singleType !== null
            ? (float) ($singleType['ticket_value_snapshot'] ?? 0)
            : ($totalTickets > 0 ? round($total / $totalTickets, 4) : null);

        return [
            'ticket_fee_vnd' => $this->money($total),
            'ticket_quantity' => $totalTickets,
            'ticket_type_code' => $singleType['ticket_type_code'] ?? ($totalTickets > 0 ? 'MIXED' : null),
            'ticket_type_name' => $singleType['ticket_type_name'] ?? ($totalTickets > 0 ? 'Multiple ticket types' : null),
            'ticket_calc_type' => $config['calc_type'] ?? null,
            'ticket_value_snapshot' => $ticketValueSnapshot,
            'breakdown' => $breakdownRows,
        ];
    }

    public function acceptedLedgerExists(string $orderId): bool
    {
        if (! $this->ledgerSchemaReady()) {
            return false;
        }

        return DB::table('store_ticket_transactions')
            ->where('idempotency_key', $this->acceptedIdempotencyKey($orderId))
            ->exists();
    }

    public function acceptOrder(Order $order, Store $store): array
    {
        if (! $this->creditSchemaReady() || ! $this->ledgerSchemaReady()) {
            throw new RuntimeException('Store credit schema is not ready.');
        }

        $orderId = (string) $order->id;
        $existingLedger = $this->findLedgerByIdempotencyKey($this->acceptedIdempotencyKey($orderId));
        if ($existingLedger !== null) {
            return [
                'idempotent' => true,
                'ticket_fee_vnd' => $this->money($existingLedger->amount_vnd ?? $order->ticket_fee_vnd ?? 0),
                'wallet_used' => abs($this->signedMoney($existingLedger->wallet_delta ?? 0)),
                'debt_increase' => max(0.0, $this->money($existingLedger->debt_delta ?? 0)),
            ];
        }

        $ticketSnapshot = $this->calculateOrderTicketSnapshot($order);
        $ticketFee = $this->money($order->ticket_fee_vnd ?? 0);
        if ($ticketFee <= 0) {
            $ticketFee = $this->money($ticketSnapshot['ticket_fee_vnd'] ?? 0);
        }

        $walletBalance = $this->money($store->wallet_balance ?? 0);
        $walletUsed = min($walletBalance, $ticketFee);
        $debtIncrease = $this->money($ticketFee - $walletUsed);
        $walletAfter = $this->money($walletBalance - $walletUsed);
        $debtAfter = $this->money(($store->ticket_debt ?? 0) + $debtIncrease);
        $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

        Store::query()
            ->whereKey((string) $store->id)
            ->update([
                'wallet_balance' => $walletAfter,
                'ticket_debt' => $debtAfter,
                'credit_version' => $nextVersion,
                'updated_at' => now(),
            ]);

        $store->forceFill([
            'wallet_balance' => $walletAfter,
            'ticket_debt' => $debtAfter,
            'credit_version' => $nextVersion,
        ]);

        $this->insertLedger([
            'order_id' => $orderId,
            'store_id' => (string) $store->id,
            'event_type' => 'ORDER_ACCEPTED',
            'amount_vnd' => $ticketFee,
            'wallet_delta' => -1 * $walletUsed,
            'debt_delta' => $debtIncrease,
            'wallet_balance_after' => $walletAfter,
            'ticket_debt_after' => $debtAfter,
            'idempotency_key' => $this->acceptedIdempotencyKey($orderId),
            'source_type' => 'ORDER_ACCEPTED',
            'source_id' => $orderId,
            'ticket_calc_type' => $ticketSnapshot['ticket_calc_type'] ?? null,
            'ticket_value_snapshot' => $ticketSnapshot['ticket_value_snapshot'] ?? null,
            'ticket_type_code' => $ticketSnapshot['ticket_type_code'] ?? null,
            'ticket_type_name' => $ticketSnapshot['ticket_type_name'] ?? null,
            'ticket_quantity' => $ticketSnapshot['ticket_quantity'] ?? 0,
            'metadata' => [
                'order_code' => $order->order_code ?? null,
                'payment_method' => strtoupper(trim((string) ($order->payment_method ?? ''))),
                'wallet_used' => $walletUsed,
                'debt_increase' => $debtIncrease,
                'credit_version' => $nextVersion,
                'ticket_snapshot' => $ticketSnapshot,
            ],
        ]);

        $this->broadcastStoreWalletUpdated((string) $store->id, 'order_accepted', [
            'event_type' => 'ORDER_ACCEPTED',
            'source_type' => 'ORDER_ACCEPTED',
            'source_id' => $orderId,
            'amount_vnd' => $ticketFee,
            'wallet_delta' => -1 * $walletUsed,
            'debt_delta' => $debtIncrease,
            'wallet_balance' => $walletAfter,
            'ticket_debt' => $debtAfter,
            'account_balance' => $this->signedMoney($walletAfter - $debtAfter),
            'credit_version' => $nextVersion,
            'is_debt_locked' => (bool) ($store->is_debt_locked ?? false),
        ]);

        return [
            'idempotent' => false,
            'ticket_fee_vnd' => $ticketFee,
            'wallet_used' => $walletUsed,
            'debt_increase' => $debtIncrease,
        ];
    }

    public function reverseAcceptedOrder(Order $order, string $reason = 'order_cancelled'): array
    {
        if (! $this->ledgerSchemaReady() || ! $order->store_id) {
            return ['reversed' => false, 'reason' => 'ledger_unavailable'];
        }

        $orderId = (string) $order->id;
        $accepted = $this->findLedgerByIdempotencyKey($this->acceptedIdempotencyKey($orderId));
        if ($accepted === null) {
            return ['reversed' => false, 'reason' => 'accepted_ledger_missing'];
        }

        $reversalKey = 'order:' . $orderId . ':cancelled_reversal';
        if ($this->findLedgerByIdempotencyKey($reversalKey) !== null) {
            return ['reversed' => false, 'idempotent' => true];
        }

        $store = Store::query()->whereKey((string) $order->store_id)->lockForUpdate()->first();
        if (! $store) {
            return ['reversed' => false, 'reason' => 'store_missing'];
        }

        $wasDebtLocked = (bool) ($store->is_debt_locked ?? false);
        $walletRestore = abs($this->signedMoney($accepted->wallet_delta ?? 0));
        $debtDecrease = max(0.0, $this->signedMoney($accepted->debt_delta ?? 0));
        $walletAfter = $this->money(($store->wallet_balance ?? 0) + $walletRestore);
        $debtAfter = max(0.0, $this->money(($store->ticket_debt ?? 0) - $debtDecrease));
        $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

        $payload = [
            'wallet_balance' => $walletAfter,
            'ticket_debt' => $debtAfter,
            'credit_version' => $nextVersion,
            'updated_at' => now(),
        ];

        if ($debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
            $payload['is_debt_locked'] = false;
            $payload['debt_locked_at'] = null;
            $payload['debt_lock_reason'] = null;
        }

        Store::query()->whereKey((string) $store->id)->update($payload);

        if ($wasDebtLocked && $debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
            $this->broadcastStoreCreditVisibilityChanged((string) $store->id, 'store_debt_unlocked');
        }

        $this->insertLedger([
            'order_id' => $orderId,
            'store_id' => (string) $store->id,
            'event_type' => 'ORDER_CANCELLED_REVERSAL',
            'amount_vnd' => $this->money($accepted->amount_vnd ?? 0),
            'wallet_delta' => $walletRestore,
            'debt_delta' => -1 * $debtDecrease,
            'wallet_balance_after' => $walletAfter,
            'ticket_debt_after' => $debtAfter,
            'idempotency_key' => $reversalKey,
            'reversal_of_transaction_id' => $accepted->transaction_id ?? null,
            'source_type' => 'ORDER_CANCELLED_REVERSAL',
            'source_id' => $orderId,
            'metadata' => [
                'reason' => $reason,
                'accepted_transaction_id' => $accepted->transaction_id ?? null,
                'credit_version' => $nextVersion,
            ],
        ]);

        $this->broadcastStoreWalletUpdated((string) $store->id, 'order_cancelled_reversal', [
            'event_type' => 'ORDER_CANCELLED_REVERSAL',
            'source_type' => 'ORDER_CANCELLED_REVERSAL',
            'source_id' => $orderId,
            'amount_vnd' => $this->money($accepted->amount_vnd ?? 0),
            'wallet_delta' => $walletRestore,
            'debt_delta' => -1 * $debtDecrease,
            'wallet_balance' => $walletAfter,
            'ticket_debt' => $debtAfter,
            'account_balance' => $this->signedMoney($walletAfter - $debtAfter),
            'credit_version' => $nextVersion,
            'is_debt_locked' => $debtAfter > 0 && (bool) ($store->is_debt_locked ?? false),
        ]);

        return [
            'reversed' => true,
            'wallet_restored' => $walletRestore,
            'debt_decreased' => $debtDecrease,
        ];
    }

    public function settlePrepaidDeliveredOrder(Order $order): array
    {
        if (! $this->ledgerSchemaReady() || ! $order->store_id || ! $this->isOnlinePaidOrder($order)) {
            return ['settled' => false, 'reason' => 'not_prepaid'];
        }

        $orderId = (string) $order->id;
        if ($this->hasSourceLedger('ORDER_SETTLEMENT', $orderId)) {
            return ['settled' => false, 'idempotent' => true];
        }

        $ticketFee = $this->money($order->ticket_fee_vnd ?? 0);
        if ($ticketFee <= 0) {
            $ticketFee = $this->calculateOrderTicketFee($order);
        }

        $finalAmount = $this->money($order->final_amount ?? $order->total_amount ?? $order->total ?? 0);
        $acceptedLedger = $this->findLedgerByIdempotencyKey($this->acceptedIdempotencyKey($orderId));
        $settlementAmount = $acceptedLedger !== null
            ? $finalAmount
            : max(0.0, $this->money($finalAmount - $ticketFee));
        if ($settlementAmount <= 0) {
            return ['settled' => false, 'reason' => 'empty_settlement'];
        }

        $store = Store::query()->whereKey((string) $order->store_id)->lockForUpdate()->first();
        if (! $store) {
            return ['settled' => false, 'reason' => 'store_missing'];
        }

        $wasDebtLocked = (bool) ($store->is_debt_locked ?? false);
        $currentDebt = $this->money($store->ticket_debt ?? 0);
        $debtRepayment = min($currentDebt, $settlementAmount);
        $walletCredit = $this->money($settlementAmount - $debtRepayment);
        $debtAfter = max(0.0, $this->money($currentDebt - $debtRepayment));
        $walletAfter = $this->money(($store->wallet_balance ?? 0) + $walletCredit);
        $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

        $payload = [
            'ticket_debt' => $debtAfter,
            'wallet_balance' => $walletAfter,
            'credit_version' => $nextVersion,
            'updated_at' => now(),
        ];

        if ($debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
            $payload['is_debt_locked'] = false;
            $payload['debt_locked_at'] = null;
            $payload['debt_lock_reason'] = null;
        }

        Store::query()->whereKey((string) $store->id)->update($payload);

        if ($wasDebtLocked && $debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
            $this->broadcastStoreCreditVisibilityChanged((string) $store->id, 'store_debt_unlocked');
        }

        if ($debtRepayment > 0) {
            $this->insertLedger([
                'order_id' => $orderId,
                'store_id' => (string) $store->id,
                'event_type' => 'DEBT_REPAYMENT',
                'amount_vnd' => $debtRepayment,
                'wallet_delta' => 0,
                'debt_delta' => -1 * $debtRepayment,
                'wallet_balance_after' => $this->money($store->wallet_balance ?? 0),
                'ticket_debt_after' => $debtAfter,
                'idempotency_key' => 'order:' . $orderId . ':debt_repayment',
                'source_type' => 'ORDER_SETTLEMENT',
                'source_id' => $orderId,
                'metadata' => [
                    'settlement_amount' => $settlementAmount,
                    'ticket_fee_vnd' => $ticketFee,
                    'accepted_fee_already_charged' => $acceptedLedger !== null,
                ],
            ]);

            $this->recordStoreLiabilityDebtDecrease(
                (string) $store->id,
                $debtRepayment,
                'future_revenue_offset',
                'order_settlement',
                $orderId,
                'order:' . $orderId . ':store_liability_decrease',
                [
                    'order_id' => $orderId,
                    'settlement_amount' => $settlementAmount,
                ]
            );
        }

        if ($walletCredit > 0) {
            $this->insertLedger([
                'order_id' => $orderId,
                'store_id' => (string) $store->id,
                'event_type' => 'REVENUE_CREDIT',
                'amount_vnd' => $walletCredit,
                'wallet_delta' => $walletCredit,
                'debt_delta' => 0,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $debtAfter,
                'idempotency_key' => 'order:' . $orderId . ':revenue_credit',
                'source_type' => 'ORDER_SETTLEMENT',
                'source_id' => $orderId,
                'metadata' => [
                    'settlement_amount' => $settlementAmount,
                    'ticket_fee_vnd' => $ticketFee,
                    'accepted_fee_already_charged' => $acceptedLedger !== null,
                ],
            ]);
        }

        $this->broadcastStoreWalletUpdated((string) $store->id, 'order_settlement', [
            'event_type' => 'ORDER_SETTLEMENT',
            'source_type' => 'ORDER_SETTLEMENT',
            'source_id' => $orderId,
            'amount_vnd' => $settlementAmount,
            'wallet_delta' => $walletCredit,
            'debt_delta' => -1 * $debtRepayment,
            'wallet_balance' => $walletAfter,
            'ticket_debt' => $debtAfter,
            'account_balance' => $this->signedMoney($walletAfter - $debtAfter),
            'credit_version' => $nextVersion,
            'is_debt_locked' => $debtAfter > 0 && (bool) ($store->is_debt_locked ?? false),
        ]);

        return [
            'settled' => true,
            'settlement_amount' => $settlementAmount,
            'debt_repayment' => $debtRepayment,
            'wallet_credit' => $walletCredit,
        ];
    }

    public function recordWalletDeposit(string $storeId, float $amount, string $reference, array $metadata = []): array
    {
        return DB::transaction(function () use ($storeId, $amount, $reference, $metadata): array {
            if (! $this->ledgerSchemaReady()) {
                throw new RuntimeException('Store credit ledger schema is not ready.');
            }

            $reference = trim($reference);
            if ($reference === '') {
                throw new RuntimeException('Deposit reference is required.');
            }

            $amount = $this->money($amount);
            if ($amount <= 0) {
                throw new RuntimeException('Deposit amount must be greater than zero.');
            }

            if ($this->hasSourceLedger('STORE_DEPOSIT', $reference)) {
                return ['applied' => false, 'idempotent' => true, 'refreshed_pending_orders' => 0];
            }

            $store = Store::query()->whereKey($storeId)->lockForUpdate()->first();
            if (! $store) {
                throw new RuntimeException('Store not found.');
            }

            $wasDebtLocked = (bool) ($store->is_debt_locked ?? false);
            $currentDebt = $this->money($store->ticket_debt ?? 0);
            $debtRepayment = min($currentDebt, $amount);
            $walletDeposit = $this->money($amount - $debtRepayment);
            $debtAfter = max(0.0, $this->money($currentDebt - $debtRepayment));
            $walletAfter = $this->money(($store->wallet_balance ?? 0) + $walletDeposit);
            $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

            $payload = [
                'ticket_debt' => $debtAfter,
                'wallet_balance' => $walletAfter,
                'credit_version' => $nextVersion,
                'updated_at' => now(),
            ];

            if ($debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
                $payload['is_debt_locked'] = false;
                $payload['debt_locked_at'] = null;
                $payload['debt_lock_reason'] = null;
            }

            Store::query()->whereKey((string) $store->id)->update($payload);
            $store->forceFill($payload);

            if ($wasDebtLocked && $debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
                $this->broadcastStoreCreditVisibilityChanged((string) $store->id, 'store_debt_unlocked');
            }

            if ($amount > 0) {
                $this->insertLedger([
                    'store_id' => (string) $store->id,
                    'event_type' => 'WALLET_DEPOSIT',
                    'amount_vnd' => $amount,
                    'wallet_delta' => $amount,
                    'debt_delta' => 0,
                    'wallet_balance_after' => $this->money($walletAfter - $walletDeposit + $amount),
                    'ticket_debt_after' => $currentDebt,
                    'idempotency_key' => 'deposit:' . $reference . ':wallet_deposit',
                    'source_type' => 'STORE_DEPOSIT',
                    'source_id' => $reference,
                    'metadata' => $metadata,
                ]);
            }

            if ($debtRepayment > 0) {
                $this->insertLedger([
                    'store_id' => (string) $store->id,
                    'event_type' => 'DEBT_REPAYMENT',
                    'amount_vnd' => $debtRepayment,
                    'wallet_delta' => -1 * $debtRepayment,
                    'debt_delta' => -1 * $debtRepayment,
                    'wallet_balance_after' => $walletAfter,
                    'ticket_debt_after' => $debtAfter,
                    'idempotency_key' => 'deposit:' . $reference . ':debt_repayment',
                    'source_type' => 'STORE_DEPOSIT',
                    'source_id' => $reference,
                    'metadata' => $metadata,
                ]);

                $this->recordStoreLiabilityDebtDecrease(
                    (string) $store->id,
                    $debtRepayment,
                    'manual_collection',
                    'manual_adjustment',
                    $reference,
                    'deposit:' . $reference . ':store_liability_decrease',
                    $metadata
                );
            }

            $refreshedPendingOrders = $this->refreshPendingCreditSnapshotsForStore($store);

            $this->broadcastStoreWalletUpdated((string) $store->id, 'wallet_deposit', [
                'event_type' => 'WALLET_DEPOSIT',
                'source_type' => 'STORE_DEPOSIT',
                'source_id' => $reference,
                'amount_vnd' => $amount,
                'wallet_delta' => $walletDeposit,
                'debt_delta' => -1 * $debtRepayment,
                'wallet_balance' => $walletAfter,
                'ticket_debt' => $debtAfter,
                'account_balance' => $this->signedMoney($walletAfter - $debtAfter),
                'credit_version' => $nextVersion,
                'is_debt_locked' => false,
                'debt_locked_at' => null,
                'debt_lock_reason' => null,
                'refreshed_pending_orders' => $refreshedPendingOrders,
            ]);

            return [
                'applied' => true,
                'debt_repayment' => $debtRepayment,
                'wallet_deposit' => $walletDeposit,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $debtAfter,
                'refreshed_pending_orders' => $refreshedPendingOrders,
            ];
        });
    }

    public function recordAdminManualTopup(string $storeId, float $amount, string $note, ?int $adminId = null): array
    {
        return DB::transaction(function () use ($storeId, $amount, $note, $adminId): array {
            if (! $this->ledgerSchemaReady()) {
                throw new RuntimeException('Store credit ledger schema is not ready.');
            }

            $amount = $this->money($amount);
            if ($amount <= 0) {
                throw new RuntimeException('Số tiền nạp phải lớn hơn 0.');
            }

            $store = Store::query()->whereKey($storeId)->lockForUpdate()->first();
            if (! $store) {
                throw new RuntimeException('Cửa hàng không tồn tại.');
            }

            $oldWalletBalance = (float)($store->wallet_balance ?? 0);
            $wasDebtLocked = (bool) ($store->is_debt_locked ?? false);
            $currentDebt = $this->money($store->ticket_debt ?? 0);
            $creditLimit = $this->money($store->credit_limit ?? 0);

            $debtRepayment = min($currentDebt, $amount);
            $walletDeposit = $this->money($amount - $debtRepayment);
            $debtAfter = max(0.0, $this->money($currentDebt - $debtRepayment));
            $walletAfter = $this->money($oldWalletBalance + $walletDeposit);
            $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

            $payload = [
                'ticket_debt' => $debtAfter,
                'wallet_balance' => $walletAfter,
                'credit_version' => $nextVersion,
                'updated_at' => now(),
            ];

            if ($debtAfter < $creditLimit && Schema::hasColumn('stores', 'is_debt_locked')) {
                $payload['is_debt_locked'] = false;
                $payload['debt_locked_at'] = null;
                $payload['debt_lock_reason'] = null;
            }

            Store::query()->whereKey((string) $store->id)->update($payload);
            $store->forceFill($payload);

            if ($wasDebtLocked && $debtAfter < $creditLimit && Schema::hasColumn('stores', 'is_debt_locked')) {
                $this->broadcastStoreCreditVisibilityChanged((string) $store->id, 'store_debt_unlocked');
            }

            $reference = 'MANUAL_' . time() . '_' . Str::upper(Str::random(6));
            $metadata = ['reason' => $note, 'admin_id' => $adminId];

            if (Schema::hasTable('store_wallet_transactions')) {
                DB::table('store_wallet_transactions')->insert([
                    'id' => (string) Str::uuid(),
                    'store_id' => $store->id,
                    'type' => 'topup',
                    'amount' => $amount,
                    'wallet_balance_before' => $oldWalletBalance,
                    'wallet_balance_after' => $walletAfter,
                    'note' => $note,
                    'reference_code' => $reference,
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->insertLedger([
                'store_id' => (string) $store->id,
                'event_type' => 'ADMIN_MANUAL_TOPUP',
                'amount_vnd' => $amount,
                'wallet_delta' => $walletDeposit,
                'debt_delta' => -1 * $debtRepayment,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $debtAfter,
                'idempotency_key' => 'manual_topup:' . $reference,
                'source_type' => 'SYSTEM_ADMIN',
                'source_id' => $reference,
                'metadata' => $metadata,
            ]);

            if ($debtRepayment > 0) {
                $this->recordStoreLiabilityDebtDecrease(
                    (string) $store->id,
                    $debtRepayment,
                    'manual_collection',
                    'manual_adjustment',
                    $reference,
                    'manual_topup:' . $reference . ':store_liability_decrease',
                    $metadata
                );
            }

            $refreshedPendingOrders = $this->refreshPendingCreditSnapshotsForStore($store);

            $this->broadcastStoreWalletUpdated((string) $store->id, 'admin_manual_topup', [
                'event_type' => 'ADMIN_MANUAL_TOPUP',
                'source_type' => 'SYSTEM_ADMIN',
                'source_id' => $reference,
                'amount_vnd' => $amount,
                'wallet_delta' => $walletDeposit,
                'debt_delta' => -1 * $debtRepayment,
                'wallet_balance' => $walletAfter,
                'ticket_debt' => $debtAfter,
                'account_balance' => $this->signedMoney($walletAfter - $debtAfter),
                'credit_version' => $nextVersion,
                'is_debt_locked' => $debtAfter >= $creditLimit,
                'debt_locked_at' => $debtAfter >= $creditLimit ? $store->debt_locked_at : null,
                'debt_lock_reason' => $debtAfter >= $creditLimit ? $store->debt_lock_reason : null,
                'refreshed_pending_orders' => $refreshedPendingOrders,
            ]);

            return [
                'applied' => true,
                'debt_repayment' => $debtRepayment,
                'wallet_deposit' => $walletDeposit,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $debtAfter,
                'refreshed_pending_orders' => $refreshedPendingOrders,
            ];
        });
    }


    public function deductAffiliateCommission(Store $store, float $amount, FinancialReconciliation $reconciliation): array
    {
        return DB::transaction(function () use ($store, $amount, $reconciliation): array {
            $amount = $this->money($amount);
            if ($amount <= 0) {
                return ['deducted' => false, 'reason' => 'empty_amount'];
            }

            if (! $this->ledgerSchemaReady()) {
                throw new RuntimeException('Store credit ledger schema is not ready.');
            }

            $reconciliationId = trim((string) $reconciliation->id);
            if ($reconciliationId === '') {
                throw new RuntimeException('Financial reconciliation id is required.');
            }

            $idempotencyKey = 'financial-reconciliation:' . $reconciliationId . ':affiliate-commission';
            $existingLedger = $this->findLedgerByIdempotencyKey($idempotencyKey);
            if ($existingLedger !== null) {
                return [
                    'deducted' => true,
                    'idempotent' => true,
                    'wallet_delta' => $this->signedMoney($existingLedger->wallet_delta ?? 0),
                    'wallet_balance_after' => $this->signedMoney($existingLedger->wallet_balance_after ?? 0),
                ];
            }

            $lockedStore = Store::query()->whereKey((string) $store->id)->lockForUpdate()->first();
            if (! $lockedStore) {
                throw new RuntimeException('Store not found.');
            }

            $walletBalance = $this->signedMoney($lockedStore->wallet_balance ?? 0);
            $ticketDebt = $this->signedMoney($lockedStore->ticket_debt ?? 0);
            $walletAfter = $this->signedMoney($walletBalance - $amount);
            $nextVersion = ((int) ($lockedStore->credit_version ?? 0)) + 1;

            $lockedStore->forceFill([
                'wallet_balance' => $walletAfter,
                'credit_version' => $nextVersion,
            ])->save();

            $this->insertLedger([
                'store_id' => (string) $lockedStore->id,
                'event_type' => 'AFFILIATE_COMMISSION_DEDUCTION',
                'amount_vnd' => $amount,
                'wallet_delta' => -1 * $amount,
                'debt_delta' => 0,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $ticketDebt,
                'idempotency_key' => $idempotencyKey,
                'source_type' => 'FINANCIAL_RECONCILIATION',
                'source_id' => $reconciliationId,
                'metadata' => [
                    'period' => $reconciliation->period,
                    'period_date' => optional($reconciliation->period_date)?->toDateString(),
                    'credit_version' => $nextVersion,
                ],
            ]);

            $this->broadcastStoreWalletUpdated((string) $lockedStore->id, 'affiliate_commission_deduction', [
                'event_type' => 'AFFILIATE_COMMISSION_DEDUCTION',
                'source_type' => 'FINANCIAL_RECONCILIATION',
                'source_id' => $reconciliationId,
                'amount_vnd' => $amount,
                'wallet_delta' => -1 * $amount,
                'debt_delta' => 0,
                'wallet_balance' => $walletAfter,
                'ticket_debt' => $ticketDebt,
                'account_balance' => $this->signedMoney($walletAfter - $ticketDebt),
                'credit_version' => $nextVersion,
            ]);

            return [
                'deducted' => true,
                'wallet_delta' => -1 * $amount,
                'wallet_balance_after' => $walletAfter,
            ];
        });
    }

    public function deductAffiliateCommissionForCommission(AffiliateCommission $commission, ?float $amount = null): array
    {
        return DB::transaction(function () use ($commission, $amount): array {
            $amount = $this->money($amount ?? $this->affiliateCommissionWalletAmount($commission));
            if ($amount <= 0) {
                return ['deducted' => false, 'reason' => 'empty_amount'];
            }

            if (! $this->affiliateCommissionWalletSchemaReady()) {
                return ['deducted' => false, 'reason' => 'schema_not_ready'];
            }

            $commissionId = (string) $commission->getKey();
            if ($commissionId === '') {
                return ['deducted' => false, 'reason' => 'missing_commission_id'];
            }

            $storeId = $this->resolveAffiliateCommissionStoreId($commission);
            if ($storeId === '') {
                return ['deducted' => false, 'reason' => 'missing_store_id'];
            }

            $idempotencyKey = 'affiliate-commission:' . $commissionId . ':store-deduction';
            $existingLedger = $this->findLedgerByIdempotencyKey($idempotencyKey);
            if ($existingLedger !== null) {
                return [
                    'deducted' => true,
                    'idempotent' => true,
                    'wallet_delta' => $this->signedMoney($existingLedger->wallet_delta ?? 0),
                    'wallet_balance_after' => $this->signedMoney($existingLedger->wallet_balance_after ?? 0),
                ];
            }

            $store = Store::query()->whereKey($storeId)->lockForUpdate()->first();
            if (! $store) {
                return ['deducted' => false, 'reason' => 'store_not_found'];
            }

            $walletBefore = $this->signedMoney($store->wallet_balance ?? 0);
            $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
            $walletAfter = $this->signedMoney($walletBefore - $amount);
            $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

            $store->forceFill([
                'wallet_balance' => $walletAfter,
                'credit_version' => $nextVersion,
            ])->save();

            $this->insertLedger([
                'order_id' => (string) ($commission->order_id ?? ''),
                'store_id' => $storeId,
                'product_id' => $commission->product_id ? (int) $commission->product_id : null,
                'event_type' => 'AFFILIATE_COMMISSION_DEDUCTION',
                'amount_vnd' => $amount,
                'wallet_delta' => -1 * $amount,
                'debt_delta' => 0,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $ticketDebt,
                'idempotency_key' => $idempotencyKey,
                'source_type' => 'AFFILIATE_COMMISSION',
                'source_id' => $commissionId,
                'metadata' => $this->affiliateCommissionLedgerMetadata($commission, [
                    'credit_version' => $nextVersion,
                    'wallet_balance_before' => $walletBefore,
                ]),
            ]);

            $this->broadcastStoreWalletUpdated($storeId, 'affiliate_commission_deduction', [
                'event_type' => 'AFFILIATE_COMMISSION_DEDUCTION',
                'source_type' => 'AFFILIATE_COMMISSION',
                'source_id' => $commissionId,
                'amount_vnd' => $amount,
                'wallet_delta' => -1 * $amount,
                'debt_delta' => 0,
                'wallet_balance' => $walletAfter,
                'ticket_debt' => $ticketDebt,
                'account_balance' => $this->signedMoney($walletAfter - $ticketDebt),
                'credit_version' => $nextVersion,
            ]);

            return [
                'deducted' => true,
                'wallet_delta' => -1 * $amount,
                'wallet_balance_after' => $walletAfter,
            ];
        });
    }

    public function refundAffiliateCommissionForCommission(
        AffiliateCommission $commission,
        ?float $amount = null,
        mixed $actor = null,
        ?OrderReturn $return = null,
        string $reason = 'affiliate_commission_cancelled'
    ): array {
        return DB::transaction(function () use ($commission, $amount, $actor, $return, $reason): array {
            $amount = $this->money($amount ?? $this->affiliateCommissionWalletAmount($commission));
            if ($amount <= 0) {
                return ['refunded' => false, 'reason' => 'empty_amount'];
            }

            if (! $this->affiliateCommissionWalletSchemaReady()) {
                return ['refunded' => false, 'reason' => 'schema_not_ready'];
            }

            $commissionId = (string) $commission->getKey();
            if ($commissionId === '') {
                return ['refunded' => false, 'reason' => 'missing_commission_id'];
            }

            $storeId = $this->resolveAffiliateCommissionStoreId($commission);
            if ($storeId === '') {
                return ['refunded' => false, 'reason' => 'missing_store_id'];
            }

            $idempotencyKey = 'affiliate-commission:' . $commissionId . ':store-refund';
            $existingLedger = $this->findLedgerByIdempotencyKey($idempotencyKey);
            if ($existingLedger !== null) {
                return [
                    'refunded' => true,
                    'idempotent' => true,
                    'wallet_delta' => $this->signedMoney($existingLedger->wallet_delta ?? 0),
                    'wallet_balance_after' => $this->signedMoney($existingLedger->wallet_balance_after ?? 0),
                ];
            }

            $deductionLedger = $this->findAffiliateCommissionStoreDeductionLedger($commission);
            if ($deductionLedger === null) {
                return ['refunded' => false, 'reason' => 'deduction_not_found'];
            }

            $deductedAmount = abs($this->signedMoney($deductionLedger->wallet_delta ?? $deductionLedger->amount_vnd ?? 0));
            $alreadyRefunded = $this->sumAffiliateCommissionStoreRefunds($commissionId);
            $refundableAmount = $this->money(max(0, $deductedAmount - $alreadyRefunded));
            $refundAmount = min($amount, $refundableAmount);

            if ($refundAmount <= 0) {
                return ['refunded' => false, 'reason' => 'already_refunded'];
            }

            $store = Store::query()->whereKey($storeId)->lockForUpdate()->first();
            if (! $store) {
                return ['refunded' => false, 'reason' => 'store_not_found'];
            }

            $walletBefore = $this->signedMoney($store->wallet_balance ?? 0);
            $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
            $walletAfter = $this->signedMoney($walletBefore + $refundAmount);
            $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

            $store->forceFill([
                'wallet_balance' => $walletAfter,
                'credit_version' => $nextVersion,
            ])->save();

            $this->insertLedger([
                'order_id' => (string) ($commission->order_id ?? ''),
                'store_id' => $storeId,
                'product_id' => $commission->product_id ? (int) $commission->product_id : null,
                'event_type' => 'AFFILIATE_COMMISSION_REFUND',
                'amount_vnd' => $refundAmount,
                'wallet_delta' => $refundAmount,
                'debt_delta' => 0,
                'wallet_balance_after' => $walletAfter,
                'ticket_debt_after' => $ticketDebt,
                'idempotency_key' => $idempotencyKey,
                'source_type' => 'AFFILIATE_COMMISSION',
                'source_id' => $commissionId,
                'metadata' => $this->affiliateCommissionLedgerMetadata($commission, [
                    'reason' => $reason,
                    'return_id' => $return ? (string) $return->id : null,
                    'return_code' => $return?->return_code,
                    'actor_id' => $this->actorId($actor),
                    'reversal_of_transaction_id' => $deductionLedger->transaction_id ?? null,
                    'credit_version' => $nextVersion,
                    'wallet_balance_before' => $walletBefore,
                ]),
            ]);

            $this->broadcastStoreWalletUpdated($storeId, 'affiliate_commission_refund', [
                'event_type' => 'AFFILIATE_COMMISSION_REFUND',
                'source_type' => 'AFFILIATE_COMMISSION',
                'source_id' => $commissionId,
                'amount_vnd' => $refundAmount,
                'wallet_delta' => $refundAmount,
                'debt_delta' => 0,
                'wallet_balance' => $walletAfter,
                'ticket_debt' => $ticketDebt,
                'account_balance' => $this->signedMoney($walletAfter - $ticketDebt),
                'credit_version' => $nextVersion,
            ]);

            return [
                'refunded' => true,
                'wallet_delta' => $refundAmount,
                'wallet_balance_after' => $walletAfter,
            ];
        });
    }

    public function refreshPendingCreditSnapshotsForStore(Store|string $store): int
    {
        if (! $this->creditSchemaReady()) {
            return 0;
        }

        $storeModel = $store instanceof Store
            ? $store
            : Store::query()->whereKey($store)->first();
        if (! $storeModel) {
            return 0;
        }

        $orderIds = Order::query()
            ->where('store_id', (string) $storeModel->id)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->where('required_topup', '>', 0)
                    ->orWhereIn('credit_status', ['insufficient', 'locked', 'stale', 'unchecked', 'not_checked']);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($orderId): string => (string) $orderId)
            ->all();

        $count = 0;
        foreach (array_chunk($orderIds, 100) as $chunk) {
            $orders = Order::query()
                ->whereIn('id', $chunk)
                ->get()
                ->keyBy(static fn (Order $order): string => (string) $order->id);
            $ticketSnapshots = $this->calculateOrderTicketSnapshotsForOrderIds($chunk);
            $now = now();

            foreach ($chunk as $orderId) {
                $order = $orders->get($orderId);
                if (! $order instanceof Order) {
                    continue;
                }

                $ticketSnapshot = $ticketSnapshots[$orderId] ?? $this->emptyTicketSnapshot();
                $snapshot = $this->buildCapacitySnapshot(
                    $storeModel,
                    $this->money($ticketSnapshot['ticket_fee_vnd'] ?? 0),
                    $order
                );

                $order->forceFill([
                    'required_topup' => $snapshot['required_topup'],
                    'ticket_fee_vnd' => $snapshot['ticket_fee_vnd'],
                    'credit_checked_at' => $now,
                    'credit_snapshot_version' => $snapshot['credit_version'],
                    'credit_status' => $snapshot['credit_status'],
                ])->save();

                $count++;
            }
        }

        return $count;
    }

    public function shouldLockStoreForCreditTimeout(Order $order, ?Store $store = null): bool
    {
        if (! $this->creditSchemaReady()) {
            return false;
        }

        if ($this->normalizeStatus($order->status) !== 'pending' || ! $order->store_id) {
            return false;
        }

        $storeModel = $store ?? Store::query()->whereKey((string) $order->store_id)->first();
        if (! $storeModel) {
            return false;
        }

        if ($this->isSnapshotFresh($order, $storeModel)) {
            $ticketFee = $this->money($order->ticket_fee_vnd ?? 0);
            if ($ticketFee <= 0) {
                $ticketFee = $this->calculateOrderTicketFee($order);
            }

            $snapshot = $this->buildCapacitySnapshot($storeModel, $ticketFee, $order);
        } else {
            $snapshot = $this->refreshOrderCreditSnapshot($order, $storeModel);
        }

        return $this->shouldLockCreditSnapshotForTimeout($snapshot);
    }

    public function shouldLockCreditSnapshotForTimeout(array $snapshot): bool
    {
        $requiredTopup = $this->money($snapshot['required_topup'] ?? 0);
        if ($requiredTopup <= 0) {
            return false;
        }

        if ((bool) ($snapshot['is_debt_locked'] ?? false)) {
            return true;
        }

        $ticketDebt = $this->money($snapshot['ticket_debt'] ?? 0);
        $walletBalance = $this->money($snapshot['wallet_balance'] ?? 0);
        $accountBalance = $this->signedMoney(
            $snapshot['account_balance'] ?? ($walletBalance - $ticketDebt)
        );

        return $accountBalance < 0 || $ticketDebt > $walletBalance;
    }

    public function lockStoreForCreditTimeout(string $storeId): void
    {
        if (! Schema::hasColumn('stores', 'is_debt_locked')) {
            return;
        }

        $store = Store::query()->whereKey($storeId)->first();
        if (! $store) {
            return;
        }

        $wasLocked = (bool) ($store->is_debt_locked ?? false);
        Store::query()
            ->whereKey($storeId)
            ->update([
                'is_debt_locked' => true,
                'debt_locked_at' => $wasLocked ? ($store->debt_locked_at ?? now()) : now(),
                'debt_lock_reason' => 'credit_capacity_timeout',
                'updated_at' => now(),
            ]);

        if (! $wasLocked) {
            $this->broadcastStoreCreditVisibilityChanged($storeId, 'store_debt_locked');
        }
    }

    public function resolveCreditLevel(float $deliveredCount, float $cancelRate, int $currentLevel): int
    {
        $target = $currentLevel;

        if ($deliveredCount >= 500) {
            $target = 4;
        } elseif ($deliveredCount >= 100) {
            $target = 3;
        } elseif ($deliveredCount >= 20 && $cancelRate < 0.20) {
            $target = 2;
        }

        return max($currentLevel, $target);
    }

    public function creditLimitForLevel(int $level): float
    {
        return match (max(1, $level)) {
            4 => 20000000.0,
            3 => 5000000.0,
            2 => 1000000.0,
            default => self::DEFAULT_CREDIT_LIMIT,
        };
    }

    public function isNearLimit(Store $store): bool
    {
        $limit = max(1.0, $this->money($store->credit_limit ?? self::DEFAULT_CREDIT_LIMIT));
        $walletBalance = $this->money($store->wallet_balance ?? 0);
        if ($walletBalance > 0) {
            return false;
        }

        return $this->money($store->ticket_debt ?? 0) >= ($limit * self::NEAR_LIMIT_RATIO);
    }

    private function acceptedIdempotencyKey(string $orderId): string
    {
        return 'order:' . $orderId . ':accepted';
    }

    private function broadcastStoreCreditVisibilityChanged(string $storeId, string $action): void
    {
        $storeId = trim($storeId);
        if ($storeId === '') {
            return;
        }

        $callback = static function () use ($storeId, $action): void {
            try {
                event(new StoreAdminProductUpdated($storeId, '', $action));
            } catch (\Throwable $e) {
                Log::warning('Unable to broadcast store credit visibility change.', [
                    'store_id' => $storeId,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
    }

    private function broadcastStoreWalletUpdated(string $storeId, string $action, array $payload = []): void
    {
        $storeId = trim($storeId);
        if ($storeId === '') {
            return;
        }

        $payload = array_merge([
            'action' => $action,
        ], $payload);

        $callback = static function () use ($storeId, $payload): void {
            try {
                event(new StoreWalletUpdated($storeId, $payload));
            } catch (\Throwable $e) {
                Log::warning('Unable to broadcast store wallet update.', [
                    'store_id' => $storeId,
                    'action' => $payload['action'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
    }

    private function buildDepositInstruction(Order $order, Store $store, array $snapshot): ?array
    {
        $isDebtLocked = (bool) ($snapshot['is_debt_locked'] ?? $store->is_debt_locked ?? false);
        $amount = $isDebtLocked
            ? $this->money($store->ticket_debt ?? 0)
            : $this->money($snapshot['required_topup'] ?? $order->required_topup ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $referenceCode = $this->buildDepositReferenceCode($order, $store);
        $bankId = trim((string) env('VIETQR_BANK_ID', 'MB'));
        $accountNo = trim((string) env('VIETQR_ACCOUNT_NO', ''));
        $accountName = trim((string) env('VIETQR_ACCOUNT_NAME', ''));

        if (Schema::hasTable('app_settings')) {
            $settings = appSetting::query()->first();
            $bankId = trim((string) ($settings?->vietqr_bank_id ?: $bankId));
            $accountNo = trim((string) ($settings?->vietqr_account_no ?: $accountNo));
            $accountName = trim((string) ($settings?->vietqr_account_name ?: $accountName));
        }

        $qrUrl = null;
        if ($bankId !== '' && $accountNo !== '' && $accountName !== '') {
            $query = http_build_query([
                'amount' => (int) round($amount),
                'addInfo' => $referenceCode,
                'accountName' => $accountName,
                't' => time(),
            ]);
            $qrUrl = "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?{$query}";
        }

        return [
            'amount_vnd' => $amount,
            'reference_code' => $referenceCode,
            'qr_url' => $qrUrl,
            'bank_id' => $bankId !== '' ? $bankId : null,
            'account_no' => $accountNo !== '' ? $accountNo : null,
            'account_name' => $accountName !== '' ? $accountName : null,
        ];
    }

    private function buildDepositReferenceCode(Order $order, Store $store): string
    {
        $storeCode = strtoupper(trim((string) ($store->store_code ?? '')));
        if ($storeCode === '') {
            $storeCode = substr(str_replace('-', '', (string) $store->id), 0, 8);
        }

        $orderCode = strtoupper(trim((string) ($order->order_code ?? '')));
        if ($orderCode === '') {
            $orderCode = substr(str_replace('-', '', (string) $order->id), 0, 8);
        }

        $reference = preg_replace('/[^A-Z0-9 ]+/', '', 'ELUCK TOPUP ' . $storeCode . ' ' . $orderCode) ?? '';

        return trim(substr($reference, 0, 120));
    }

    private function affiliateCommissionWalletAmount(AffiliateCommission $commission): float
    {
        $policySnapshot = is_array($commission->policy_snapshot_json) ? $commission->policy_snapshot_json : [];

        return $this->money(max(
            abs((float) ($commission->payout_amount ?? 0)),
            abs((float) ($commission->approved_amount ?? 0)),
            abs((float) ($commission->pending_amount ?? 0)),
            abs((float) ($policySnapshot['commission_amount'] ?? 0)),
        ));
    }

    private function resolveAffiliateCommissionStoreId(AffiliateCommission $commission): string
    {
        $storeId = trim((string) ($commission->seller_id ?? ''));
        if ($storeId !== '') {
            return $storeId;
        }

        if ($commission->relationLoaded('order') && $commission->order) {
            return trim((string) ($commission->order->store_id ?? ''));
        }

        if (! Schema::hasTable('orders') || trim((string) ($commission->order_id ?? '')) === '') {
            return '';
        }

        return trim((string) DB::table('orders')->where('id', (string) $commission->order_id)->value('store_id'));
    }

    private function affiliateCommissionLedgerMetadata(AffiliateCommission $commission, array $extra = []): array
    {
        $orderCode = null;
        if ($commission->relationLoaded('order') && $commission->order) {
            $orderCode = $commission->order->order_code ?? null;
        } elseif (Schema::hasTable('orders') && trim((string) ($commission->order_id ?? '')) !== '') {
            $orderCode = DB::table('orders')->where('id', (string) $commission->order_id)->value('order_code');
        }

        return array_filter(array_merge([
            'affiliate_commission_id' => (string) $commission->getKey(),
            'affiliate_id' => (int) $commission->affiliate_id,
            'order_id' => (string) ($commission->order_id ?? ''),
            'order_code' => $orderCode,
            'order_item_id' => (string) ($commission->order_item_id ?? ''),
            'product_id' => $commission->product_id ? (int) $commission->product_id : null,
            'source' => 'affiliate_commission',
        ], $extra), static fn ($value): bool => $value !== null && $value !== '');
    }

    private function findAffiliateCommissionStoreDeductionLedger(AffiliateCommission $commission): ?object
    {
        if (! Schema::hasTable('store_ticket_transactions')) {
            return null;
        }

        $commissionId = (string) $commission->getKey();
        $idempotencyKey = 'affiliate-commission:' . $commissionId . ':store-deduction';

        $ledger = DB::table('store_ticket_transactions')
            ->where('event_type', 'AFFILIATE_COMMISSION_DEDUCTION')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($ledger) {
            return $ledger;
        }

        if (
            Schema::hasColumn('store_ticket_transactions', 'source_type')
            && Schema::hasColumn('store_ticket_transactions', 'source_id')
        ) {
            $ledger = DB::table('store_ticket_transactions')
                ->where('event_type', 'AFFILIATE_COMMISSION_DEDUCTION')
                ->where('source_type', 'AFFILIATE_COMMISSION')
                ->where('source_id', $commissionId)
                ->first();

            if ($ledger) {
                return $ledger;
            }
        }

        return $this->findReconciliationAffiliateDeductionLedger($commission);
    }

    private function findReconciliationAffiliateDeductionLedger(AffiliateCommission $commission): ?object
    {
        if (
            ! Schema::hasTable('financial_reconciliations')
            || ! Schema::hasTable('store_ticket_transactions')
            || ! Schema::hasColumn('store_ticket_transactions', 'source_type')
            || ! Schema::hasColumn('store_ticket_transactions', 'source_id')
            || trim((string) ($commission->order_id ?? '')) === ''
        ) {
            return null;
        }

        $storeId = $this->resolveAffiliateCommissionStoreId($commission);
        if ($storeId === '') {
            return null;
        }

        $order = Schema::hasTable('orders')
            ? DB::table('orders')->where('id', (string) $commission->order_id)->first()
            : null;
        if (! $order) {
            return null;
        }

        $orderDate = Carbon::parse($order->delivered_at ?? $order->created_at ?? now())->startOfDay();

        $rows = DB::table('store_ticket_transactions as stt')
            ->join('financial_reconciliations as fr', 'fr.id', '=', 'stt.source_id')
            ->where('stt.store_id', $storeId)
            ->where('stt.event_type', 'AFFILIATE_COMMISSION_DEDUCTION')
            ->where('stt.wallet_delta', '<', 0)
            ->where('stt.source_type', 'FINANCIAL_RECONCILIATION')
            ->where('fr.store_id', $storeId)
            ->orderByDesc('stt.timestamp')
            ->orderByDesc('stt.created_at')
            ->select([
                'stt.transaction_id',
                'stt.wallet_delta',
                'stt.amount_vnd',
                'stt.source_id',
                'fr.period',
                'fr.period_date',
            ])
            ->get();

        foreach ($rows as $row) {
            if ($this->reconciliationPeriodCoversDate((string) ($row->period ?? ''), $row->period_date ?? null, $orderDate)) {
                return $row;
            }
        }

        return null;
    }

    private function reconciliationPeriodCoversDate(string $period, mixed $periodDate, Carbon $targetDate): bool
    {
        if (! $periodDate) {
            return false;
        }

        $start = Carbon::parse($periodDate)->startOfDay();
        $end = match ($period) {
            'month' => $start->copy()->endOfMonth(),
            'year' => $start->copy()->endOfYear(),
            default => $start->copy()->endOfDay(),
        };

        return $targetDate->betweenIncluded($start, $end);
    }

    private function sumAffiliateCommissionStoreRefunds(string $commissionId): float
    {
        if (! Schema::hasTable('store_ticket_transactions')) {
            return 0.0;
        }

        $query = DB::table('store_ticket_transactions')
            ->where('event_type', 'AFFILIATE_COMMISSION_REFUND');

        if (
            Schema::hasColumn('store_ticket_transactions', 'source_type')
            && Schema::hasColumn('store_ticket_transactions', 'source_id')
        ) {
            $query->where('source_type', 'AFFILIATE_COMMISSION')
                ->where('source_id', $commissionId);
        } else {
            $query->where('idempotency_key', 'affiliate-commission:' . $commissionId . ':store-refund');
        }

        return $this->money($query->sum('wallet_delta'));
    }

    private function insertLedger(array $input): string
    {
        if (! Schema::hasTable('store_ticket_transactions')) {
            throw new RuntimeException('store_ticket_transactions table is not available.');
        }

        $now = now();
        $transactionId = (string) Str::uuid();
        $payload = [
            'transaction_id' => $transactionId,
            'order_id' => $input['order_id'] ?? null,
            'store_id' => $input['store_id'] ?? null,
            'product_id' => $input['product_id'] ?? null,
            'tickets_consumed' => (int) ($input['tickets_consumed'] ?? 0),
            'unit_price_at_time' => $this->money($input['unit_price_at_time'] ?? 0),
            'total_debit_amount' => $this->money($input['total_debit_amount'] ?? 0),
            'event_type' => (string) ($input['event_type'] ?? 'WALLET_DEPOSIT'),
            'ticket_calc_type' => $input['ticket_calc_type'] ?? null,
            'ticket_value_snapshot' => $input['ticket_value_snapshot'] ?? null,
            'metadata' => json_encode($input['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
            'timestamp' => $input['timestamp'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ([
            'amount_vnd',
            'wallet_delta',
            'debt_delta',
            'wallet_balance_after',
            'ticket_debt_after',
            'idempotency_key',
            'reversal_of_transaction_id',
            'source_type',
            'source_id',
            'ticket_type_code',
            'ticket_type_name',
            'ticket_quantity',
        ] as $column) {
            if (Schema::hasColumn('store_ticket_transactions', $column)) {
                $payload[$column] = $input[$column] ?? ($column === 'ticket_quantity' ? 0 : null);
            }
        }

        DB::table('store_ticket_transactions')->insert($payload);
        $this->createWalletBalanceNotificationAfterCommit($payload);

        return $transactionId;
    }

    private function createWalletBalanceNotificationAfterCommit(array $ledgerPayload): void
    {
        $walletDelta = $this->signedMoney($ledgerPayload['wallet_delta'] ?? 0);
        if ($walletDelta == 0.0) {
            return;
        }

        $storeId = trim((string) ($ledgerPayload['store_id'] ?? ''));
        $transactionId = trim((string) ($ledgerPayload['transaction_id'] ?? ''));
        if ($storeId === '' || $transactionId === '') {
            return;
        }

        $callback = function () use ($ledgerPayload, $walletDelta, $storeId, $transactionId): void {
            try {
                if (! Schema::hasTable('store_admin_notifications')) {
                    return;
                }

                if (StoreAdminNotification::query()->whereKey($transactionId)->exists()) {
                    return;
                }

                $isCredit = $walletDelta > 0;
                $amount = abs($walletDelta);
                $title = $isCredit ? 'Biến động số dư (+)' : 'Biến động số dư (-)';
                $message = sprintf(
                    'Số dư ví %s %s đ',
                    $isCredit ? 'tăng' : 'giảm',
                    number_format($amount, 0, ',', '.')
                );
                $eventType = strtoupper(trim((string) ($ledgerPayload['event_type'] ?? 'WALLET')));
                $sourceType = trim((string) ($ledgerPayload['source_type'] ?? ''));
                $sourceId = trim((string) ($ledgerPayload['source_id'] ?? ''));
                $orderId = trim((string) ($ledgerPayload['order_id'] ?? ''));
                $orderCode = $this->resolveWalletNotificationOrderCode($orderId, $ledgerPayload['metadata'] ?? null);
                $createdAt = $ledgerPayload['created_at'] ?? now();

                $notification = StoreAdminNotification::query()->create([
                    'id' => $transactionId,
                    'store_id' => $storeId,
                    'order_id' => null,
                    'order_code' => $orderCode,
                    'type' => $isCredit ? 'wallet_balance_credit' : 'wallet_balance_debit',
                    'title' => $title,
                    'message' => $message,
                    'data' => [
                        'event' => $isCredit ? 'wallet.balance.credit' : 'wallet.balance.debit',
                        'transaction_id' => $transactionId,
                        'transaction_code' => $this->buildWalletTransactionCode($transactionId, $createdAt),
                        'wallet_direction' => $isCredit ? 'credit' : 'debit',
                        'wallet_delta' => $walletDelta,
                        'amount_vnd' => $amount,
                        'wallet_balance_after' => $this->signedMoney($ledgerPayload['wallet_balance_after'] ?? 0),
                        'event_type' => $eventType,
                        'source_type' => $sourceType !== '' ? $sourceType : null,
                        'source_id' => $sourceId !== '' ? $sourceId : null,
                        'order_id' => $orderId !== '' ? $orderId : null,
                        'order_code' => $orderCode,
                        'action_url' => '/admin_store/wallet',
                    ],
                    'is_read' => false,
                    'read_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $ledgerPayload['updated_at'] ?? now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Unable to create store wallet balance notification.', [
                    'store_id' => $storeId,
                    'transaction_id' => $transactionId,
                    'wallet_delta' => $walletDelta,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }

    private function buildWalletTransactionCode(string $transactionId, mixed $createdAt = null): string
    {
        $date = $createdAt instanceof Carbon ? $createdAt : Carbon::parse($createdAt ?: now());
        $compactId = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $transactionId) ?? '');
        $suffix = substr($compactId, 0, 9);

        if (strlen($suffix) < 9) {
            $suffix = str_pad($suffix, 9, '0');
        }

        return 'GD' . $date->format('Y') . $suffix;
    }

    private function resolveWalletNotificationOrderCode(string $orderId, mixed $metadata): ?string
    {
        $metadataRecord = [];
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadataRecord = is_array($decoded) ? $decoded : [];
        } elseif (is_array($metadata)) {
            $metadataRecord = $metadata;
        }

        $metadataOrderCode = trim((string) ($metadataRecord['order_code'] ?? $metadataRecord['orderCode'] ?? ''));
        if ($metadataOrderCode !== '') {
            return $metadataOrderCode;
        }

        $orderId = trim($orderId);
        if ($orderId === '' || ! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'order_code')) {
            return null;
        }

        $orderCode = DB::table('orders')->where('id', $orderId)->value('order_code');
        $orderCode = trim((string) $orderCode);

        return $orderCode !== '' ? $orderCode : null;
    }

    private function findLedgerByIdempotencyKey(string $key): ?object
    {
        if (! Schema::hasTable('store_ticket_transactions') || ! Schema::hasColumn('store_ticket_transactions', 'idempotency_key')) {
            return null;
        }

        return DB::table('store_ticket_transactions')
            ->where('idempotency_key', $key)
            ->first();
    }

    private function hasSourceLedger(string $sourceType, string $sourceId): bool
    {
        if (
            ! Schema::hasTable('store_ticket_transactions')
            || ! Schema::hasColumn('store_ticket_transactions', 'source_type')
            || ! Schema::hasColumn('store_ticket_transactions', 'source_id')
        ) {
            return false;
        }

        return DB::table('store_ticket_transactions')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    private function isOnlinePaidOrder(Order $order): bool
    {
        $paymentMethod = strtoupper(trim((string) ($order->payment_method ?? '')));
        if (in_array($paymentMethod, ['CASH', 'COD'], true)) {
            return false;
        }

        if (Schema::hasColumn('orders', 'status_payment')) {
            return $this->normalizeStatus($order->status_payment ?? '') === 'paid';
        }

        return true;
    }

    private function normalizeStatus(?string $status): string
    {
        return mb_strtolower(trim((string) $status));
    }

    private function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    private function recordStoreLiabilityDebtDecrease(
        string $storeId,
        float $amount,
        string $fundingSource,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        array $metadata = []
    ): void {
        if (! Schema::hasTable('store_liability_ledger_entries')) {
            return;
        }

        $liabilityDebt = $this->money(
            (float) DB::table('store_liability_ledger_entries')
                ->where('store_id', $storeId)
                ->where('direction', 'debt_increase')
                ->sum('amount')
            - (float) DB::table('store_liability_ledger_entries')
                ->where('store_id', $storeId)
                ->where('direction', 'debt_decrease')
                ->sum('amount')
        );

        $amount = min($this->money($amount), max(0.0, $liabilityDebt));
        if ($amount <= 0) {
            return;
        }

        $this->storeLiabilityService->record(
            $storeId,
            'debt_decrease',
            $amount,
            $fundingSource,
            $sourceType,
            $sourceId,
            $idempotencyKey,
            'system',
            $metadata
        );
    }

    private function signedMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }

    public function calculateReturnTicketFee(OrderReturn $return): float
    {
        if (! Schema::hasTable('order_return_items') || ! Schema::hasTable('order_items')) {
            return 0.0;
        }

        $query = DB::table('order_return_items as ori')
            ->join('order_items as oi', 'oi.id', '=', 'ori.order_item_id')
            ->where('ori.order_return_id', (string) $return->id);

        $hasProducts = Schema::hasTable('products')
            && Schema::hasColumn('order_items', 'product_id')
            && Schema::hasColumn('products', 'id');
        $hasVariants = Schema::hasTable('product_variants')
            && Schema::hasColumn('order_items', 'variant_id')
            && Schema::hasColumn('product_variants', 'id');

        if ($hasProducts) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }
        if ($hasVariants) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        $selects = [
            'ori.order_return_id as order_id',
            'COALESCE(ori.quantity, 0) as quantity',
        ];

        $unitPriceCandidates = [];
        foreach (['unit_price', 'price'] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                $unitPriceCandidates[] = 'oi.' . $column;
            }
        }
        $selects[] = (empty($unitPriceCandidates)
            ? '0'
            : 'COALESCE(' . implode(', ', $unitPriceCandidates) . ', 0)'
        ) . ' as unit_price';

        if (Schema::hasColumn('order_items', 'ticket_reward_quantity')) {
            $selects[] = 'COALESCE(oi.ticket_reward_quantity, 0) as ticket_quantity_snapshot';
        } else {
            $selects[] = '0 as ticket_quantity_snapshot';
        }

        $rewardCandidates = [];
        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'pv.ticket_reward_quantity';
        }
        if ($hasProducts && Schema::hasColumn('products', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'p.ticket_reward_quantity';
        }
        $selects[] = (empty($rewardCandidates) ? '0' : 'COALESCE(' . implode(', ', $rewardCandidates) . ', 0)')
            . ' as ticket_reward_per_unit';

        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code') && $hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $selects[] = "COALESCE(NULLIF(pv.ticket_type_code, ''), NULLIF(p.ticket_type_code, ''), '') as ticket_type_code";
        } elseif ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code')) {
            $selects[] = "COALESCE(NULLIF(pv.ticket_type_code, ''), '') as ticket_type_code";
        } elseif ($hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $selects[] = "COALESCE(NULLIF(p.ticket_type_code, ''), '') as ticket_type_code";
        } else {
            $selects[] = "'' as ticket_type_code";
        }

        $rows = $query->selectRaw(implode(', ', $selects))->get();
        if ($rows->isEmpty()) {
            return 0.0;
        }

        $pricingContext = $this->buildTicketPricingContext();
        $snapshot = $this->buildTicketSnapshotFromRows($rows, $pricingContext);

        return $this->money($snapshot['ticket_fee_vnd'] ?? 0);
    }

    public function refundReturnTicketFee(OrderReturn $return, float $ticketFeeRefund, mixed $actor = null): array
    {
        if (! $this->returnTicketRefundSchemaReady()) {
            return ['refunded' => false, 'reason' => 'schema_not_ready'];
        }

        $storeId = (string) $return->store_id;
        $orderId = (string) $return->order_id;
        $returnId = (string) $return->id;

        $idempotencyKey = 'refund:order_return:' . $returnId . ':ticket_fee';
        $existing = $this->findLedgerByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return [
                'refunded' => true,
                'idempotent' => true,
                'debt_repayment' => abs($this->signedMoney($existing->debt_delta ?? 0)),
                'wallet_credit' => abs($this->signedMoney($existing->wallet_delta ?? 0)),
            ];
        }

        $store = Store::query()->whereKey($storeId)->lockForUpdate()->first();
        if (! $store) {
            return ['refunded' => false, 'reason' => 'store_not_found'];
        }

        $wasDebtLocked = (bool) ($store->is_debt_locked ?? false);
        $currentDebt = $this->money($store->ticket_debt ?? 0);
        $currentWallet = $this->money($store->wallet_balance ?? 0);

        $debtRepayment = min($currentDebt, $ticketFeeRefund);
        $walletCredit = $this->money($ticketFeeRefund - $debtRepayment);

        $debtAfter = max(0.0, $this->money($currentDebt - $debtRepayment));
        $walletAfter = $this->money($currentWallet + $walletCredit);
        $nextVersion = ((int) ($store->credit_version ?? 0)) + 1;

        $storeUpdates = [
            'ticket_debt' => $debtAfter,
            'wallet_balance' => $walletAfter,
            'credit_version' => $nextVersion,
            'updated_at' => now(),
        ];

        if ($debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
            $storeUpdates['is_debt_locked'] = false;
            $storeUpdates['debt_locked_at'] = null;
            $storeUpdates['debt_lock_reason'] = null;
        }

        Store::query()->whereKey($storeId)->update($storeUpdates);
        $store->forceFill($storeUpdates);

        if ($wasDebtLocked && $debtAfter <= 0 && Schema::hasColumn('stores', 'is_debt_locked')) {
            $this->broadcastStoreCreditVisibilityChanged($storeId, 'store_debt_unlocked');
        }

        $this->insertLedger([
            'order_id' => $orderId,
            'store_id' => $storeId,
            'event_type' => 'RETURN_TICKET_REFUND',
            'amount_vnd' => $ticketFeeRefund,
            'wallet_delta' => $walletCredit,
            'debt_delta' => -1 * $debtRepayment,
            'wallet_balance_after' => $walletAfter,
            'ticket_debt_after' => $debtAfter,
            'idempotency_key' => $idempotencyKey,
            'source_type' => 'order_return',
            'source_id' => $returnId,
            'metadata' => [
                'return_code' => $return->return_code,
                'debt_repayment' => $debtRepayment,
                'wallet_credit' => $walletCredit,
                'credit_version' => $nextVersion,
                'actor_id' => $this->actorId($actor),
            ],
        ]);

        $this->broadcastStoreWalletUpdated($storeId, 'return_ticket_refund', [
            'event_type' => 'RETURN_TICKET_REFUND',
            'source_type' => 'order_return',
            'source_id' => $returnId,
            'amount_vnd' => $ticketFeeRefund,
            'wallet_delta' => $walletCredit,
            'debt_delta' => -1 * $debtRepayment,
            'wallet_balance' => $walletAfter,
            'ticket_debt' => $debtAfter,
            'account_balance' => $this->signedMoney($walletAfter - $debtAfter),
            'credit_version' => $nextVersion,
            'is_debt_locked' => $debtAfter > 0 && (bool) ($store->is_debt_locked ?? false),
        ]);

        return [
            'refunded' => true,
            'debt_repayment' => $debtRepayment,
            'wallet_credit' => $walletCredit,
        ];
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
}
