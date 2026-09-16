<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialistStoreInsightService
{
    private const SUCCESS_ORDER_STATUSES = ['delivered', 'completed', 'call_completed'];
    private const CANCELLED_ORDER_STATUSES = ['cancelled', 'canceled'];
    private const TICKET_STATUS_GROUPS = [
        'eligible' => ['ELIGIBLE', 'AVAILABLE', 'USED_FOR_ORDER', 'USED_IN_ROOM'],
        'held' => ['HELD', 'DISPUTED', 'LOCKED'],
        'cancelled' => ['CANCELLED', 'CANCELED', 'EXPIRED', 'REFUNDED'],
    ];

    public function __construct(private readonly TicketPricingService $ticketPricingService)
    {
    }

    public function build(Store $store): array
    {
        $storeId = (string) $store->id;
        $orders = $this->buildOrderMetrics($storeId);
        $tickets = $this->buildTicketMetrics($storeId);
        $income = $this->resolveStoreIncome($store, $storeId);

        return [
            'orders' => $orders,
            'tickets' => $tickets,
            'income' => $income,
            'rates' => [
                'cancel_rate' => $orders['total'] > 0
                    ? round(($orders['cancelled'] / $orders['total']) * 100, 2)
                    : 0.0,
                'held_ticket_rate' => $tickets['total'] > 0
                    ? round(($tickets['held'] / $tickets['total']) * 100, 2)
                    : 0.0,
            ],
        ];
    }

    public function storeHasHeldTickets(string $storeId): bool
    {
        return $this->baseTicketQuery($storeId)
            ->whereIn(DB::raw("UPPER(COALESCE(t.status, ''))"), self::TICKET_STATUS_GROUPS['held'])
            ->exists();
    }

    private function buildOrderMetrics(string $storeId): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return [
                'total' => 0,
                'successful' => 0,
                'cancelled' => 0,
                'revenue' => 0.0,
            ];
        }

        $total = (int) DB::table('orders')->where('store_id', $storeId)->count();
        $successfulQuery = DB::table('orders')->where('store_id', $storeId);
        $this->applyOrderStatus($successfulQuery, self::SUCCESS_ORDER_STATUSES);

        $cancelledQuery = DB::table('orders')->where('store_id', $storeId);
        $this->applyOrderStatus($cancelledQuery, self::CANCELLED_ORDER_STATUSES);

        $revenueQuery = DB::table('orders')->where('store_id', $storeId);
        $this->applyOrderStatus($revenueQuery, self::SUCCESS_ORDER_STATUSES);

        return [
            'total' => $total,
            'successful' => (int) $successfulQuery->count(),
            'cancelled' => (int) $cancelledQuery->count(),
            'revenue' => (float) $revenueQuery->sum($this->revenueColumn()),
        ];
    }

    private function applyOrderStatus(QueryBuilder $query, array $statuses): void
    {
        if (! Schema::hasColumn('orders', 'status')) {
            return;
        }

        $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $statuses);
    }

    private function revenueColumn(): string
    {
        foreach (['final_amount', 'total_amount', 'total'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                return $column;
            }
        }

        return 'id';
    }

    private function buildTicketMetrics(string $storeId): array
    {
        if (! Schema::hasTable('tickets')) {
            return [
                'total' => 0,
                'eligible' => 0,
                'held' => 0,
                'cancelled' => 0,
            ];
        }

        $metrics = [
            'total' => (int) $this->baseTicketQuery($storeId)->count(),
        ];

        foreach (array_keys(self::TICKET_STATUS_GROUPS) as $group) {
            $metrics[$group] = (int) $this->baseTicketQuery($storeId)
                ->whereIn(DB::raw("UPPER(COALESCE(t.status, ''))"), self::TICKET_STATUS_GROUPS[$group])
                ->count();
        }

        return $metrics;
    }

    private function baseTicketQuery(string $storeId): QueryBuilder
    {
        $query = DB::table('tickets as t');
        $hasScope = false;

        $query->where(function (QueryBuilder $scope) use ($storeId, &$hasScope): void {
            if (Schema::hasColumn('tickets', 'source_type') && Schema::hasColumn('tickets', 'source_id')) {
                $hasScope = true;
                $scope->orWhere(function (QueryBuilder $sourceStore) use ($storeId): void {
                    $sourceStore
                        ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['STORE'])
                        ->where('t.source_id', $storeId);
                });

                if ($this->canScopeTicketsByOrder()) {
                    $scope->orWhereExists(function (QueryBuilder $orderSource) use ($storeId): void {
                        $orderSource->selectRaw('1')
                            ->from('orders as ticket_source_orders')
                            ->whereColumn('ticket_source_orders.id', 't.source_id')
                            ->where('ticket_source_orders.store_id', $storeId);
                    });
                }
            }

            if (Schema::hasColumn('tickets', 'used_in_order_id') && $this->canScopeTicketsByOrder()) {
                $hasScope = true;
                $scope->orWhereExists(function (QueryBuilder $usedOrder) use ($storeId): void {
                    $usedOrder->selectRaw('1')
                        ->from('orders as ticket_used_orders')
                        ->whereColumn('ticket_used_orders.id', 't.used_in_order_id')
                        ->where('ticket_used_orders.store_id', $storeId);
                });
            }

            if (
                Schema::hasTable('order_tickets')
                && Schema::hasColumn('order_tickets', 'ticket_id')
                && Schema::hasColumn('order_tickets', 'order_id')
                && $this->canScopeTicketsByOrder()
            ) {
                $hasScope = true;
                $scope->orWhereExists(function (QueryBuilder $orderTicket) use ($storeId): void {
                    $orderTicket->selectRaw('1')
                        ->from('order_tickets as ot')
                        ->join('orders as ticket_order_links', 'ticket_order_links.id', '=', 'ot.order_id')
                        ->whereColumn('ot.ticket_id', 't.id')
                        ->where('ticket_order_links.store_id', $storeId);
                });
            }
        });

        if (! $hasScope) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function canScopeTicketsByOrder(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');
    }

    private function resolveStoreIncome(Store $store, string $storeId): array
    {
        $specialistId = (int) ($store->sale_id ?? 0);
        $fromEarnings = $specialistId > 0
            ? $this->sumSpecialistTicketEarnings($specialistId, $storeId)
            : 0.0;

        $estimated = $this->estimateIncomeFromTicketValue($store, $storeId);

        return [
            'amount' => round($fromEarnings > 0 ? $fromEarnings : $estimated, 2),
            'source' => $fromEarnings > 0 ? 'specialist_ticket_earnings' : 'calculated_from_orders',
        ];
    }

    private function sumSpecialistTicketEarnings(int $specialistId, string $storeId): float
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

        if (Schema::hasColumn('specialist_ticket_earnings', 'store_id')) {
            $query->where('store_id', $storeId);
        }

        return (float) ($query->sum($amountColumn) ?? 0);
    }

    private function estimateIncomeFromTicketValue(Store $store, string $storeId): float
    {
        $rate = (float) ($store->sale_commission_rate ?? 0);
        if ($rate <= 0) {
            return 0.0;
        }

        $ticketValue = $this->storeTicketTransactionValue($storeId);
        if ($ticketValue <= 0) {
            $ticketValue = $this->orderItemTicketValue($storeId);
        }

        return $ticketValue * ($rate / 100);
    }

    private function storeTicketTransactionValue(string $storeId): float
    {
        if (
            ! Schema::hasTable('store_ticket_transactions')
            || ! Schema::hasColumn('store_ticket_transactions', 'store_id')
            || ! Schema::hasColumn('store_ticket_transactions', 'total_debit_amount')
        ) {
            return 0.0;
        }

        $query = DB::table('store_ticket_transactions')
            ->where('store_id', $storeId);

        if (Schema::hasColumn('store_ticket_transactions', 'event_type')) {
            $query->where('event_type', 'ORDER_DELIVERED');
        }

        return (float) ($query->sum('total_debit_amount') ?? 0);
    }

    private function orderItemTicketValue(string $storeId): float
    {
        if (
            ! Schema::hasTable('orders')
            || ! Schema::hasTable('order_items')
            || ! Schema::hasColumn('orders', 'id')
            || ! Schema::hasColumn('orders', 'store_id')
            || ! Schema::hasColumn('order_items', 'order_id')
            || ! Schema::hasColumn('order_items', 'quantity')
        ) {
            return 0.0;
        }

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.store_id', $storeId);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(o.status, ''))"), self::SUCCESS_ORDER_STATUSES);
        }

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
            return 0.0;
        }

        $ticketTypeExpression = "''";
        if ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code') && $hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $ticketTypeExpression = "COALESCE(pv.ticket_type_code, p.ticket_type_code, '')";
        } elseif ($hasVariants && Schema::hasColumn('product_variants', 'ticket_type_code')) {
            $ticketTypeExpression = "COALESCE(pv.ticket_type_code, '')";
        } elseif ($hasProducts && Schema::hasColumn('products', 'ticket_type_code')) {
            $ticketTypeExpression = "COALESCE(p.ticket_type_code, '')";
        }

        $rows = $query
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw($this->orderItemUnitPriceExpression() . ' as unit_price')
            ->selectRaw('GREATEST(' . implode(', ', $rewardCandidates) . ') as ticket_reward_quantity')
            ->selectRaw($ticketTypeExpression . ' as ticket_type_code')
            ->get();

        $ticketTypePrices = collect($this->ticketPricingService->getTicketTypes())
            ->mapWithKeys(function (array $type): array {
                $code = strtoupper(trim((string) ($type['code'] ?? '')));

                return $code === '' ? [] : [$code => (float) ($type['price'] ?? 0)];
            })
            ->all();

        $total = 0.0;
        foreach ($rows as $row) {
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

            $total += $ticketCount * max(0, $ticketPrice);
        }

        return $total;
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

        return empty($candidates)
            ? '0'
            : 'COALESCE(' . implode(', ', $candidates) . ', 0)';
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
