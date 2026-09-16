<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialistDashboardService
{
    private const SUCCESS_ORDER_STATUSES = ['delivered', 'completed', 'call_completed'];
    private const ACTIVE_STORE_STATUSES = ['active', 'approved'];
    private const SUSPENDED_STORE_STATUSES = ['suspended', 'paused', 'inactive', 'blocked', 'closed'];
    private const TICKET_STATUS_GROUPS = [
        'eligible' => ['ELIGIBLE', 'AVAILABLE', 'USED_FOR_ORDER', 'USED_IN_ROOM'],
        'held' => ['HELD', 'DISPUTED', 'LOCKED'],
        'cancelled' => ['CANCELLED', 'CANCELED', 'EXPIRED', 'REFUNDED'],
    ];

    public function __construct(private readonly TicketPricingService $ticketPricingService)
    {
    }

    public function build(User $specialist, array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $dateRange = $this->resolveDateRange($filters);
        $assignment = $this->resolveAssignedStoreIds((int) $specialist->id);

        $assignedStoreIds = $assignment['store_ids'];
        $storeOptions = $this->loadStoreOptions($assignedStoreIds);
        $visibleStoreIds = $this->loadVisibleStoreIds($assignedStoreIds, $filters);

        $commissionByStore = $this->estimateCommissionByStore(
            $visibleStoreIds,
            $dateRange['from'],
            $dateRange['to']
        );

        $metrics = [
            'stores' => $this->buildStoreMetrics($visibleStoreIds, $dateRange['from'], $dateRange['to']),
            'orders' => $this->buildOrderMetrics($visibleStoreIds, $dateRange['from'], $dateRange['to']),
            'tickets' => $this->buildTicketMetrics($visibleStoreIds, $dateRange['from'], $dateRange['to'], $filters['ticket_status']),
            'income' => $this->buildIncomeMetrics(
                $specialist,
                $visibleStoreIds,
                $dateRange['from'],
                $dateRange['to'],
                $commissionByStore
            ),
        ];

        return [
            'specialist' => $specialist,
            'filters' => $filters,
            'dateRange' => $dateRange,
            'metrics' => $metrics,
            'storeOptions' => $storeOptions,
            'storeRows' => $this->buildStoreRows($visibleStoreIds, $dateRange['from'], $dateRange['to'], $commissionByStore),
            'assignmentSource' => $assignment['source'],
            'hasSpecialistSettlementTables' => Schema::hasTable('specialist_ticket_earnings') || Schema::hasTable('specialist_settlements'),
            'hasRegionColumn' => Schema::hasTable('stores') && Schema::hasColumn('stores', 'region_id'),
        ];
    }

    private function normalizeFilters(array $query): array
    {
        $range = (string) ($query['range'] ?? 'this_month');
        if (! in_array($range, ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'custom'], true)) {
            $range = 'this_month';
        }

        $ticketStatus = (string) ($query['ticket_status'] ?? 'all');
        if (! in_array($ticketStatus, ['all', 'eligible', 'held', 'cancelled'], true)) {
            $ticketStatus = 'all';
        }

        return [
            'range' => $range,
            'from_date' => trim((string) ($query['from_date'] ?? '')),
            'to_date' => trim((string) ($query['to_date'] ?? '')),
            'store_id' => trim((string) ($query['store_id'] ?? '')),
            'region' => trim((string) ($query['region'] ?? ($query['region_id'] ?? ''))),
            'ticket_status' => $ticketStatus,
        ];
    }

    private function resolveDateRange(array $filters): array
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $range = $filters['range'];

        if ($range === 'today') {
            $from = $now->copy()->startOfDay();
            $to = $now->copy()->endOfDay();
        } elseif ($range === 'yesterday') {
            $from = $now->copy()->subDay()->startOfDay();
            $to = $now->copy()->subDay()->endOfDay();
        } elseif ($range === 'last_7_days') {
            $from = $now->copy()->subDays(6)->startOfDay();
            $to = $now->copy()->endOfDay();
        } elseif ($range === 'last_month') {
            $from = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $to = $now->copy()->subMonthNoOverflow()->endOfMonth();
        } elseif ($range === 'custom') {
            $from = $filters['from_date'] !== ''
                ? Carbon::parse($filters['from_date'], 'Asia/Ho_Chi_Minh')->startOfDay()
                : $now->copy()->startOfMonth();
            $to = $filters['to_date'] !== ''
                ? Carbon::parse($filters['to_date'], 'Asia/Ho_Chi_Minh')->endOfDay()
                : $now->copy()->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
        } else {
            $range = 'this_month';
            $from = $now->copy()->startOfMonth();
            $to = $now->copy()->endOfDay();
        }

        return [
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'label' => $from->format('d/m/Y') . ' - ' . $to->format('d/m/Y'),
        ];
    }

    private function resolveAssignedStoreIds(int $specialistId): array
    {
        $assignmentStoreIds = $this->activeAssignmentStoreIds($specialistId);
        if (! empty($assignmentStoreIds)) {
            return [
                'source' => 'specialist_store_assignments',
                'store_ids' => $assignmentStoreIds,
            ];
        }

        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'sale_id')) {
            return [
                'source' => 'none',
                'store_ids' => [],
            ];
        }

        return [
            'source' => 'stores.sale_id',
            'store_ids' => DB::table('stores')
                ->where('sale_id', $specialistId)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->values()
                ->all(),
        ];
    }

    private function activeAssignmentStoreIds(int $specialistId): array
    {
        if (
            ! Schema::hasTable('specialist_store_assignments')
            || ! Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            || ! Schema::hasColumn('specialist_store_assignments', 'store_id')
            || ! Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            return [];
        }

        $now = Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString();

        return DB::table('specialist_store_assignments')
            ->where('specialist_id', $specialistId)
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->whereNull('assigned_to')
                    ->orWhere('assigned_to', '>=', $now);
            })
            ->pluck('store_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function loadStoreOptions(array $storeIds): Collection
    {
        if (empty($storeIds) || ! Schema::hasTable('stores')) {
            return collect();
        }

        return DB::table('stores')
            ->select($this->storeSelectColumns())
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get();
    }

    private function loadVisibleStoreIds(array $assignedStoreIds, array $filters): array
    {
        if (empty($assignedStoreIds) || ! Schema::hasTable('stores')) {
            return [];
        }

        return $this->baseStoreQuery($assignedStoreIds, $filters)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    private function baseStoreQuery(array $storeIds, array $filters = []): QueryBuilder
    {
        $query = DB::table('stores')->whereIn('id', $storeIds);

        if (($filters['store_id'] ?? '') !== '' && in_array((string) $filters['store_id'], $storeIds, true)) {
            $query->where('id', (string) $filters['store_id']);
        }

        if (($filters['region'] ?? '') !== '') {
            $this->applyRegionFilter($query, (string) $filters['region']);
        }

        return $query;
    }

    private function applyRegionFilter(QueryBuilder $query, string $region): void
    {
        if (Schema::hasColumn('stores', 'region_id') && preg_match('/^\d+$/', $region)) {
            $query->where('region_id', (int) $region);

            return;
        }

        $query->where(function (QueryBuilder $scope) use ($region): void {
            $hasSearchableColumn = false;
            foreach (['address', 'name', 'store_code'] as $column) {
                if (! Schema::hasColumn('stores', $column)) {
                    continue;
                }

                $hasSearchableColumn = true;
                $scope->orWhere($column, 'like', '%' . $region . '%');
            }

            if (! $hasSearchableColumn) {
                $scope->whereRaw('1 = 1');
            }
        });
    }

    private function storeSelectColumns(): array
    {
        $columns = ['id'];
        foreach (['store_code', 'name', 'address', 'status', 'is_open', 'sale_commission_rate', 'region_id'] as $column) {
            if (Schema::hasColumn('stores', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function buildStoreMetrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        $total = count($storeIds);
        if ($total === 0 || ! Schema::hasTable('stores')) {
            return [
                'managed' => 0,
                'active' => 0,
                'suspended' => 0,
                'without_orders' => 0,
            ];
        }

        $hasStatus = Schema::hasColumn('stores', 'status');
        $hasIsOpen = Schema::hasColumn('stores', 'is_open');

        $active = DB::table('stores')->whereIn('id', $storeIds);
        if ($hasStatus) {
            $active->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::ACTIVE_STORE_STATUSES);
        }
        if ($hasIsOpen) {
            $active->where('is_open', true);
        }

        $suspended = 0;
        if ($hasStatus || $hasIsOpen) {
            $suspendedQuery = DB::table('stores')->whereIn('id', $storeIds)
                ->where(function (QueryBuilder $query) use ($hasStatus, $hasIsOpen): void {
                    if ($hasStatus) {
                        $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::SUSPENDED_STORE_STATUSES);
                    }
                    if ($hasIsOpen) {
                        $query->orWhere('is_open', false);
                    }
                });
            $suspended = (int) $suspendedQuery->count();
        }

        return [
            'managed' => $total,
            'active' => (int) $active->count(),
            'suspended' => $suspended,
            'without_orders' => $this->countStoresWithoutSuccessfulOrders($storeIds, $from, $to),
        ];
    }

    private function countStoresWithoutSuccessfulOrders(array $storeIds, Carbon $from, Carbon $to): int
    {
        if (empty($storeIds) || ! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return count($storeIds);
        }

        return (int) DB::table('stores')
            ->whereIn('stores.id', $storeIds)
            ->whereNotExists(function (QueryBuilder $query) use ($from, $to): void {
                $query->selectRaw('1')
                    ->from('orders as order_scope')
                    ->whereColumn('order_scope.store_id', 'stores.id');

                $this->applySuccessStatus($query, 'order_scope.status');
                $this->applyOrderDateRange($query, $from, $to, 'order_scope');
            })
            ->count();
    }

    private function buildOrderMetrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        $globalSuccessQuery = DB::table('orders');
        $this->applySuccessStatus($globalSuccessQuery, 'orders.status');
        $this->applyOrderDateRange($globalSuccessQuery, $from, $to, 'orders');

        $specialistQuery = $this->baseOrderQuery($storeIds, $from, $to);
        $revenueColumn = $this->revenueColumn('orders');

        $today = Carbon::now('Asia/Ho_Chi_Minh');
        $todayQuery = $this->baseOrderQuery(
            $storeIds,
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay()
        );

        return [
            'global_successful' => (int) $globalSuccessQuery->count(),
            'specialist_successful' => (int) $specialistQuery->count(),
            'today_successful' => (int) $todayQuery->count(),
            'managed_revenue' => (float) $this->baseOrderQuery($storeIds, $from, $to)->sum($revenueColumn),
        ];
    }

    private function baseOrderQuery(array $storeIds, Carbon $from, Carbon $to): QueryBuilder
    {
        $query = DB::table('orders');

        if (empty($storeIds) || ! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('orders.store_id', $storeIds);
        $this->applySuccessStatus($query, 'orders.status');
        $this->applyOrderDateRange($query, $from, $to, 'orders');

        return $query;
    }

    private function applySuccessStatus(QueryBuilder $query, string $statusColumn): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE({$statusColumn}, ''))"), self::SUCCESS_ORDER_STATUSES);
        }
    }

    private function applyOrderDateRange(QueryBuilder $query, Carbon $from, Carbon $to, string $alias): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'created_at')) {
            return;
        }

        $dateExpression = Schema::hasColumn('orders', 'delivered_at')
            ? 'COALESCE(' . $alias . '.delivered_at, ' . $alias . '.created_at)'
            : $alias . '.created_at';

        $query->whereRaw($dateExpression . ' BETWEEN ? AND ?', [
            $from->toDateTimeString(),
            $to->toDateTimeString(),
        ]);
    }

    private function revenueColumn(string $alias): string
    {
        foreach (['final_amount', 'total_amount', 'total'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                return $alias . '.' . $column;
            }
        }

        return $alias . '.id';
    }

    private function buildTicketMetrics(array $storeIds, Carbon $from, Carbon $to, string $selectedStatus): array
    {
        if (empty($storeIds) || ! Schema::hasTable('tickets')) {
            return [
                'total' => 0,
                'eligible' => 0,
                'held' => 0,
                'cancelled' => 0,
            ];
        }

        $totalQuery = $this->baseTicketQuery($storeIds, $from, $to);
        $this->applySelectedTicketStatus($totalQuery, $selectedStatus);

        $counts = [
            'total' => (int) $totalQuery->count(),
        ];

        foreach (array_keys(self::TICKET_STATUS_GROUPS) as $group) {
            $query = $this->baseTicketQuery($storeIds, $from, $to);
            $this->applySelectedTicketStatus($query, $selectedStatus);
            $this->applyTicketGroupStatus($query, $group);
            $counts[$group] = (int) $query->count();
        }

        return $counts;
    }

    private function baseTicketQuery(array $storeIds, Carbon $from, Carbon $to): QueryBuilder
    {
        $query = DB::table('tickets as t');
        $hasScope = false;

        $query->where(function (QueryBuilder $scope) use ($storeIds, &$hasScope): void {
            if (Schema::hasColumn('tickets', 'source_type') && Schema::hasColumn('tickets', 'source_id')) {
                $hasScope = true;
                $scope->orWhere(function (QueryBuilder $sourceStore) use ($storeIds): void {
                    $sourceStore
                        ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['STORE'])
                        ->whereIn('t.source_id', $storeIds);
                });

                if (
                    Schema::hasTable('orders')
                    && Schema::hasColumn('orders', 'id')
                    && Schema::hasColumn('orders', 'store_id')
                ) {
                    $scope->orWhereExists(function (QueryBuilder $orderSource) use ($storeIds): void {
                        $orderSource->selectRaw('1')
                            ->from('orders as ticket_source_orders')
                            ->whereColumn('ticket_source_orders.id', 't.source_id')
                            ->whereIn('ticket_source_orders.store_id', $storeIds);

                        if (Schema::hasColumn('tickets', 'source_type')) {
                            $orderSource->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['ORDER', 'ORDER_REWARD', 'ORDER_REDEEM']);
                        }
                    });
                }
            }

            if (
                Schema::hasColumn('tickets', 'used_in_order_id')
                && Schema::hasTable('orders')
                && Schema::hasColumn('orders', 'id')
                && Schema::hasColumn('orders', 'store_id')
            ) {
                $hasScope = true;
                $scope->orWhereExists(function (QueryBuilder $usedOrder) use ($storeIds): void {
                    $usedOrder->selectRaw('1')
                        ->from('orders as ticket_used_orders')
                        ->whereColumn('ticket_used_orders.id', 't.used_in_order_id')
                        ->whereIn('ticket_used_orders.store_id', $storeIds);
                });
            }
        });

        if (! $hasScope) {
            $query->whereRaw('1 = 0');
        }

        $this->applyTicketDateRange($query, $from, $to);

        return $query;
    }

    private function applyTicketDateRange(QueryBuilder $query, Carbon $from, Carbon $to): void
    {
        $dateColumn = null;
        foreach (['created_at', 'purchased_at', 'used_at'] as $column) {
            if (Schema::hasColumn('tickets', $column)) {
                $dateColumn = 't.' . $column;
                break;
            }
        }

        if ($dateColumn === null) {
            return;
        }

        $query->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()]);
    }

    private function applySelectedTicketStatus(QueryBuilder $query, string $selectedStatus): void
    {
        if ($selectedStatus === 'all') {
            return;
        }

        $this->applyTicketGroupStatus($query, $selectedStatus);
    }

    private function applyTicketGroupStatus(QueryBuilder $query, string $group): void
    {
        if (! Schema::hasColumn('tickets', 'status') || ! isset(self::TICKET_STATUS_GROUPS[$group])) {
            return;
        }

        $query->whereIn(DB::raw("UPPER(COALESCE(t.status, ''))"), self::TICKET_STATUS_GROUPS[$group]);
    }

    private function buildIncomeMetrics(
        User $specialist,
        array $storeIds,
        Carbon $from,
        Carbon $to,
        array $estimatedCommissionByStore
    ): array {
        $source = 'calculated_from_orders';
        $tentative = array_sum($estimatedCommissionByStore);

        if (Schema::hasTable('specialist_ticket_earnings')) {
            $source = 'specialist_ticket_earnings';
            $specialistId = (int) $specialist->id;
            $tentative = $this->sumSpecialistEarnings($specialistId, $storeIds, $from, $to, [
                'estimated',
                'draft',
                'calculated',
                'pending',
                'awaiting_approval',
                'waiting_approval',
                'pending_settlement',
            ]);
        }

        $pending = $this->sumSpecialistSettlements((int) $specialist->id, $storeIds, $from, $to, [
            'draft',
            'pending',
            'awaiting_approval',
            'waiting_approval',
            'pending_settlement',
        ]);
        $approved = $this->sumSpecialistSettlements((int) $specialist->id, $storeIds, $from, $to, ['approved']);
        $paid = $this->sumSpecialistSettlements((int) $specialist->id, $storeIds, $from, $to, [
            'paid',
            'settled',
            'completed',
        ]);

        if ($pending <= 0 && Schema::hasTable('specialist_ticket_earnings')) {
            $pending = $this->sumSpecialistEarnings((int) $specialist->id, $storeIds, $from, $to, ['pending', 'awaiting_approval', 'waiting_approval', 'pending_settlement']);
        }
        if ($approved <= 0 && Schema::hasTable('specialist_ticket_earnings')) {
            $approved = $this->sumSpecialistEarnings((int) $specialist->id, $storeIds, $from, $to, ['approved']);
        }
        if ($paid <= 0 && Schema::hasTable('specialist_ticket_earnings')) {
            $paid = $this->sumSpecialistEarnings((int) $specialist->id, $storeIds, $from, $to, ['paid', 'settled', 'completed']);
        }

        return [
            'tentative' => round((float) $tentative, 2),
            'pending' => round((float) $pending, 2),
            'approved' => round((float) $approved, 2),
            'paid' => round((float) $paid, 2),
            'source' => $source,
        ];
    }

    private function sumSpecialistEarnings(int $specialistId, array $storeIds, Carbon $from, Carbon $to, array $statuses): float
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return 0.0;
        }

        $amountColumn = $this->firstExistingColumn('specialist_ticket_earnings', [
            'earning_amount',
            'amount',
            'commission_amount',
            'estimated_amount',
        ]);
        if ($amountColumn === null) {
            return 0.0;
        }

        $query = DB::table('specialist_ticket_earnings')
            ->where('specialist_id', $specialistId);

        if (! empty($storeIds) && Schema::hasColumn('specialist_ticket_earnings', 'store_id')) {
            $query->whereIn('store_id', $storeIds);
        }

        if (Schema::hasColumn('specialist_ticket_earnings', 'status') && ! empty($statuses)) {
            $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $statuses);
        }

        $dateColumn = $this->firstExistingColumn('specialist_ticket_earnings', ['earned_at', 'created_at']);
        if ($dateColumn !== null) {
            $query->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()]);
        }

        return (float) ($query->sum($amountColumn) ?? 0);
    }

    private function sumSpecialistSettlements(int $specialistId, array $storeIds, Carbon $from, Carbon $to, array $statuses): float
    {
        if (! Schema::hasTable('specialist_settlements')) {
            return 0.0;
        }

        $amountColumn = $this->firstExistingColumn('specialist_settlements', ['amount', 'total_amount', 'payable_amount']);
        if ($amountColumn === null) {
            return 0.0;
        }

        $query = DB::table('specialist_settlements')
            ->where('specialist_id', $specialistId);

        if (! empty($storeIds) && Schema::hasColumn('specialist_settlements', 'store_id')) {
            $query->whereIn('store_id', $storeIds);
        }

        if (Schema::hasColumn('specialist_settlements', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $statuses);
        }

        if (Schema::hasColumn('specialist_settlements', 'period_from') && Schema::hasColumn('specialist_settlements', 'period_to')) {
            $query->where(function (QueryBuilder $period) use ($from, $to): void {
                $period->whereNull('period_from')
                    ->orWhere('period_from', '<=', $to->toDateString());
            })->where(function (QueryBuilder $period) use ($from): void {
                $period->whereNull('period_to')
                    ->orWhere('period_to', '>=', $from->toDateString());
            });
        } elseif (($dateColumn = $this->firstExistingColumn('specialist_settlements', ['created_at', 'approved_at', 'paid_at'])) !== null) {
            $query->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()]);
        }

        return (float) ($query->sum($amountColumn) ?? 0);
    }

    private function estimateCommissionByStore(array $storeIds, Carbon $from, Carbon $to): array
    {
        if (empty($storeIds) || ! Schema::hasTable('stores')) {
            return [];
        }

        $ratesQuery = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->select('id');

        if (Schema::hasColumn('stores', 'sale_commission_rate')) {
            $ratesQuery->addSelect('sale_commission_rate');
        } else {
            $ratesQuery->selectRaw('0 as sale_commission_rate');
        }

        $rates = $ratesQuery
            ->get()
            ->mapWithKeys(static fn ($store): array => [(string) $store->id => (float) ($store->sale_commission_rate ?? 0)])
            ->all();

        if (empty($rates)) {
            return [];
        }

        $ticketValueByStore = $this->ticketTransactionValueByStore($storeIds, $from, $to);
        if (empty($ticketValueByStore)) {
            $ticketValueByStore = $this->orderItemTicketValueByStore($storeIds, $from, $to);
        }

        $commission = [];
        foreach ($rates as $storeId => $rate) {
            $ticketValue = (float) ($ticketValueByStore[$storeId] ?? 0);
            $commission[$storeId] = round($ticketValue * ($rate / 100), 2);
        }

        return $commission;
    }

    private function ticketTransactionValueByStore(array $storeIds, Carbon $from, Carbon $to): array
    {
        if (
            ! Schema::hasTable('store_ticket_transactions')
            || ! Schema::hasColumn('store_ticket_transactions', 'store_id')
            || ! Schema::hasColumn('store_ticket_transactions', 'total_debit_amount')
        ) {
            return [];
        }

        $dateColumn = Schema::hasColumn('store_ticket_transactions', 'timestamp') ? 'timestamp' : 'created_at';
        if (! Schema::hasColumn('store_ticket_transactions', $dateColumn)) {
            return [];
        }

        $query = DB::table('store_ticket_transactions')
            ->whereIn('store_id', $storeIds)
            ->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()]);

        if (Schema::hasColumn('store_ticket_transactions', 'event_type')) {
            $query->where('event_type', 'ORDER_DELIVERED');
        }

        return $query
            ->selectRaw('store_id, COALESCE(SUM(total_debit_amount), 0) as total_value')
            ->groupBy('store_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->store_id => (float) $row->total_value])
            ->all();
    }

    private function orderItemTicketValueByStore(array $storeIds, Carbon $from, Carbon $to): array
    {
        if (
            ! Schema::hasTable('orders')
            || ! Schema::hasTable('order_items')
            || ! Schema::hasColumn('orders', 'id')
            || ! Schema::hasColumn('orders', 'store_id')
            || ! Schema::hasColumn('order_items', 'order_id')
            || ! Schema::hasColumn('order_items', 'quantity')
        ) {
            return [];
        }

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.store_id', $storeIds);

        $this->applySuccessStatus($query, 'o.status');
        $this->applyOrderDateRange($query, $from, $to, 'o');

        $hasProducts = Schema::hasTable('products') && Schema::hasColumn('order_items', 'product_id') && Schema::hasColumn('products', 'id');
        $hasVariants = Schema::hasTable('product_variants') && Schema::hasColumn('order_items', 'variant_id') && Schema::hasColumn('product_variants', 'id');

        if ($hasProducts) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }
        if ($hasVariants) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        $rewardCandidates = [];
        if (Schema::hasColumn('order_items', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'COALESCE(oi.ticket_reward_quantity, 0)';
        }
        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'COALESCE(pv.ticket_reward_quantity, 0)';
        }
        if ($hasProducts && Schema::hasColumn('products', 'ticket_reward_quantity')) {
            $rewardCandidates[] = 'COALESCE(p.ticket_reward_quantity, 0)';
        }

        if (empty($rewardCandidates)) {
            return [];
        }

        $unitPriceExpression = $this->orderItemUnitPriceExpression();
        $ticketTypeExpression = "''";
        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code') && $hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $ticketTypeExpression = "COALESCE(pv.ticket_type_code, p.ticket_type_code, '')";
        } elseif ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code')) {
            $ticketTypeExpression = "COALESCE(pv.ticket_type_code, '')";
        } elseif ($hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $ticketTypeExpression = "COALESCE(p.ticket_type_code, '')";
        }

        $rows = $query
            ->selectRaw('o.store_id as store_id')
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw($unitPriceExpression . ' as unit_price')
            ->selectRaw('GREATEST(' . implode(', ', $rewardCandidates) . ') as ticket_reward_quantity')
            ->selectRaw($ticketTypeExpression . ' as ticket_type_code')
            ->get();

        $ticketTypePrices = collect($this->ticketPricingService->getTicketTypes())
            ->mapWithKeys(function (array $type): array {
                $code = strtoupper(trim((string) ($type['code'] ?? '')));

                return $code === '' ? [] : [$code => (float) ($type['price'] ?? 0)];
            })
            ->all();

        $totals = [];
        foreach ($rows as $row) {
            $storeId = (string) ($row->store_id ?? '');
            if ($storeId === '') {
                continue;
            }

            $quantity = max(0, (int) ($row->quantity ?? 0));
            $rewardPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));
            $ticketCount = $quantity * $rewardPerUnit;
            if ($ticketCount < 1) {
                continue;
            }

            $ticketTypeCode = strtoupper(trim((string) ($row->ticket_type_code ?? '')));
            $unitPrice = (float) ($row->unit_price ?? 0);
            $ticketPrice = $ticketTypeCode !== '' && array_key_exists($ticketTypeCode, $ticketTypePrices)
                ? (float) $ticketTypePrices[$ticketTypeCode]
                : (float) $this->ticketPricingService->calculatePerTicketValue($unitPrice);

            $totals[$storeId] = (float) (($totals[$storeId] ?? 0) + ($ticketCount * max(0, $ticketPrice)));
        }

        return $totals;
    }

    private function orderItemUnitPriceExpression(): string
    {
        $candidates = [];
        foreach (['unit_price', 'price'] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                $candidates[] = 'oi.' . $column;
            }
        }

        if (Schema::hasColumn('order_items', 'total_price')) {
            $candidates[] = 'CASE WHEN COALESCE(oi.quantity, 0) > 0 THEN oi.total_price / oi.quantity ELSE 0 END';
        }

        if (empty($candidates)) {
            return '0';
        }

        return 'COALESCE(' . implode(', ', $candidates) . ', 0)';
    }

    private function buildStoreRows(array $storeIds, Carbon $from, Carbon $to, array $commissionByStore): Collection
    {
        if (empty($storeIds) || ! Schema::hasTable('stores')) {
            return collect();
        }

        $stores = DB::table('stores')
            ->select($this->storeSelectColumns())
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get();

        $orderAggregates = $this->orderAggregatesByStore($storeIds, $from, $to);

        return $stores->map(function ($store) use ($orderAggregates, $commissionByStore): array {
            $storeId = (string) $store->id;
            $orders = $orderAggregates[$storeId] ?? ['count' => 0, 'revenue' => 0.0];

            return [
                'id' => $storeId,
                'store_code' => (string) ($store->store_code ?? ''),
                'name' => (string) ($store->name ?? 'N/A'),
                'status' => (string) ($store->status ?? ''),
                'is_open' => property_exists($store, 'is_open') ? (bool) $store->is_open : true,
                'orders_count' => (int) $orders['count'],
                'revenue' => (float) $orders['revenue'],
                'commission_rate' => (float) ($store->sale_commission_rate ?? 0),
                'estimated_income' => (float) ($commissionByStore[$storeId] ?? 0),
            ];
        });
    }

    private function orderAggregatesByStore(array $storeIds, Carbon $from, Carbon $to): array
    {
        if (empty($storeIds)) {
            return [];
        }

        $query = $this->baseOrderQuery($storeIds, $from, $to);
        $revenueColumn = $this->revenueColumn('orders');

        return $query
            ->selectRaw('orders.store_id as store_id')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(' . $revenueColumn . '), 0) as revenue_total')
            ->groupBy('orders.store_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->store_id => [
                    'count' => (int) $row->orders_count,
                    'revenue' => (float) $row->revenue_total,
                ],
            ])
            ->all();
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
