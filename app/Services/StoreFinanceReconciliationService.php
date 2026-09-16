<?php

namespace App\Services;

use App\Events\StoreFinanceReconciliationUpdated;
use App\Models\FinancialReconciliation;
use App\Models\Reconciliation;
use App\Models\Store;
use App\Models\StoreWalletWithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreFinanceReconciliationService
{
    public const STATUS_PENDING_SHOP = 'pending_shop';
    public const STATUS_PENDING_ADMIN = 'pending_admin';
    public const STATUS_WAITING_PAYMENT = 'waiting_payment';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';
    private const SUBMITTED_STATUSES = [
        self::STATUS_PENDING_ADMIN,
        self::STATUS_WAITING_PAYMENT,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
    ];

    public function __construct(private readonly StoreCreditService $storeCreditService)
    {
    }

    public function periodRange(string $period, Carbon $baseDate): array
    {
        if ($period === 'month') {
            return [$baseDate->copy()->startOfMonth(), $baseDate->copy()->endOfMonth()];
        }

        if ($period === 'year') {
            return [$baseDate->copy()->startOfYear(), $baseDate->copy()->endOfYear()];
        }

        return [$baseDate->copy()->startOfDay(), $baseDate->copy()->endOfDay()];
    }

    public function reconciliationPeriodRange(string $storeId, string $period, Carbon $baseDate): array
    {
        if ($period === 'day') {
            $latest = FinancialReconciliation::query()
                ->where('store_id', $storeId)
                ->where('period', $period)
                ->whereDate('period_date', '<', $baseDate->toDateString())
                ->whereIn('status', [self::STATUS_WAITING_PAYMENT, self::STATUS_PAID])
                ->orderByDesc('period_date')
                ->first();

            if ($latest) {
                $from = Carbon::parse($latest->period_date)->addDay()->startOfDay();
            } else {
                $store = Store::query()->whereKey($storeId)->first();
                $from = $store && $store->created_at
                    ? Carbon::parse($store->created_at)->startOfDay()
                    : $baseDate->copy()->subYear()->startOfDay();

                if ($from->greaterThan($baseDate)) {
                    $from = $baseDate->copy()->startOfDay();
                }
            }
            $to = $baseDate->copy()->endOfDay();
            return [$from, $to];
        }

        return $this->periodRange($period, $baseDate);
    }

    public function buildSnapshot(string $storeId, string $period, Carbon $baseDate): array
    {
        [$from, $to] = $this->reconciliationPeriodRange($storeId, $period, $baseDate);

        $revenueBuckets = $this->sumRevenueBuckets($storeId, $from, $to);
        $revenueBuckets['transfer'] += $this->sumPendingTransferRevenue($storeId, $from, $to);
        $revenueTotal = $this->money(array_sum($revenueBuckets));
        $affiliateCommission = $this->money(
            $this->sumAffiliateCommission($storeId, $from, $to)
            + $this->sumPendingTransferAffiliateCommission($storeId, $from, $to)
        );
        $ticketReward = $this->money($this->sumTicketReward($storeId, $from, $to));
        $refundAmount = $this->money($this->sumRefundAmount($storeId, $from, $to));
        $cashCollected = $this->money($revenueBuckets['cash'] + $revenueBuckets['cod']);
        $creditSnapshot = $this->storeCreditSnapshot($storeId);
        $walletForWithdrawal = $this->signedMoney($creditSnapshot['wallet_balance_for_withdrawal']);
        $withdrawable = $walletForWithdrawal;

        $periodDate = $this->periodRange($period, $baseDate)[0];

        $sourceData = [
            'formula_version' => 'wallet_includes_affiliate_commission_v1',
            'period' => $period,
            'period_date' => $periodDate->toDateString(),
            'revenue_total' => $revenueTotal,
            'revenue_cash' => $this->money($revenueBuckets['cash']),
            'revenue_transfer' => $this->money($revenueBuckets['transfer']),
            'revenue_cod' => $this->money($revenueBuckets['cod']),
            'revenue_other' => $this->money($revenueBuckets['other']),
            'affiliate_commission' => $affiliateCommission,
            'ticket_reward' => $ticketReward,
            'cash_collected_amount' => $cashCollected,
        ];

        return [
            'period' => $period,
            'period_date' => $periodDate->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue_total' => $revenueTotal,
            'revenue_cash' => $this->money($revenueBuckets['cash']),
            'revenue_transfer' => $this->money($revenueBuckets['transfer']),
            'revenue_cod' => $this->money($revenueBuckets['cod']),
            'revenue_other' => $this->money($revenueBuckets['other']),
            'affiliate_commission' => $affiliateCommission,
            'ticket_reward' => $ticketReward,
            'refund_amount' => $refundAmount,
            'cash_collected_amount' => $cashCollected,
            'wallet_balance' => $creditSnapshot['wallet_balance'],
            'ticket_debt' => $creditSnapshot['ticket_debt'],
            'account_balance' => $creditSnapshot['account_balance'],
            'wallet_balance_for_withdrawal' => $walletForWithdrawal,
            'account_balance_for_withdrawal' => $walletForWithdrawal,
            'withdrawable_amount' => $withdrawable,
            'net_amount' => $withdrawable,
            'source_hash' => hash('sha256', json_encode($sourceData, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    public function submitForAdminReview(
        Store $store,
        string $period,
        Carbon $baseDate,
        string $source = 'manual',
        mixed $actor = null
    ): FinancialReconciliation {
        $snapshot = $this->buildSnapshot((string) $store->id, $period, $baseDate);

        return DB::transaction(function () use ($store, $period, $snapshot, $source, $actor): FinancialReconciliation {
            // Delete old unapproved ones before creating a new one
            FinancialReconciliation::query()
                ->where('store_id', (string) $store->id)
                ->where('period', $period)
                ->whereIn('status', [self::STATUS_PENDING_ADMIN, self::STATUS_PENDING_SHOP])
                ->whereDate('period_date', '<', $snapshot['period_date'])
                ->delete();

            if (Schema::hasTable('reconciliations')) {
                Reconciliation::query()
                    ->where('store_id', (string) $store->id)
                    ->where('status', 'pending')
                    ->whereDate('reconciliation_date', '<', $snapshot['period_date'])
                    ->delete();
            }

            $submittedRecord = FinancialReconciliation::query()
                ->where('store_id', (string) $store->id)
                ->where('period', $period)
                ->whereDate('period_date', $snapshot['period_date'])
                ->whereIn('status', self::SUBMITTED_STATUSES)
                ->orderByDesc('submitted_at')
                ->orderByDesc('updated_at')
                ->lockForUpdate()
                ->first();

            if ($submittedRecord) {
                if ((string) $submittedRecord->status !== self::STATUS_REJECTED) {
                    return $submittedRecord->refresh();
                }
            }

            $record = FinancialReconciliation::query()
                ->where('store_id', (string) $store->id)
                ->where('period', $period)
                ->whereDate('period_date', $snapshot['period_date'])
                ->whereIn('status', [
                    self::STATUS_PENDING_SHOP,
                    self::STATUS_PENDING_ADMIN,
                    self::STATUS_REJECTED,
                    self::STATUS_WAITING_PAYMENT,
                ])
                ->whereNull('paid_at')
                ->orderByRaw("CASE WHEN status = '" . self::STATUS_PENDING_ADMIN . "' THEN 0 WHEN status = '" . self::STATUS_WAITING_PAYMENT . "' THEN 1 ELSE 2 END")
                ->orderByDesc('updated_at')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                $record = new FinancialReconciliation();
                $record->id = (string) Str::uuid();
                $record->store_id = (string) $store->id;
                $record->period = $period;
                $record->period_date = $snapshot['period_date'];
            }

            if (
                $record->exists
                && $record->status === self::STATUS_WAITING_PAYMENT
                && (string) ($record->source_hash ?? '') !== ''
                && (string) $record->source_hash === (string) $snapshot['source_hash']
            ) {
                $this->syncAdminReconciliation($record, 'approved', $actor);

                return $record->refresh();
            }

            $record->forceFill([
                'revenue_total' => $snapshot['revenue_total'],
                'revenue_cash' => $snapshot['revenue_cash'],
                'revenue_transfer' => $snapshot['revenue_transfer'],
                'revenue_cod' => $snapshot['revenue_cod'],
                'affiliate_commission' => $snapshot['affiliate_commission'],
                'ticket_reward' => $snapshot['ticket_reward'],
                'refund_amount' => $snapshot['refund_amount'],
                'cash_collected_amount' => $snapshot['cash_collected_amount'],
                'withdrawable_amount' => $snapshot['withdrawable_amount'],
                'approved_withdrawable_amount' => 0,
                'net_amount' => $snapshot['net_amount'],
                'source_hash' => $snapshot['source_hash'],
                'submitted_source' => $source,
                'submitted_at' => now(),
                'submitted_by' => $this->actorId($actor),
                'status' => self::STATUS_PENDING_ADMIN,
                'confirmed_at' => now(),
                'paid_at' => null,
            ])->save();

            $this->syncAdminReconciliation($record);

            DB::afterCommit(fn () => $this->broadcast($record, 'submitted'));

            return $record->refresh();
        });
    }

    public function approveFinancialReconciliation(FinancialReconciliation $record, mixed $actor = null, ?string $note = null): FinancialReconciliation
    {
        return DB::transaction(function () use ($record, $actor, $note): FinancialReconciliation {
            /** @var FinancialReconciliation $locked */
            $locked = FinancialReconciliation::query()
                ->whereKey((string) $record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== self::STATUS_PAID) {
                $approvedAmount = $this->signedMoney($locked->net_amount ?? $locked->withdrawable_amount ?? 0);
                $locked->forceFill([
                    'status' => self::STATUS_WAITING_PAYMENT,
                    'approved_withdrawable_amount' => $approvedAmount,
                    'confirmed_at' => $locked->confirmed_at ?: now(),
                    'paid_at' => null,
                ])->save();

            }

            $this->syncAdminReconciliation($locked, 'approved', $actor, $note);
            DB::afterCommit(fn () => $this->broadcast($locked->fresh(), 'approved'));

            return $locked->refresh();
        });
    }

    public function rejectFinancialReconciliation(FinancialReconciliation $record, mixed $actor = null, ?string $note = null): FinancialReconciliation
    {
        return DB::transaction(function () use ($record, $actor, $note): FinancialReconciliation {
            /** @var FinancialReconciliation $locked */
            $locked = FinancialReconciliation::query()->whereKey((string) $record->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => self::STATUS_REJECTED])->save();
            $this->syncAdminReconciliation($locked, 'rejected', $actor, $note);
            DB::afterCommit(fn () => $this->broadcast($locked->fresh(), 'rejected'));

            return $locked->refresh();
        });
    }

    public function findLatestForPeriod(string $storeId, string $period, Carbon $baseDate): ?FinancialReconciliation
    {
        $periodDate = $this->periodRange($period, $baseDate)[0];

        return FinancialReconciliation::query()
            ->where('store_id', $storeId)
            ->where('period', $period)
            ->whereDate('period_date', $periodDate->toDateString())
            ->orderByRaw("CASE WHEN status = '" . self::STATUS_WAITING_PAYMENT . "' THEN 0 WHEN status = '" . self::STATUS_PENDING_ADMIN . "' THEN 1 WHEN status = '" . self::STATUS_PENDING_SHOP . "' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function ensureApprovedSourceIsFresh(FinancialReconciliation $record, array $currentSnapshot): FinancialReconciliation
    {
        if (
            $record->status === self::STATUS_WAITING_PAYMENT
            && (string) ($record->source_hash ?? '') !== ''
            && (
                (string) $record->source_hash !== (string) ($currentSnapshot['source_hash'] ?? '')
                || $this->money($record->refund_amount ?? 0) !== $this->money($currentSnapshot['refund_amount'] ?? 0)
            )
        ) {
            $record->forceFill([
                'status' => self::STATUS_PENDING_ADMIN,
                'revenue_total' => $currentSnapshot['revenue_total'],
                'revenue_cash' => $currentSnapshot['revenue_cash'],
                'revenue_transfer' => $currentSnapshot['revenue_transfer'],
                'revenue_cod' => $currentSnapshot['revenue_cod'],
                'affiliate_commission' => $currentSnapshot['affiliate_commission'],
                'ticket_reward' => $currentSnapshot['ticket_reward'],
                'refund_amount' => $currentSnapshot['refund_amount'],
                'cash_collected_amount' => $currentSnapshot['cash_collected_amount'],
                'withdrawable_amount' => $currentSnapshot['withdrawable_amount'],
                'net_amount' => $currentSnapshot['net_amount'],
                'source_hash' => $currentSnapshot['source_hash'],
            ])->save();

            $this->syncAdminReconciliation($record);
            DB::afterCommit(fn () => $this->broadcast($record->fresh(), 'requires_reapproval'));
        }

        return $record->fresh();
    }

    public function autoSubmitForDate(Carbon $date, string $period = 'day'): array
    {
        if (! Schema::hasTable('stores') || ! Schema::hasTable('financial_reconciliations')) {
            return ['submitted' => 0, 'skipped' => 0];
        }

        $submitted = 0;
        $skipped = 0;

        Store::query()
            ->whereIn('status', Store::PUBLIC_STATUSES)
            ->orderBy('id')
            ->chunkById(100, function ($stores) use ($date, $period, &$submitted, &$skipped): void {
                foreach ($stores as $store) {
                    if ($this->findSubmittedForPeriod((string) $store->id, $period, $date)) {
                        $skipped++;
                        continue;
                    }

                    $this->submitForAdminReview($store, $period, $date, 'auto');
                    $submitted++;
                }
            });

        return ['submitted' => $submitted, 'skipped' => $skipped];
    }

    public function findSubmittedForPeriod(string $storeId, string $period, Carbon $baseDate): ?FinancialReconciliation
    {
        $periodDate = $this->periodRange($period, $baseDate)[0];

        return FinancialReconciliation::query()
            ->where('store_id', $storeId)
            ->where('period', $period)
            ->whereDate('period_date', $periodDate->toDateString())
            ->whereIn('status', self::SUBMITTED_STATUSES)
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function latestSubmittedBefore(string $storeId, string $period, Carbon $baseDate): ?FinancialReconciliation
    {
        $periodDate = $this->periodRange($period, $baseDate)[0];

        return FinancialReconciliation::query()
            ->where('store_id', $storeId)
            ->where('period', $period)
            ->whereDate('period_date', '<', $periodDate->toDateString())
            ->whereIn('status', self::SUBMITTED_STATUSES)
            ->orderByDesc('period_date')
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function serialize(?FinancialReconciliation $record): ?array
    {
        if (! $record) {
            return null;
        }

        [$from, $to] = $this->reconciliationPeriodRange((string) $record->store_id, (string) $record->period, Carbon::parse($record->period_date));
        $topupAmount = $this->sumTopupAmount((string) $record->store_id, $from, $to);
        $latestWithdrawalAmount = $this->latestPaidWithdrawalAmount((string) $record->store_id);

        return [
            'id' => (string) $record->id,
            'step' => $this->step($record),
            'status' => (string) $record->status,
            'period' => (string) $record->period,
            'period_date' => $record->period_date ? $record->period_date->toDateString() : null,
            'revenue_total' => (float) $record->revenue_total,
            'revenue_cash' => (float) $record->revenue_cash,
            'revenue_transfer' => (float) $record->revenue_transfer,
            'revenue_cod' => (float) $record->revenue_cod,
            'affiliate_commission' => (float) $record->affiliate_commission,
            'ticket_reward' => (float) $record->ticket_reward,
            'refund_amount' => (float) ($record->refund_amount ?? 0),
            'cash_collected_amount' => (float) ($record->cash_collected_amount ?? 0),
            'withdrawable_amount' => (float) ($record->withdrawable_amount ?? $record->net_amount ?? 0),
            'approved_withdrawable_amount' => (float) ($record->approved_withdrawable_amount ?? 0),
            'net_amount' => (float) $record->net_amount,
            'confirmed_at' => optional($record->confirmed_at)?->toIso8601String(),
            'paid_at' => optional($record->paid_at)?->toIso8601String(),
            'submitted_source' => $record->submitted_source ?? null,
            'submitted_at' => optional($record->submitted_at)?->toIso8601String(),
            'updated_at' => optional($record->updated_at)?->toIso8601String(),
            'topup_amount' => $topupAmount,
            'latest_withdrawal_amount' => $latestWithdrawalAmount,
        ];
    }

    public function step(?FinancialReconciliation $record): int
    {
        if (! $record || $record->status === self::STATUS_PENDING_SHOP) {
            return 1;
        }

        if ($record->status === self::STATUS_REJECTED) {
            return 0;
        }

        if ($record->status === self::STATUS_PAID || $record->paid_at) {
            return 4;
        }

        if ($record->status === self::STATUS_WAITING_PAYMENT) {
            return 3;
        }

        return 2;
    }

    public function syncAdminFromFinancial(
        FinancialReconciliation $record,
        ?string $adminStatus = null,
        mixed $actor = null,
        ?string $note = null
    ): void {
        $this->syncAdminReconciliation($record, $adminStatus, $actor, $note);
    }

    private function syncAdminReconciliation(
        FinancialReconciliation $record,
        ?string $adminStatus = null,
        mixed $actor = null,
        ?string $note = null
    ): void {
        if (! Schema::hasTable('reconciliations')) {
            return;
        }

        $status = $adminStatus ?: match ((string) $record->status) {
            self::STATUS_PENDING_ADMIN, self::STATUS_PENDING_SHOP => 'pending',
            self::STATUS_WAITING_PAYMENT => 'approved',
            self::STATUS_REJECTED => 'rejected',
            self::STATUS_PAID => 'paid',
            default => 'pending',
        };

        $admin = Reconciliation::query()
            ->where('store_id', (string) $record->store_id)
            ->whereDate('reconciliation_date', $record->period_date?->toDateString() ?? (string) $record->period_date)
            ->orderByDesc('updated_at')
            ->lockForUpdate()
            ->first();

        if (! $admin) {
            $admin = new Reconciliation();
            $admin->store_id = (string) $record->store_id;
            $admin->reconciliation_date = $record->period_date?->toDateString() ?? (string) $record->period_date;
        }

        $admin->status = $status;
        $admin->gross_revenue = $record->revenue_total ?? 0;
        $admin->net_revenue = $record->net_amount ?? 0;
        $admin->date_confirmed = $record->confirmed_at ?: now();

        if ($note !== null) {
            $admin->notes = $note;
        }

        if ($status === 'approved') {
            $admin->approved_by = $this->actorId($actor) ?: $admin->approved_by;
            $admin->approved_at = $admin->approved_at ?: now();
        }

        $admin->save();
    }

    private function sumRevenueBuckets(string $storeId, Carbon $from, Carbon $to): array
    {
        $buckets = ['cash' => 0.0, 'transfer' => 0.0, 'cod' => 0.0, 'other' => 0.0];
        if (! Schema::hasTable('orders')) {
            return $buckets;
        }

        $amountColumn = $this->resolveAmountColumn();
        $orderDateExpression = $this->resolveOrderDateExpression();
        $query = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $this->completedOrderStatuses());
        }

        $rows = $query
            ->selectRaw("LOWER(COALESCE(payment_method, '')) as method")
            ->selectRaw('COALESCE(SUM(' . $amountColumn . '), 0) as total_amount')
            ->groupBy('method')
            ->get();

        foreach ($rows as $row) {
            $buckets[$this->mapPaymentMethodBucket((string) ($row->method ?? ''))] += (float) ($row->total_amount ?? 0);
        }

        return $buckets;
    }

    private function storeCreditSnapshot(string $storeId): array
    {
        $walletBalance = 0.0;
        $ticketDebt = 0.0;

        if (Schema::hasTable('stores')) {
            $store = Store::query()->whereKey($storeId)->first(['id', 'wallet_balance', 'ticket_debt']);
            if ($store) {
                $walletBalance = $this->signedMoney($store->wallet_balance ?? 0);
                $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
            }
        }

        return [
            'wallet_balance' => $walletBalance,
            'ticket_debt' => $ticketDebt,
            'account_balance' => $this->signedMoney($walletBalance - $ticketDebt),
            'wallet_balance_for_withdrawal' => $this->signedMoney($walletBalance - $ticketDebt),
            'account_balance_for_withdrawal' => $this->signedMoney($walletBalance - $ticketDebt),
        ];
    }

    private function sumAffiliateCommission(string $storeId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('commission_users') || ! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return 0.0;
        }

        $query = DB::table('commission_users')
            ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereRaw('DATE(' . $this->resolveOrderDateExpression() . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $this->resolveOrderDateExpression() . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(orders.status, ''))"), $this->completedOrderStatuses());
        }

        if (Schema::hasColumn('commission_users', 'status')) {
            $query->whereIn('commission_users.status', ['paid', 'pending']);
        }

        return (float) $query->sum('commission_users.amount');
    }

    private function sumPendingTransferAffiliateCommission(string $storeId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('commission_users') || ! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return 0.0;
        }

        $query = DB::table('commission_users')
            ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereRaw('DATE(' . $this->resolveOrderDateExpression() . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $this->resolveOrderDateExpression() . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereNotIn(DB::raw("LOWER(COALESCE(orders.status, ''))"), $this->completedOrderStatuses());
        }

        $this->scopeTransferPayment($query, 'orders');

        if (Schema::hasColumn('commission_users', 'status')) {
            $query->where('commission_users.status', 'pending');
        }

        return (float) $query->sum('commission_users.amount');
    }

    private function sumPendingTransferRevenue(string $storeId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('orders')) {
            return 0.0;
        }

        $query = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString());

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), $this->completedOrderStatuses());
        }

        $this->scopeTransferPayment($query);

        return (float) $query->sum($this->resolveAmountColumn());
    }

    private function sumTicketReward(string $storeId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return 0.0;
        }

        $orderDateExpression = $this->resolveOrderDateExpression('o');
        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.store_id', $storeId)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(o.status, ''))"), $this->completedOrderStatuses());
        }

        if (Schema::hasColumn('order_items', 'ticket_reward_quantity')) {
            $tickets = (int) $query->sum(DB::raw('COALESCE(oi.quantity, 0) * COALESCE(oi.ticket_reward_quantity, 0)'));
            if ($tickets > 0) {
                return (float) $tickets * 5000;
            }
        }

        if (! Schema::hasTable('store_ticket_transactions')) {
            return 0.0;
        }

        $ledger = DB::table('store_ticket_transactions')
            ->where('store_id', $storeId)
            ->whereBetween('timestamp', [$from->toDateTimeString(), $to->toDateTimeString()]);

        if (Schema::hasColumn('store_ticket_transactions', 'event_type')) {
            $ledger->where('event_type', 'ORDER_DELIVERED');
        }

        return (float) ((int) $ledger->sum('tickets_consumed')) * 5000;
    }

    private function sumRefundAmount(string $storeId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('order_returns')) {
            return 0.0;
        }

        $amountColumn = Schema::hasColumn('order_returns', 'refund_amount_final') ? 'refund_amount_final' : null;
        if (! $amountColumn) {
            return 0.0;
        }

        $dateColumn = Schema::hasColumn('order_returns', 'refund_processed_at')
            ? 'refund_processed_at'
            : (Schema::hasColumn('order_returns', 'updated_at') ? 'updated_at' : 'created_at');

        $query = DB::table('order_returns')
            ->where('store_id', $storeId)
            ->whereDate($dateColumn, '>=', $from->toDateString())
            ->whereDate($dateColumn, '<=', $to->toDateString());

        if (Schema::hasColumn('order_returns', 'status')) {
            $query->whereRaw("LOWER(COALESCE(status, '')) = ?", ['refunded']);
        }

        return (float) $query->sum($amountColumn);
    }

    public function sumTopupAmount(string $storeId, Carbon $from, Carbon $to): float
    {
        if (Schema::hasTable('store_wallet_transactions')) {
            return $this->money(DB::table('store_wallet_transactions')
                ->where('store_id', $storeId)
                ->where('type', 'topup')
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount'));
        }

        if (Schema::hasTable('store_ticket_transactions') && Schema::hasColumn('store_ticket_transactions', 'wallet_delta')) {
            $dateColumn = Schema::hasColumn('store_ticket_transactions', 'timestamp') ? 'timestamp' : 'created_at';
            return $this->money(DB::table('store_ticket_transactions')
                ->where('store_id', $storeId)
                ->where('wallet_delta', '>', 0)
                ->whereBetween($dateColumn, [$from, $to])
                ->sum('wallet_delta'));
        }

        return 0.0;
    }

    public function latestPaidWithdrawalAmount(string $storeId): float
    {
        if (! Schema::hasTable('store_wallet_withdrawal_requests')) {
            return 0.0;
        }

        $latest = DB::table('store_wallet_withdrawal_requests')
            ->where('store_id', $storeId)
            ->where('status', 'paid')
            ->orderByDesc('created_at')
            ->first();

        return $latest ? (float) $latest->amount : 0.0;
    }

    private function scopeTransferPayment($query, string $table = 'orders'): void
    {
        if (! Schema::hasColumn('orders', 'payment_method')) {
            return;
        }

        $prefix = $table !== '' ? $table . '.' : '';
        $query->where(function ($builder) use ($prefix): void {
            $builder
                ->whereRaw("LOWER(COALESCE({$prefix}payment_method, '')) LIKE ?", ['%bank%'])
                ->orWhereRaw("LOWER(COALESCE({$prefix}payment_method, '')) LIKE ?", ['%transfer%'])
                ->orWhereRaw("LOWER(COALESCE({$prefix}payment_method, '')) LIKE ?", ['%vnpay%'])
                ->orWhereRaw("LOWER(COALESCE({$prefix}payment_method, '')) LIKE ?", ['%momo%']);
        });
    }

    private function resolveAmountColumn(): string
    {
        if (Schema::hasColumn('orders', 'final_amount')) {
            return 'final_amount';
        }

        if (Schema::hasColumn('orders', 'total_amount')) {
            return 'total_amount';
        }

        if (Schema::hasColumn('orders', 'total')) {
            return 'total';
        }

        return 'final_amount';
    }

    private function resolveOrderDateExpression(string $alias = 'orders'): string
    {
        if (Schema::hasColumn('orders', 'delivered_at')) {
            return 'COALESCE(' . $alias . '.delivered_at, ' . $alias . '.created_at)';
        }

        return $alias . '.created_at';
    }

    private function mapPaymentMethodBucket(string $method): string
    {
        $value = strtolower(trim($method));
        if ($value === '') {
            return 'other';
        }

        if (str_contains($value, 'cod')) {
            return 'cod';
        }

        if (str_contains($value, 'cash') || str_contains($value, 'tien mat') || str_contains($value, 'tienmat')) {
            return 'cash';
        }

        if (str_contains($value, 'bank') || str_contains($value, 'transfer') || str_contains($value, 'vnpay') || str_contains($value, 'momo')) {
            return 'transfer';
        }

        return 'other';
    }

    private function completedOrderStatuses(): array
    {
        return ['delivered', 'completed', 'call_completed'];
    }

    private function broadcast(FinancialReconciliation $record, string $action): void
    {
        try {
            event(new StoreFinanceReconciliationUpdated(
                (string) $record->store_id,
                (string) $record->period,
                $record->period_date?->toDateString() ?? (string) $record->period_date,
                $action,
                $this->step($record),
                $this->serialize($record) ?? [],
            ));
        } catch (\Throwable $exception) {
            logger()->warning('Failed to broadcast store finance reconciliation.', [
                'store_id' => (string) $record->store_id,
                'period' => (string) $record->period,
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

    /**
     * Kiểm tra xem một đơn (đối soát hoặc rút ví) có phải là đơn chưa duyệt mới nhất của cửa hàng hay không.
     *
     * @param string $storeId  ID cửa hàng
     * @param string $type     Loại đơn: 'reconciliation' hoặc 'withdrawal'
     * @param string|int $id   ID của đơn cần kiểm tra
     * @return bool true nếu đơn này là đơn mới nhất chưa duyệt, false nếu ngược lại
     */
    public function isLatestUnapproved(string $storeId, string $type, string|int $id): bool
    {
        // Lệnh rút ví không áp dụng quy tắc này (luôn cho phép duyệt)
        if ($type === 'withdrawal') {
            return true;
        }

        // Lấy tất cả đơn đối soát chưa duyệt của cửa hàng
        $pendingReconciliations = Reconciliation::query()
            ->where('store_id', $storeId)
            ->whereIn('status', ['pending', 'pending_shop', 'waiting_payment', 'disputed'])
            ->orderByDesc('created_at')
            ->get(['id', 'created_at'])
            ->map(fn ($r) => ['type' => 'reconciliation', 'id' => (string) $r->id, 'created_at' => $r->created_at ? $r->created_at->timestamp : 0]);

        // Nếu không có đơn nào chưa duyệt thì cho phép
        if ($pendingReconciliations->isEmpty()) {
            return true;
        }

        // Chỉ đơn mới nhất mới được phép xử lý
        $latest = $pendingReconciliations->sortByDesc('created_at')->first();

        return $latest['id'] === (string) $id;
    }

    /**
     * Lấy đơn chưa duyệt mới nhất cho danh sách các cửa hàng (để tối ưu hóa query hàng loạt).
     *
     * @param array $storeIds Danh sách ID cửa hàng
     * @return array Mảng dạng [store_id => ['type' => 'reconciliation'|'withdrawal', 'id' => string]]
     */
    public function getLatestUnapprovedForStores(array $storeIds): array
    {
        // Lọc bỏ giá trị null, trống để an toàn cho truy vấn SQL
        $storeIds = array_values(array_filter($storeIds, fn($val) => !is_null($val) && $val !== ''));

        if (empty($storeIds)) {
            return [];
        }

        // Lấy tất cả đơn đối soát chưa duyệt của các cửa hàng
        $pendingReconciliations = Reconciliation::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', ['pending', 'pending_shop', 'waiting_payment', 'disputed'])
            ->get(['id', 'store_id', 'created_at']);

        $result = [];
        foreach ($storeIds as $storeId) {
            $storeRecons = $pendingReconciliations->where('store_id', $storeId)->map(fn ($r) => [
                'type' => 'reconciliation',
                'id' => (string) $r->id,
                'created_at' => $r->created_at ? $r->created_at->timestamp : 0
            ]);

            $latest = $storeRecons->sortByDesc('created_at')->first();

            if ($latest) {
                $result[$storeId] = $latest;
            } else {
                $result[$storeId] = null;
            }
        }

        return $result;
    }
}
