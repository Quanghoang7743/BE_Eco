<?php

namespace App\Services;

use App\Models\User;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialistReportService
{
    private const SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const ACTIVE_STORE_STATUSES = ['active', 'approved'];
    private const SUSPENDED_STORE_STATUSES = ['suspended', 'paused', 'inactive', 'blocked', 'closed'];
    private const ELIGIBLE_TICKET_STATUSES = ['ELIGIBLE', 'AVAILABLE', 'USED_FOR_ORDER', 'USED_IN_ROOM'];
    private const HELD_TICKET_STATUSES = ['HELD', 'DISPUTED', 'LOCKED'];
    private const CANCELLED_TICKET_STATUSES = ['CANCELLED', 'CANCELED', 'REFUNDED', 'REFUNDED_CANCELLED', 'FRAUD', 'FRAUD_CANCELLED', 'EXPIRED'];
    private const PENDING_SETTLEMENT_STATUSES = ['pending', 'estimated', 'draft', 'pending_approval', 'awaiting_approval', 'waiting_approval', 'waiting_review', 'pending_settlement'];

    public function report(Request $request, ?User $user = null): array
    {
        $user ??= AdminCmsAccess::currentUser();
        $filters = $this->filters($request, $user);
        $isSaleUser = AdminCmsAccess::isSale($user);

        return [
            'filters' => $filters,
            'dateRangeLabel' => Carbon::parse($filters['date_from'])->format('d/m/Y') . ' - ' . Carbon::parse($filters['date_to'])->format('d/m/Y'),
            'isSaleUser' => $isSaleUser,
            'specialistOptions' => $this->specialistOptions($user),
            'selectedSpecialist' => $filters['specialist_id'] ? User::query()->find($filters['specialist_id']) : null,
            'personal' => $this->personalReport($filters),
            'admin' => $isSaleUser ? null : $this->adminReport($filters),
        ];
    }

    private function filters(Request $request, ?User $user): array
    {
        $today = now('Asia/Ho_Chi_Minh');
        $dateFrom = (string) ($request->query('date_from') ?: $today->copy()->startOfMonth()->toDateString());
        $dateTo = (string) ($request->query('date_to') ?: $today->toDateString());

        $from = Carbon::parse($dateFrom, 'Asia/Ho_Chi_Minh')->startOfDay();
        $to = Carbon::parse($dateTo, 'Asia/Ho_Chi_Minh')->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $specialistId = AdminCmsAccess::isSale($user)
            ? (int) $user->id
            : (int) $request->query('specialist_id', 0);

        $inactiveDays = (int) $request->query('inactive_days', 30);
        if ($inactiveDays < 1 || $inactiveDays > 365) {
            $inactiveDays = 30;
        }

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'from' => $from,
            'to' => $to->copy()->endOfDay(),
            'specialist_id' => $specialistId > 0 ? $specialistId : null,
            'inactive_days' => $inactiveDays,
        ];
    }

    private function personalReport(array $filters): array
    {
        $storeIds = $this->storeIdsForReport($filters['specialist_id']);
        $orderSummary = $this->orderSummary($filters, $storeIds, $filters['specialist_id']);
        $ticketSummary = $this->ticketSummary($filters, $storeIds, $filters['specialist_id']);
        $incomeSummary = $this->incomeSummary($filters, $filters['specialist_id']);
        $settlementSummary = $this->settlementSummary($filters, $filters['specialist_id']);

        return [
            'storeSummary' => $this->storeSummary($storeIds, $filters),
            'orderSummary' => $orderSummary,
            'ticketSummary' => $ticketSummary,
            'incomeSummary' => $incomeSummary,
            'settlementSummary' => $settlementSummary,
            'storeRows' => $this->storeRows($storeIds, $filters),
            'orderRows' => $this->successfulOrderRows($filters, $storeIds, $filters['specialist_id']),
            'ticketStatusRows' => $this->ticketStatusRows($filters, $storeIds, $filters['specialist_id']),
            'ticketTypeIncomeRows' => $this->ticketTypeIncomeRows($filters, $storeIds, $filters['specialist_id']),
            'settlementRows' => $this->settlementRows($filters, $filters['specialist_id']),
        ];
    }

    private function adminReport(array $filters): array
    {
        $specialists = User::query()
            ->select(['id', 'name', 'full_name', 'email', 'role'])
            ->where('role', 'sale')
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();

        $latestRatings = $this->latestRatingsBySpecialist($specialists->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $performanceRows = $this->performanceRows($specialists, $latestRatings, $filters);
        $adminTicketSummary = $this->ticketSummary($filters, [], null);
        $adminSettlementSummary = $this->settlementSummary($filters, null);
        $inactiveStoreRows = $this->inactiveStoreRows($filters, null, 20);

        return [
            'summary' => [
                'specialists' => $specialists->count(),
                'total_tickets' => $adminTicketSummary['total'],
                'abnormal_tickets' => $adminTicketSummary['abnormal'],
                'specialist_cost' => $adminSettlementSummary['final_income'],
                'high_performers' => $latestRatings->filter(fn ($rating): bool => (int) ($rating->star_level ?? 0) >= 4)->count(),
                'inactive_stores' => $this->inactiveStoreCount($filters, null),
            ],
            'performanceRows' => $performanceRows,
            'abnormalTicketRows' => $this->abnormalTicketRows($filters),
            'inactiveStoreRows' => $inactiveStoreRows,
        ];
    }

    private function storeSummary(array $storeIds, array $filters): array
    {
        if (empty($storeIds) || ! Schema::hasTable('stores')) {
            return ['total' => 0, 'active' => 0, 'suspended' => 0, 'no_order' => 0];
        }

        $base = DB::table('stores')->whereIn('id', $storeIds);

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::ACTIVE_STORE_STATUSES)->count(),
            'suspended' => (clone $base)->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::SUSPENDED_STORE_STATUSES)->count(),
            'no_order' => $this->inactiveStoreCount($filters, $storeIds),
        ];
    }

    private function orderSummary(array $filters, array $storeIds, ?int $specialistId): array
    {
        $query = $this->successfulOrdersQuery($filters, $storeIds, $specialistId);
        if ($query === null) {
            return ['total' => 0, 'gmv' => 0.0];
        }

        $row = $query
            ->selectRaw('COUNT(DISTINCT o.id) as total_orders')
            ->selectRaw('COALESCE(SUM(o.final_amount), 0) as total_gmv')
            ->first();

        return [
            'total' => (int) ($row->total_orders ?? 0),
            'gmv' => (float) ($row->total_gmv ?? 0),
        ];
    }

    private function ticketSummary(array $filters, array $storeIds, ?int $specialistId): array
    {
        $query = $this->specialistTicketEarningQuery($filters, $specialistId, $storeIds);
        if ($query === null) {
            return ['total' => 0, 'eligible' => 0, 'held' => 0, 'cancelled' => 0, 'abnormal' => 0];
        }

        $row = $query
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw("SUM(CASE WHEN is_eligible = 1 OR UPPER(COALESCE(ticket_status, '')) IN ('" . implode("','", self::ELIGIBLE_TICKET_STATUSES) . "') THEN 1 ELSE 0 END) as eligible_tickets")
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(ticket_status, '')) IN ('" . implode("','", self::HELD_TICKET_STATUSES) . "') THEN 1 ELSE 0 END) as held_tickets")
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(ticket_status, '')) IN ('" . implode("','", self::CANCELLED_TICKET_STATUSES) . "') THEN 1 ELSE 0 END) as cancelled_tickets")
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(ticket_status, '')) IN ('HELD','DISPUTED','LOCKED','FRAUD','FRAUD_CANCELLED') OR LOWER(COALESCE(earning_status, status, '')) IN ('held','disputed','fraud','fraud_cancelled') THEN 1 ELSE 0 END) as abnormal_tickets")
            ->first();

        return [
            'total' => (int) ($row->total_tickets ?? 0),
            'eligible' => (int) ($row->eligible_tickets ?? 0),
            'held' => (int) ($row->held_tickets ?? 0),
            'cancelled' => (int) ($row->cancelled_tickets ?? 0),
            'abnormal' => (int) ($row->abnormal_tickets ?? 0),
        ];
    }

    private function incomeSummary(array $filters, ?int $specialistId): array
    {
        $query = $this->specialistTicketEarningQuery($filters, $specialistId, []);
        if ($query === null) {
            return ['estimated' => 0.0, 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'held' => 0.0, 'cancelled' => 0.0];
        }

        $row = $query
            ->selectRaw("SUM(CASE WHEN is_eligible = 1 THEN earning_amount ELSE 0 END) as estimated_income")
            ->selectRaw("SUM(CASE WHEN is_eligible = 1 AND LOWER(COALESCE(earning_status, status, '')) IN ('" . implode("','", self::PENDING_SETTLEMENT_STATUSES) . "') THEN earning_amount ELSE 0 END) as pending_income")
            ->selectRaw("SUM(CASE WHEN is_eligible = 1 AND LOWER(COALESCE(earning_status, status, '')) = 'approved' THEN earning_amount ELSE 0 END) as approved_income")
            ->selectRaw("SUM(CASE WHEN is_eligible = 1 AND (LOWER(COALESCE(earning_status, status, '')) = 'paid' OR paid_at IS NOT NULL) THEN earning_amount ELSE 0 END) as paid_income")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(earning_status, status, '')) IN ('held','disputed') THEN ABS(earning_amount) ELSE 0 END) as held_income")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(earning_status, status, '')) IN ('cancelled','canceled','refunded','fraud','fraud_cancelled','refunded_cancelled','adjusted') THEN ABS(earning_amount) ELSE 0 END) as cancelled_income")
            ->first();

        return [
            'estimated' => (float) ($row->estimated_income ?? 0),
            'pending' => (float) ($row->pending_income ?? 0),
            'approved' => (float) ($row->approved_income ?? 0),
            'paid' => (float) ($row->paid_income ?? 0),
            'held' => (float) ($row->held_income ?? 0),
            'cancelled' => (float) ($row->cancelled_income ?? 0),
        ];
    }

    private function settlementSummary(array $filters, ?int $specialistId): array
    {
        $query = $this->settlementQuery($filters, $specialistId);
        if ($query === null) {
            return ['total' => 0, 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'final_income' => 0.0];
        }

        $row = $query
            ->selectRaw('COUNT(*) as total_settlements')
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('" . implode("','", self::PENDING_SETTLEMENT_STATUSES) . "') THEN final_income ELSE 0 END) as pending_income")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'approved' THEN final_income ELSE 0 END) as approved_income")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('paid','settled','completed') THEN final_income ELSE 0 END) as paid_income")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(status, '')) <> 'cancelled' THEN final_income ELSE 0 END) as final_income")
            ->first();

        return [
            'total' => (int) ($row->total_settlements ?? 0),
            'pending' => (float) ($row->pending_income ?? 0),
            'approved' => (float) ($row->approved_income ?? 0),
            'paid' => (float) ($row->paid_income ?? 0),
            'final_income' => (float) ($row->final_income ?? 0),
        ];
    }

    private function storeRows(array $storeIds, array $filters): Collection
    {
        if (empty($storeIds) || ! Schema::hasTable('stores')) {
            return collect();
        }

        $orderMap = $this->orderAggregatesByStore($storeIds, $filters);
        $ticketMap = $this->ticketAggregatesByStore($storeIds, $filters);
        $incomeMap = $this->incomeAggregatesByStore($storeIds, $filters);
        $assignmentMap = $this->assignmentMapByStore($storeIds);

        return DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'store_code', 'name', 'status', 'sale_id'])
            ->map(function ($store) use ($orderMap, $ticketMap, $incomeMap, $assignmentMap): array {
                $storeId = (string) $store->id;
                $orders = $orderMap[$storeId] ?? ['orders' => 0, 'gmv' => 0.0];
                $tickets = $ticketMap[$storeId] ?? ['total' => 0, 'eligible' => 0, 'held' => 0, 'cancelled' => 0];

                return [
                    'id' => $storeId,
                    'code' => (string) ($store->store_code ?? ''),
                    'name' => (string) ($store->name ?? 'N/A'),
                    'status' => (string) ($store->status ?? 'N/A'),
                    'assigned_from' => $assignmentMap[$storeId]['assigned_from'] ?? null,
                    'assigned_to' => $assignmentMap[$storeId]['assigned_to'] ?? null,
                    'orders' => (int) $orders['orders'],
                    'gmv' => (float) $orders['gmv'],
                    'tickets' => (int) $tickets['total'],
                    'eligible_tickets' => (int) $tickets['eligible'],
                    'held_tickets' => (int) $tickets['held'],
                    'cancelled_tickets' => (int) $tickets['cancelled'],
                    'income' => (float) ($incomeMap[$storeId] ?? 0),
                ];
            });
    }

    private function successfulOrderRows(array $filters, array $storeIds, ?int $specialistId): Collection
    {
        $query = $this->successfulOrdersQuery($filters, $storeIds, $specialistId);
        if ($query === null) {
            return collect();
        }

        return $query
            ->leftJoin('stores as s', 's.id', '=', 'o.store_id')
            ->orderByDesc(DB::raw('COALESCE(o.delivered_at, o.created_at)'))
            ->limit(30)
            ->get([
                'o.id',
                'o.order_code',
                'o.final_amount',
                'o.status',
                'o.status_payment',
                'o.delivered_at',
                'o.created_at',
                's.name as store_name',
                's.store_code',
            ]);
    }

    private function ticketStatusRows(array $filters, array $storeIds, ?int $specialistId): Collection
    {
        $query = $this->specialistTicketEarningQuery($filters, $specialistId, $storeIds);
        if ($query === null) {
            return collect();
        }

        $statusRows = $query
            ->selectRaw("COALESCE(NULLIF(ticket_status, ''), 'UNKNOWN') as ticket_status_group")
            ->selectRaw('is_eligible')
            ->selectRaw('earning_amount');

        return DB::query()
            ->fromSub($statusRows, 'ticket_status_rows')
            ->selectRaw('ticket_status_group as status')
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw('SUM(CASE WHEN is_eligible = 1 THEN 1 ELSE 0 END) as eligible_tickets')
            ->selectRaw('COALESCE(SUM(earning_amount), 0) as income')
            ->groupBy('ticket_status_group')
            ->orderByDesc('total_tickets')
            ->limit(20)
            ->get();
    }

    private function ticketTypeIncomeRows(array $filters, array $storeIds, ?int $specialistId): Collection
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return collect();
        }

        $query = DB::table('specialist_ticket_earnings as ste')
            ->leftJoin('tickets as t', 't.id', '=', 'ste.ticket_id')
            ->whereRaw('COALESCE(ste.generated_at, ste.earned_at, ste.created_at) >= ?', [$filters['from']->toDateTimeString()])
            ->whereRaw('COALESCE(ste.generated_at, ste.earned_at, ste.created_at) <= ?', [$filters['to']->toDateTimeString()]);

        if ($specialistId !== null) {
            $query->where('ste.specialist_id', $specialistId);
        }

        if (! empty($storeIds)) {
            $query->whereIn('ste.store_id', $storeIds);
        }

        $rows = $query
            ->selectRaw("COALESCE(t.ticket_type_code, JSON_UNQUOTE(JSON_EXTRACT(ste.metadata, '$.ticket_type_code')), 'TICKET_DEFAULT') as ticket_type_code")
            ->selectRaw('ste.is_eligible as is_eligible')
            ->selectRaw('ste.earning_amount as earning_amount');

        return DB::query()
            ->fromSub($rows, 'ticket_type_income_rows')
            ->select('ticket_type_code')
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw('SUM(CASE WHEN is_eligible = 1 THEN 1 ELSE 0 END) as eligible_tickets')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_eligible = 1 THEN earning_amount ELSE 0 END), 0) as income')
            ->groupBy('ticket_type_code')
            ->orderByDesc('income')
            ->limit(20)
            ->get();
    }

    private function settlementRows(array $filters, ?int $specialistId): Collection
    {
        $query = $this->settlementQuery($filters, $specialistId);
        if ($query === null) {
            return collect();
        }

        return $query
            ->leftJoin('users as u', 'u.id', '=', 'specialist_settlements.specialist_id')
            ->orderByDesc('period_from')
            ->limit(20)
            ->get([
                'specialist_settlements.id',
                'specialist_settlements.period_from',
                'specialist_settlements.period_to',
                'specialist_settlements.status',
                'specialist_settlements.total_eligible_tickets',
                'specialist_settlements.gross_income',
                'specialist_settlements.adjustment_amount',
                'specialist_settlements.final_income',
                'u.name',
                'u.full_name',
                'u.email',
            ]);
    }

    private function performanceRows(Collection $specialists, Collection $latestRatings, array $filters): Collection
    {
        return $specialists
            ->map(function ($specialist) use ($latestRatings, $filters): array {
                $specialistId = (int) $specialist->id;
                $storeIds = $this->storeIdsForReport($specialistId);
                $orders = $this->orderSummary($filters, $storeIds, $specialistId);
                $tickets = $this->ticketSummary($filters, $storeIds, $specialistId);
                $settlements = $this->settlementSummary($filters, $specialistId);
                $rating = $latestRatings->get($specialistId);

                return [
                    'id' => $specialistId,
                    'name' => $this->userName($specialist),
                    'email' => (string) ($specialist->email ?? ''),
                    'stores' => count($storeIds),
                    'orders' => $orders['total'],
                    'gmv' => $orders['gmv'],
                    'tickets' => $tickets['total'],
                    'abnormal_tickets' => $tickets['abnormal'],
                    'income' => $settlements['final_income'],
                    'paid_income' => $settlements['paid'],
                    'score' => $rating ? (float) ($rating->total_score ?? 0) : null,
                    'stars' => $rating ? (int) ($rating->star_level ?? 0) : null,
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) ($row['score'] ?? 0) + ($row['orders'] / 1000))
            ->values()
            ->take(30);
    }

    private function abnormalTicketRows(array $filters): Collection
    {
        $query = $this->specialistTicketEarningQuery($filters, null, []);
        if ($query === null) {
            return collect();
        }

        return $query
            ->leftJoin('users as u', 'u.id', '=', 'specialist_ticket_earnings.specialist_id')
            ->leftJoin('stores as s', 's.id', '=', 'specialist_ticket_earnings.store_id')
            ->where(function ($scope): void {
                $scope->whereIn(DB::raw("UPPER(COALESCE(specialist_ticket_earnings.ticket_status, ''))"), ['HELD', 'DISPUTED', 'LOCKED', 'FRAUD', 'FRAUD_CANCELLED'])
                    ->orWhereIn(DB::raw("LOWER(COALESCE(specialist_ticket_earnings.earning_status, specialist_ticket_earnings.status, ''))"), ['held', 'disputed', 'fraud', 'fraud_cancelled']);
            })
            ->orderByDesc(DB::raw('COALESCE(specialist_ticket_earnings.generated_at, specialist_ticket_earnings.earned_at, specialist_ticket_earnings.created_at)'))
            ->limit(30)
            ->get([
                'specialist_ticket_earnings.id',
                'specialist_ticket_earnings.ticket_id',
                'specialist_ticket_earnings.ticket_status',
                'specialist_ticket_earnings.reason_not_eligible',
                'specialist_ticket_earnings.generated_at',
                'u.name',
                'u.full_name',
                'u.email',
                's.name as store_name',
                's.store_code',
            ]);
    }

    private function inactiveStoreRows(array $filters, ?array $storeIds, int $limit): Collection
    {
        if (! Schema::hasTable('stores')) {
            return collect();
        }

        $query = DB::table('stores as s')
            ->leftJoin('users as u', 'u.id', '=', 's.sale_id')
            ->whereIn(DB::raw("LOWER(COALESCE(s.status, ''))"), self::ACTIVE_STORE_STATUSES)
            ->whereNotExists(function ($exists) use ($filters): void {
                $exists->selectRaw('1')
                    ->from('orders as o')
                    ->whereColumn('o.store_id', 's.id')
                    ->whereIn(DB::raw("LOWER(COALESCE(o.status, ''))"), self::SUCCESS_ORDER_STATUSES)
                    ->where('o.created_at', '>=', Carbon::parse($filters['date_to'])->subDays($filters['inactive_days'])->startOfDay()->toDateTimeString());
            });

        if (is_array($storeIds)) {
            if (empty($storeIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('s.id', $storeIds);
            }
        }

        return $query
            ->orderBy('s.name')
            ->limit($limit)
            ->get([
                's.id',
                's.store_code',
                's.name',
                's.status',
                's.created_at',
                'u.full_name as specialist_full_name',
                'u.name as specialist_name',
                'u.email as specialist_email',
            ]);
    }

    private function inactiveStoreCount(array $filters, ?array $storeIds): int
    {
        return $this->inactiveStoreRows($filters, $storeIds, 100000)->count();
    }

    private function successfulOrdersQuery(array $filters, array $storeIds, ?int $specialistId): ?QueryBuilder
    {
        if (! Schema::hasTable('orders')) {
            return null;
        }

        $query = DB::table('orders as o')
            ->whereIn(DB::raw("LOWER(COALESCE(o.status, ''))"), self::SUCCESS_ORDER_STATUSES)
            ->where('o.status_payment', 'paid')
            ->whereRaw('COALESCE(o.delivered_at, o.created_at) >= ?', [$filters['from']->toDateTimeString()])
            ->whereRaw('COALESCE(o.delivered_at, o.created_at) <= ?', [$filters['to']->toDateTimeString()]);

        if ($specialistId !== null) {
            $this->applyOrderSpecialistScope($query, $specialistId);
        } elseif (! empty($storeIds)) {
            $query->whereIn('o.store_id', $storeIds);
        }

        return $query;
    }

    private function specialistTicketEarningQuery(array $filters, ?int $specialistId, array $storeIds): ?QueryBuilder
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return null;
        }

        $query = DB::table('specialist_ticket_earnings')
            ->whereRaw('COALESCE(specialist_ticket_earnings.generated_at, specialist_ticket_earnings.earned_at, specialist_ticket_earnings.created_at) >= ?', [$filters['from']->toDateTimeString()])
            ->whereRaw('COALESCE(specialist_ticket_earnings.generated_at, specialist_ticket_earnings.earned_at, specialist_ticket_earnings.created_at) <= ?', [$filters['to']->toDateTimeString()]);

        if ($specialistId !== null) {
            $query->where('specialist_id', $specialistId);
        }

        if (! empty($storeIds)) {
            $query->whereIn('store_id', $storeIds);
        }

        return $query;
    }

    private function settlementQuery(array $filters, ?int $specialistId): ?QueryBuilder
    {
        if (! Schema::hasTable('specialist_settlements')) {
            return null;
        }

        $query = DB::table('specialist_settlements')
            ->where(function ($period) use ($filters): void {
                $period->whereNull('period_from')
                    ->orWhereNull('period_to')
                    ->orWhere(function ($intersect) use ($filters): void {
                        $intersect->whereDate('period_from', '<=', $filters['date_to'])
                            ->whereDate('period_to', '>=', $filters['date_from']);
                    });
            });

        if ($specialistId !== null) {
            $query->where('specialist_id', $specialistId);
        }

        return $query;
    }

    private function applyOrderSpecialistScope(QueryBuilder $query, int $specialistId): void
    {
        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_from')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            $dateExpression = 'COALESCE(o.delivered_at, o.created_at)';
            $query->whereExists(function ($assignment) use ($specialistId, $dateExpression): void {
                $assignment->selectRaw('1')
                    ->from('specialist_store_assignments as ssa')
                    ->where('ssa.specialist_id', $specialistId)
                    ->whereColumn('ssa.store_id', 'o.store_id')
                    ->whereRaw('ssa.assigned_from <= ' . $dateExpression)
                    ->where(function ($window) use ($dateExpression): void {
                        $window->whereNull('ssa.assigned_to')
                            ->orWhereRaw('ssa.assigned_to >= ' . $dateExpression);
                    });
            });

            return;
        }

        $storeIds = $this->storeIdsForReport($specialistId);
        $query->whereIn('o.store_id', $storeIds);
    }

    private function storeIdsForReport(?int $specialistId): array
    {
        if (! Schema::hasTable('stores')) {
            return [];
        }

        if ($specialistId !== null) {
            $ids = $this->activeAssignmentStoreIds($specialistId);
            if (! empty($ids)) {
                return $ids;
            }

            if (Schema::hasColumn('stores', 'sale_id')) {
                return DB::table('stores')
                    ->where('sale_id', $specialistId)
                    ->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->unique()
                    ->values()
                    ->all();
            }

            return [];
        }

        $ids = collect();
        if (Schema::hasTable('specialist_store_assignments')) {
            $ids = $ids->merge(DB::table('specialist_store_assignments')->pluck('store_id'));
        }
        if (Schema::hasColumn('stores', 'sale_id')) {
            $ids = $ids->merge(DB::table('stores')->whereNotNull('sale_id')->pluck('id'));
        }

        return $ids->map(static fn ($id): string => (string) $id)->unique()->values()->all();
    }

    private function activeAssignmentStoreIds(int $specialistId): array
    {
        if (! Schema::hasTable('specialist_store_assignments')) {
            return [];
        }

        $query = DB::table('specialist_store_assignments')->where('specialist_id', $specialistId);
        if (Schema::hasColumn('specialist_store_assignments', 'assigned_to')) {
            $now = now('Asia/Ho_Chi_Minh')->toDateTimeString();
            $query->where(function ($window) use ($now): void {
                $window->whereNull('assigned_to')->orWhere('assigned_to', '>=', $now);
            });
        }

        return $query->pluck('store_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all();
    }

    private function orderAggregatesByStore(array $storeIds, array $filters): array
    {
        if (empty($storeIds) || ! Schema::hasTable('orders')) {
            return [];
        }

        return DB::table('orders')
            ->whereIn('store_id', $storeIds)
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::SUCCESS_ORDER_STATUSES)
            ->where('status_payment', 'paid')
            ->whereRaw('COALESCE(delivered_at, created_at) >= ?', [$filters['from']->toDateTimeString()])
            ->whereRaw('COALESCE(delivered_at, created_at) <= ?', [$filters['to']->toDateTimeString()])
            ->select('store_id')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(final_amount), 0) as gmv')
            ->groupBy('store_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->store_id => ['orders' => (int) $row->orders, 'gmv' => (float) $row->gmv]])
            ->all();
    }

    private function ticketAggregatesByStore(array $storeIds, array $filters): array
    {
        $query = $this->specialistTicketEarningQuery($filters, null, $storeIds);
        if ($query === null) {
            return [];
        }

        return $query
            ->select('store_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_eligible = 1 THEN 1 ELSE 0 END) as eligible')
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(ticket_status, '')) IN ('" . implode("','", self::HELD_TICKET_STATUSES) . "') THEN 1 ELSE 0 END) as held")
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(ticket_status, '')) IN ('" . implode("','", self::CANCELLED_TICKET_STATUSES) . "') THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('store_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->store_id => [
                'total' => (int) $row->total,
                'eligible' => (int) $row->eligible,
                'held' => (int) $row->held,
                'cancelled' => (int) $row->cancelled,
            ]])
            ->all();
    }

    private function incomeAggregatesByStore(array $storeIds, array $filters): array
    {
        $query = $this->specialistTicketEarningQuery($filters, null, $storeIds);
        if ($query === null) {
            return [];
        }

        return $query
            ->select('store_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_eligible = 1 THEN earning_amount ELSE 0 END), 0) as income')
            ->groupBy('store_id')
            ->pluck('income', 'store_id')
            ->mapWithKeys(static fn ($value, $storeId): array => [(string) $storeId => (float) $value])
            ->all();
    }

    private function assignmentMapByStore(array $storeIds): array
    {
        if (empty($storeIds) || ! Schema::hasTable('specialist_store_assignments')) {
            return [];
        }

        return DB::table('specialist_store_assignments')
            ->whereIn('store_id', $storeIds)
            ->select('store_id')
            ->selectRaw('MIN(assigned_from) as assigned_from')
            ->selectRaw('MAX(assigned_to) as assigned_to')
            ->groupBy('store_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->store_id => [
                'assigned_from' => $row->assigned_from,
                'assigned_to' => $row->assigned_to,
            ]])
            ->all();
    }

    private function latestRatingsBySpecialist(array $specialistIds): Collection
    {
        if (empty($specialistIds) || ! Schema::hasTable('specialist_ratings')) {
            return collect();
        }

        return DB::table('specialist_ratings')
            ->whereIn('specialist_id', $specialistIds)
            ->orderByDesc(DB::raw('COALESCE(approved_at, calculated_at, created_at)'))
            ->get(['id', 'specialist_id', 'total_score', 'star_level', 'status', 'approved_at', 'calculated_at', 'created_at'])
            ->groupBy('specialist_id')
            ->map(static fn (Collection $rows) => $rows->first());
    }

    private function specialistOptions(?User $user): Collection
    {
        if (AdminCmsAccess::isSale($user) || ! Schema::hasTable('users')) {
            return collect();
        }

        return User::query()
            ->select(['id', 'name', 'full_name', 'email'])
            ->where('role', 'sale')
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();
    }

    private function userName($user): string
    {
        return $user ? (string) (($user->full_name ?? null) ?: ($user->name ?? null) ?: ($user->email ?? 'N/A')) : 'N/A';
    }
}
