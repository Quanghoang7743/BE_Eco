<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConsolidatedRevenueController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'string', 'max:36'],
            'region' => ['nullable', 'string', 'max:255'],
            'granularity' => ['nullable', 'in:day,week,month'],
            'ticket_unit_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $granularity = $filters['granularity'] ?? 'day';
        $ticketUnitValue = (float) ($filters['ticket_unit_value'] ?? 5000);

        $hasOrderStoreId = true;
        $hasOrderCreatedAt = true;
        $hasOrderTotalTicketsUsed = true;
        $hasOrderTicketsTable = true;
        $hasStoresTable = true;

        $orderAmountColumn = 'final_amount';

        $batchRevenueQuery = DB::table('ticket_batches')->where('status', 'completed');
        $ticketStatsQuery = DB::table('tickets');
        $ordersRevenueQuery = DB::table('orders')->whereIn('status', ['delivered', 'completed']);
        $roomPrizeQuery = DB::table('rooms');
        $ledgerQuery = DB::table('ticket_ledger');

        $this->applyDateFilter($batchRevenueQuery, 'created_at', $filters);
        $this->applyDateFilter($ticketStatsQuery, 'created_at', $filters);
        if ($hasOrderCreatedAt) {
            $this->applyDateFilter($ordersRevenueQuery, 'created_at', $filters);
        }
        $this->applyDateFilter($roomPrizeQuery, 'created_at', $filters);
        $this->applyDateFilter($ledgerQuery, 'created_at', $filters);

        if (!empty($filters['store_id']) && $hasOrderStoreId) {
            $ordersRevenueQuery->where('store_id', $filters['store_id']);
        }

        $ticketBatchSummary = (clone $batchRevenueQuery)
            ->selectRaw('COALESCE(SUM(quantity), 0) as tickets_sold')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as ticket_revenue')
            ->first();

        $orderSummary = (clone $ordersRevenueQuery)
            ->selectRaw('COUNT(*) as delivered_orders')
            ->selectRaw($hasOrderTotalTicketsUsed ? 'COALESCE(SUM(total_tickets_used), 0) as tickets_used_for_orders' : '0 as tickets_used_for_orders')
            ->selectRaw($orderAmountColumn ? 'COALESCE(SUM(' . $orderAmountColumn . '), 0) as order_revenue' : '0 as order_revenue')
            ->first();

        $ticketsUsedFromOrderTickets = null;
        if (!$hasOrderTotalTicketsUsed && $hasOrderTicketsTable) {
            $ticketsUsedQuery = DB::table('order_tickets as ot')
                ->join('orders as o', 'o.id', '=', 'ot.order_id')
                ->whereIn('o.status', ['delivered', 'completed']);

            if (!empty($filters['store_id']) && $hasOrderStoreId) {
                $ticketsUsedQuery->where('o.store_id', $filters['store_id']);
            }

            $this->applyDateFilter($ticketsUsedQuery, 'o.created_at', $filters);

            $ticketsUsedFromOrderTickets = (int) $ticketsUsedQuery->count();
        }

        if ($hasOrderStoreId) {
            $storeRevenueQuery = DB::table('orders as o')
                ->whereIn('o.status', ['delivered', 'completed']);

            if ($hasStoresTable) {
                $storeRevenueQuery->leftJoin('stores as s', 's.id', '=', 'o.store_id');
            }

            if ($hasOrderCreatedAt) {
                $this->applyDateFilter($storeRevenueQuery, 'o.created_at', $filters);
            }

            if (!empty($filters['store_id'])) {
                $storeRevenueQuery->where('o.store_id', $filters['store_id']);
            }

            if (!empty($filters['region']) && $hasStoresTable) {
                $region = trim((string) $filters['region']);
                $storeRevenueQuery->where(function ($q) use ($region) {
                    $q->where('s.address', 'like', '%' . $region . '%')
                        ->orWhere('s.name', 'like', '%' . $region . '%')
                        ->orWhere('s.store_code', 'like', '%' . $region . '%');
                });
            }

            $storeRows = (clone $storeRevenueQuery)
                ->selectRaw('o.store_id')
                ->selectRaw($hasStoresTable ? "COALESCE(NULLIF(s.store_code, ''), 'UNKNOWN') as store_code" : "COALESCE(NULLIF(o.store_id, ''), 'UNKNOWN') as store_code")
                ->selectRaw($hasStoresTable ? "COALESCE(NULLIF(s.name, ''), 'Khong xac dinh cua hang') as store_name" : "'Khong xac dinh cua hang' as store_name")
                ->selectRaw('COUNT(o.id) as total_orders')
                ->selectRaw(
                    $hasOrderTotalTicketsUsed
                        ? 'COALESCE(SUM(o.total_tickets_used), 0) as total_tickets_used'
                        : ($hasOrderTicketsTable
                            ? 'COALESCE(SUM((SELECT COUNT(*) FROM order_tickets ot WHERE ot.order_id = o.id)), 0) as total_tickets_used'
                            : '0 as total_tickets_used')
                )
                ->selectRaw($orderAmountColumn ? 'COALESCE(SUM(o.' . $orderAmountColumn . '), 0) as total_revenue' : '0 as total_revenue')
                ->groupBy('o.store_id')
                ->when($hasStoresTable, function ($query) {
                    $query->groupBy('s.store_code', 's.name');
                })
                ->orderByDesc('total_revenue')
                ->get();
        } else {
            $storeRows = collect([
                (object) [
                    'store_id' => null,
                    'store_code' => 'UNKNOWN',
                    'store_name' => 'Khong xac dinh cua hang',
                    'total_orders' => (int) ($orderSummary->delivered_orders ?? 0),
                    'total_tickets_used' => (int) ($ticketsUsedFromOrderTickets ?? $orderSummary->tickets_used_for_orders ?? 0),
                    'total_revenue' => (float) ($orderSummary->order_revenue ?? 0),
                ],
            ]);
        }

        $ticketStatusSummary = (clone $ticketStatsQuery)
            ->selectRaw('COUNT(*) as total_tickets_issued')
            ->selectRaw("SUM(CASE WHEN status = 'USED_FOR_ORDER' OR used_in_order_id IS NOT NULL THEN 1 ELSE 0 END) as tickets_used_for_orders_status")
            ->selectRaw("SUM(CASE WHEN status = 'USED_IN_ROOM' OR used_in_room_id IS NOT NULL THEN 1 ELSE 0 END) as tickets_used_for_rooms")
            ->selectRaw("SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END) as tickets_expired")
            ->selectRaw("SUM(CASE WHEN status = 'LOCKED' THEN 1 ELSE 0 END) as tickets_locked")
            ->selectRaw("SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END) as tickets_available")
            ->first();

        $prizeCost = (float) ((clone $roomPrizeQuery)
            ->whereNotNull('prize_value')
            ->sum('prize_value'));

        $ledgerSummary = (clone $ledgerQuery)
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_change > 0 THEN amount_change ELSE 0 END), 0) as ledger_in')
            ->selectRaw('COALESCE(ABS(SUM(CASE WHEN amount_change < 0 THEN amount_change ELSE 0 END)), 0) as ledger_out')
            ->selectRaw('COALESCE(SUM(amount_change), 0) as ledger_net')
            ->first();

        $ticketRevenue = (float) ($ticketBatchSummary->ticket_revenue ?? 0);
        $ticketsSold = (int) ($ticketBatchSummary->tickets_sold ?? 0);
        $orderRevenue = (float) ($orderSummary->order_revenue ?? 0);

        $totalTicketsIssued = (int) ($ticketStatusSummary->total_tickets_issued ?? 0);
        $ticketsUsedForOrders = (int) max(
            (int) ($ticketStatusSummary->tickets_used_for_orders_status ?? 0),
            (int) ($ticketsUsedFromOrderTickets ?? $orderSummary->tickets_used_for_orders ?? 0)
        );
        $ticketsUsedForRooms = (int) ($ticketStatusSummary->tickets_used_for_rooms ?? 0);
        $ticketsExpired = (int) ($ticketStatusSummary->tickets_expired ?? 0);
        $ticketsLocked = (int) ($ticketStatusSummary->tickets_locked ?? 0);
        $ticketsAvailable = (int) ($ticketStatusSummary->tickets_available ?? 0);

        $circulatingValue = $ticketsAvailable * $ticketUnitValue;
        $coverageRatio = $prizeCost > 0 ? $ticketRevenue / $prizeCost : null;

        $periodExpression = $this->periodExpression('created_at', $granularity);

        $ticketTrend = DB::table('ticket_batches')->where('status', 'completed');
        $this->applyDateFilter($ticketTrend, 'created_at', $filters);
        $ticketTrendRows = $ticketTrend
            ->selectRaw($periodExpression . ' as period_key')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as ticket_revenue')
            ->groupByRaw($periodExpression)
            ->orderBy('period_key')
            ->get();

        $orderTrend = DB::table('orders')->whereIn('status', ['delivered', 'completed']);

        if ($hasOrderCreatedAt) {
            $this->applyDateFilter($orderTrend, 'created_at', $filters);
        }

        if (!empty($filters['store_id']) && $hasOrderStoreId) {
            $orderTrend->where('store_id', $filters['store_id']);
        }

        if ($hasOrderCreatedAt) {
            $orderTrendRows = $orderTrend
                ->selectRaw($periodExpression . ' as period_key')
                ->selectRaw($orderAmountColumn ? 'COALESCE(SUM(' . $orderAmountColumn . '), 0) as order_revenue' : '0 as order_revenue')
                ->groupByRaw($periodExpression)
                ->orderBy('period_key')
                ->get();
        } else {
            $orderTrendRows = collect();
        }

        $trends = $this->mergeTrendRows($ticketTrendRows, $orderTrendRows);

        $storeRows = $storeRows->map(function ($row) use ($ticketUnitValue) {
            $row->converted_value = (float) $row->total_tickets_used * $ticketUnitValue;

            return $row;
        });

        $mismatchFlag = abs($ticketsSold - (int) ($ledgerSummary->ledger_in ?? 0)) > 0;

        return view('admin.reports.consolidated-revenue', [
            'filters' => [
                'from_date' => $filters['from_date'] ?? null,
                'to_date' => $filters['to_date'] ?? null,
                'store_id' => $filters['store_id'] ?? null,
                'region' => $filters['region'] ?? null,
                'granularity' => $granularity,
                'ticket_unit_value' => $ticketUnitValue,
            ],
            'summary' => [
                'ticket_revenue' => $ticketRevenue,
                'order_revenue' => $orderRevenue,
                'total_revenue' => $ticketRevenue + $orderRevenue,
                'tickets_sold' => $ticketsSold,
                'total_tickets_issued' => $totalTicketsIssued,
                'tickets_used_for_orders' => $ticketsUsedForOrders,
                'tickets_used_for_rooms' => $ticketsUsedForRooms,
                'tickets_expired' => $ticketsExpired,
                'tickets_locked' => $ticketsLocked,
                'tickets_available' => $ticketsAvailable,
                'circulating_value' => $circulatingValue,
                'delivered_orders' => (int) ($orderSummary->delivered_orders ?? 0),
            ],
            'costCoverage' => [
                'ticket_revenue' => $ticketRevenue,
                'prize_cost' => $prizeCost,
                'ratio' => $coverageRatio,
            ],
            'reconciliation' => [
                'ledger_in' => (int) ($ledgerSummary->ledger_in ?? 0),
                'ledger_out' => (int) ($ledgerSummary->ledger_out ?? 0),
                'ledger_net' => (int) ($ledgerSummary->ledger_net ?? 0),
                'mismatch_flag' => $mismatchFlag,
            ],
            'storeRows' => $storeRows,
            'trends' => $trends,
        ]);
    }

    public function datatable(Request $request)
    {
        $validated = $request->validate([
            'draw' => ['required', 'integer'],
            'start' => ['required', 'integer'],
            'length' => ['required', 'integer'],
            'order' => ['nullable', 'array'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'string', 'max:36'],
            'region' => ['nullable', 'string', 'max:255'],
            'ticket_unit_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $ticketUnitValue = (float) ($validated['ticket_unit_value'] ?? 5000);

        $hasOrderStoreId = true;
        $hasOrderCreatedAt = true;
        $hasOrderTotalTicketsUsed = true;
        $hasOrderTicketsTable = true;
        $hasStoresTable = true;

        $orderAmountColumn = 'final_amount';

        if (!$hasOrderStoreId) {
            $ordersRevenueQuery = DB::table('orders')->whereIn('status', ['delivered', 'completed']);
            if ($hasOrderCreatedAt) {
                $this->applyDateFilter($ordersRevenueQuery, 'created_at', $validated);
            }

            $orderSummary = (clone $ordersRevenueQuery)
                ->selectRaw('COUNT(*) as delivered_orders')
                ->selectRaw($hasOrderTotalTicketsUsed ? 'COALESCE(SUM(total_tickets_used), 0) as tickets_used_for_orders' : '0 as tickets_used_for_orders')
                ->selectRaw($orderAmountColumn ? 'COALESCE(SUM(' . $orderAmountColumn . '), 0) as order_revenue' : '0 as order_revenue')
                ->first();

            $ticketsUsedFromOrderTickets = null;
            if (!$hasOrderTotalTicketsUsed && $hasOrderTicketsTable) {
                $ticketsUsedQuery = DB::table('order_tickets as ot')
                    ->join('orders as o', 'o.id', '=', 'ot.order_id')
                    ->whereIn('o.status', ['delivered', 'completed']);

                $this->applyDateFilter($ticketsUsedQuery, 'o.created_at', $validated);

                $ticketsUsedFromOrderTickets = (int) $ticketsUsedQuery->count();
            }

            $totalOrders = (int) ($orderSummary->delivered_orders ?? 0);
            $totalTicketsUsed = (int) ($ticketsUsedFromOrderTickets ?? $orderSummary->tickets_used_for_orders ?? 0);
            $totalRevenue = (float) ($orderSummary->order_revenue ?? 0);
            $convertedValue = (float) $totalTicketsUsed * $ticketUnitValue;

            return response()->json([
                'draw' => $validated['draw'],
                'recordsTotal' => 1,
                'recordsFiltered' => 1,
                'data' => [[
                    'store_info' => '<div class="fw-semibold">Khong xac dinh cua hang</div><small class="text-muted">UNKNOWN | N/A</small>',
                    'total_orders' => number_format($totalOrders),
                    'total_tickets_used' => number_format($totalTicketsUsed),
                    'converted_value' => number_format($convertedValue, 0, ',', '.') . ' VND',
                    'total_revenue' => number_format($totalRevenue, 0, ',', '.') . ' VND',
                ]],
            ]);
        }

        $storeRevenueQuery = DB::table('orders as o')
            ->whereIn('o.status', ['delivered', 'completed']);

        if ($hasStoresTable) {
            $storeRevenueQuery->leftJoin('stores as s', 's.id', '=', 'o.store_id');
        }

        if ($hasOrderCreatedAt) {
            $this->applyDateFilter($storeRevenueQuery, 'o.created_at', $validated);
        }

        if (!empty($validated['store_id'])) {
            $storeRevenueQuery->where('o.store_id', $validated['store_id']);
        }

        if (!empty($validated['region']) && $hasStoresTable) {
            $region = trim((string) $validated['region']);
            $storeRevenueQuery->where(function ($q) use ($region) {
                $q->where('s.address', 'like', '%' . $region . '%')
                    ->orWhere('s.name', 'like', '%' . $region . '%')
                    ->orWhere('s.store_code', 'like', '%' . $region . '%');
            });
        }

        $storeRevenueQuery
            ->selectRaw('o.store_id')
            ->selectRaw($hasStoresTable ? "COALESCE(NULLIF(s.store_code, ''), 'UNKNOWN') as store_code" : "COALESCE(NULLIF(o.store_id, ''), 'UNKNOWN') as store_code")
            ->selectRaw($hasStoresTable ? "COALESCE(NULLIF(s.name, ''), 'Khong xac dinh cua hang') as store_name" : "'Khong xac dinh cua hang' as store_name")
            ->selectRaw('COUNT(o.id) as total_orders')
            ->selectRaw(
                $hasOrderTotalTicketsUsed
                    ? 'COALESCE(SUM(o.total_tickets_used), 0) as total_tickets_used'
                    : ($hasOrderTicketsTable
                        ? 'COALESCE(SUM((SELECT COUNT(*) FROM order_tickets ot WHERE ot.order_id = o.id)), 0) as total_tickets_used'
                        : '0 as total_tickets_used')
            )
            ->selectRaw($orderAmountColumn ? 'COALESCE(SUM(o.' . $orderAmountColumn . '), 0) as total_revenue' : '0 as total_revenue')
            ->groupBy('o.store_id')
            ->when($hasStoresTable, function ($query) {
                $query->groupBy('s.store_code', 's.name');
            });

        $countQuery = DB::query()->fromSub(clone $storeRevenueQuery, 'store_rows');
        $total = $countQuery->count();

        $columns = [
            0 => 'store_name',
            1 => 'total_orders',
            2 => 'total_tickets_used',
            3 => 'converted_value',
            4 => 'total_revenue',
        ];

        if (!empty($validated['order'])) {
            foreach ($validated['order'] as $order) {
                $col = $order['column'] ?? 0;
                $dir = $order['dir'] === 'asc' ? 'asc' : 'desc';
                $field = $columns[$col] ?? 'total_revenue';

                if ($field === 'converted_value') {
                    $storeRevenueQuery->orderByRaw('total_tickets_used * ? ' . $dir, [$ticketUnitValue]);
                } else {
                    $storeRevenueQuery->orderBy($field, $dir);
                }
            }
        } else {
            $storeRevenueQuery->orderByDesc('total_revenue');
        }

        $results = $storeRevenueQuery
            ->skip($validated['start'])
            ->take($validated['length'])
            ->get();

        $data = $results->map(function ($row) use ($ticketUnitValue) {
            $storeName = e($row->store_name ?? 'Khong xac dinh cua hang');
            $storeCode = e($row->store_code ?? 'UNKNOWN');
            $storeId = e($row->store_id ?? 'N/A');
            $totalOrders = (int) ($row->total_orders ?? 0);
            $totalTicketsUsed = (int) ($row->total_tickets_used ?? 0);
            $convertedValue = (float) $totalTicketsUsed * $ticketUnitValue;
            $totalRevenue = (float) ($row->total_revenue ?? 0);

            return [
                'store_info' => '<div class="fw-semibold">' . $storeName . '</div><small class="text-muted">' . $storeCode . ' | ' . $storeId . '</small>',
                'total_orders' => number_format($totalOrders),
                'total_tickets_used' => number_format($totalTicketsUsed),
                'converted_value' => number_format($convertedValue, 0, ',', '.') . ' VND',
                'total_revenue' => number_format($totalRevenue, 0, ',', '.') . ' VND',
            ];
        });

        return response()->json([
            'draw' => $validated['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $data,
        ]);
    }

    private function applyDateFilter($query, string $column, array $filters): void
    {
        if (!empty($filters['from_date'])) {
            $query->whereDate($column, '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate($column, '<=', $filters['to_date']);
        }
    }

    private function periodExpression(string $column, string $granularity): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            if ($granularity === 'month') {
                return "to_char(date_trunc('month', {$column}), 'YYYY-MM')";
            }

            if ($granularity === 'week') {
                return "to_char(date_trunc('week', {$column}), 'IYYY-\"W\"IW')";
            }

            return "to_char(date_trunc('day', {$column}), 'YYYY-MM-DD')";
        }

        if ($driver === 'sqlite') {
            if ($granularity === 'month') {
                return "strftime('%Y-%m', {$column})";
            }

            if ($granularity === 'week') {
                return "strftime('%Y-W%W', {$column})";
            }

            return "strftime('%Y-%m-%d', {$column})";
        }

        if ($granularity === 'month') {
            return "DATE_FORMAT({$column}, '%Y-%m')";
        }

        if ($granularity === 'week') {
            return "DATE_FORMAT({$column}, '%x-W%v')";
        }

        return "DATE_FORMAT({$column}, '%Y-%m-%d')";
    }

    private function mergeTrendRows($ticketTrendRows, $orderTrendRows): array
    {
        $merged = [];

        foreach ($ticketTrendRows as $row) {
            $period = (string) $row->period_key;
            $merged[$period] = [
                'period' => $period,
                'ticket_revenue' => (float) ($row->ticket_revenue ?? 0),
                'order_revenue' => 0.0,
            ];
        }

        foreach ($orderTrendRows as $row) {
            $period = (string) $row->period_key;
            if (!isset($merged[$period])) {
                $merged[$period] = [
                    'period' => $period,
                    'ticket_revenue' => 0.0,
                    'order_revenue' => 0.0,
                ];
            }
            $merged[$period]['order_revenue'] = (float) ($row->order_revenue ?? 0);
        }

        ksort($merged);

        return array_values($merged);
    }
}
