<?php

namespace App\Http\Controllers;

use App\Services\SpecialistFeeConfigService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TicketReportController extends Controller
{
    private const SPECIALIST_SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const SPECIALIST_RESOLVED_COMPLAINT_STATUSES = ['resolved', 'closed', 'rejected', 'cancelled', 'canceled', 'done'];
    private const SPECIALIST_TICKET_STATUS_FILTERS = [
        'generated' => ['GENERATED', 'CREATED', 'NEW'],
        'eligible' => ['ELIGIBLE', 'AVAILABLE', 'USED_FOR_ORDER', 'USED_IN_ROOM'],
        'pending_confirmation' => ['PENDING', 'PENDING_CONFIRMATION', 'PENDING CONFIRMATION'],
        'held' => ['HELD', 'LOCKED'],
        'disputed' => ['DISPUTED'],
        'cancelled' => ['CANCELLED', 'CANCELED'],
        'refunded_cancelled' => ['REFUNDED', 'REFUNDED_CANCELLED', 'REFUNDED CANCELED'],
        'fraud_cancelled' => ['FRAUD', 'FRAUD_CANCELLED', 'FRAUD CANCELED'],
        'expired' => ['EXPIRED'],
    ];

    public function __construct(
        private readonly SpecialistFeeConfigService $feeConfigService
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'store_code' => ['nullable', 'string', 'max:100'],
        ]);

        $baseTickets = DB::table('tickets');
        $this->applySaleTicketScope($baseTickets, 'tickets');

        if (!empty($validated['date_from'])) {
            $baseTickets->whereDate('purchased_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $baseTickets->whereDate('purchased_at', '<=', $validated['date_to']);
        }

        $summary = (clone $baseTickets)
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw('COALESCE(SUM(COALESCE(purchased_price, value_amount, 0)), 0) as total_revenue')
            ->first();

        $statusRows = (clone $baseTickets)
            ->selectRaw("COALESCE(NULLIF(status, ''), 'UNKNOWN') as status")
            ->selectRaw('COUNT(*) as total_tickets')
            ->groupBy('status')
            ->orderByDesc('total_tickets')
            ->get();

        $dailyRows = (clone $baseTickets)
            ->selectRaw('DATE(purchased_at) as stat_date')
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw('COALESCE(SUM(COALESCE(purchased_price, value_amount, 0)), 0) as total_revenue')
            ->groupByRaw('DATE(purchased_at)')
            ->orderByDesc('stat_date')
            ->paginate(15, ['*'], 'daily_page');

        $dailyRows->appends($request->query());

        $storeQuery = DB::table('tickets as t')
            ->leftJoin('stores as s', function ($join) {
                $join->on('t.source_id', '=', 's.id')
                    ->whereIn('t.source_type', ['store', 'STORE']);
            })
            ->selectRaw("COALESCE(NULLIF(s.store_code, ''), 'UNKNOWN_STORE') as store_code")
            ->selectRaw("COALESCE(NULLIF(s.name, ''), 'Khong xac dinh cua hang') as store_name")
            ->selectRaw('COUNT(t.id) as total_tickets')
            ->selectRaw('COALESCE(SUM(COALESCE(t.purchased_price, t.value_amount, 0)), 0) as total_revenue')
            ->groupBy('s.id', 's.store_code', 's.name')
            ->orderByDesc('total_tickets');

        if (AdminCmsAccess::isSale()) {
            $storeQuery->where('s.sale_id', auth()->id());
        }

        if (!empty($validated['date_from'])) {
            $storeQuery->whereDate('t.purchased_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $storeQuery->whereDate('t.purchased_at', '<=', $validated['date_to']);
        }

        if (!empty($validated['store_code'])) {
            $storeCode = trim((string) $validated['store_code']);
            $storeQuery->where('s.store_code', 'like', '%' . $storeCode . '%');
        }

        $storeRows = $storeQuery
            ->simplePaginate(15, ['*'], 'store_page');

        $storeRows->appends($request->query());

        return view('admin.tickets.stats', [
            'summary' => $summary,
            'statusRows' => $statusRows,
            'dailyRows' => $dailyRows,
            'storeRows' => $storeRows,
        ]);
    }

    public function specialistTickets(Request $request)
    {
        return view('admin.specialists.tickets.index', [
            'isSaleUser' => AdminCmsAccess::isSale(),
            'storeOptions' => $this->specialistTicketStoreOptions(),
            'statusOptions' => $this->specialistTicketStatusOptions(),
        ]);
    }

    public function specialistTicketsDatatable(Request $request)
    {
        $validated = $request->validate([
            'draw' => ['required', 'integer'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', 'min:1', 'max:100'],
            'order' => ['nullable', 'array'],
            'search' => ['nullable', 'array'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'store_id' => ['nullable', 'string', 'max:36'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $expressions = $this->specialistTicketExpressions();
        $baseQuery = $this->specialistTicketBaseQuery($expressions);
        $this->applySpecialistTicketScope($baseQuery, $expressions['store_id'], $expressions['scope_date']);

        $recordsTotal = $this->countSpecialistTickets($baseQuery);

        $query = clone $baseQuery;
        $this->applySpecialistTicketFilters($query, $validated, $expressions);

        $recordsFiltered = $this->countSpecialistTickets($query);
        $this->applySpecialistTicketOrdering($query, $validated['order'] ?? [], $expressions);

        $tickets = $query
            ->skip((int) $validated['start'])
            ->take((int) $validated['length'])
            ->get();

        $orderIds = $tickets
            ->pluck('order_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $complaintCounts = $this->activeComplaintCountsForOrders($orderIds);
        $refundedOrderIds = $this->refundedOrderIds($orderIds);

        $data = $tickets->map(function ($ticket) use ($complaintCounts, $refundedOrderIds) {
            $statusInfo = $this->specialistTicketStatusInfo($ticket);
            $orderId = (string) ($ticket->order_id ?? '');
            $eligibility = $this->specialistTicketEligibility(
                $ticket,
                $statusInfo,
                (int) ($complaintCounts[$orderId] ?? 0),
                in_array($orderId, $refundedOrderIds, true)
            );

            return [
                'ticket_code' => $this->formatSpecialistTicketCode($ticket),
                'store' => $this->formatSpecialistTicketStore($ticket),
                'order' => $this->formatSpecialistTicketOrder($ticket),
                'owner' => $this->formatSpecialistTicketOwner($ticket),
                'ticket_status' => $this->formatSpecialistTicketStatus($statusInfo, (string) ($ticket->raw_ticket_status ?? '')),
                'generated_at' => $this->formatDateTime($ticket->generated_at ?? null),
                'income_eligible' => $this->formatSpecialistIncomeEligibility($eligibility),
                'earning_amount' => $this->formatSpecialistTicketAmount($eligibility),
                'reason' => $this->formatSpecialistTicketReason($ticket, $statusInfo, $eligibility),
            ];
        });

        return response()->json([
            'draw' => (int) $validated['draw'],
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function unused(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:AVAILABLE,USED_IN_ROOM'],
            'source_type' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);

        $baseUnused = DB::table('tickets as t')
            ->whereIn('t.status', ['AVAILABLE', 'USED_IN_ROOM'])
            ->whereNull('t.used_in_order_id');

        $this->applySaleTicketScope($baseUnused, 't');

        if (!empty($validated['date_from'])) {
            $baseUnused->whereDate('t.purchased_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $baseUnused->whereDate('t.purchased_at', '<=', $validated['date_to']);
        }

        if (!empty($validated['status'])) {
            $baseUnused->where('t.status', $validated['status']);
        }

        if (!empty($validated['source_type'])) {
            $baseUnused->where('t.source_type', trim((string) $validated['source_type']));
        }

        $now = now();

        $availableBalanceQuery = DB::table('user_balances');

        if (AdminCmsAccess::isSale()) {
            $scopedTicketUsers = DB::table('tickets as scoped_tickets')
                ->select('scoped_tickets.user_id')
                ->whereNotNull('scoped_tickets.user_id')
                ->distinct();
            $this->applySaleTicketScope($scopedTicketUsers, 'scoped_tickets');

            $availableBalanceQuery->whereIn('user_id', $scopedTicketUsers);
        }

        $summary = [
            'total_unused_tickets' => (clone $baseUnused)->count(),
            'week_unused_tickets' => (clone $baseUnused)->where('t.purchased_at', '>=', $now->copy()->startOfWeek())->count(),
            'month_unused_tickets' => (clone $baseUnused)->where('t.purchased_at', '>=', $now->copy()->startOfMonth())->count(),
            'total_available_balance' => (int) $availableBalanceQuery->sum('available_balance'),
        ];

        $query = DB::table('tickets as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('user_balances as ub', 'ub.user_id', '=', 't.user_id')
            ->whereIn('t.status', ['AVAILABLE', 'USED_IN_ROOM'])
            ->whereNull('t.used_in_order_id')
            ->select([
                't.id',
                't.ticket_code',
                't.user_id',
                't.status',
                't.purchased_at',
                't.expired_at',
                't.source_type',
                't.batch_id',
                't.value_amount',
                't.purchased_price',
                'u.phone as user_phone',
                'u.email as user_email',
                'ub.available_balance',
            ])
            ->selectRaw("COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), 'N/A') as owner_name");

        $this->applySaleTicketScope($query, 't');

        if (!empty($validated['date_from'])) {
            $query->whereDate('t.purchased_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('t.purchased_at', '<=', $validated['date_to']);
        }

        if (!empty($validated['status'])) {
            $query->where('t.status', $validated['status']);
        }

        if (!empty($validated['source_type'])) {
            $query->where('t.source_type', trim((string) $validated['source_type']));
        }

        if (!empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($sub) use ($keyword) {
                $sub->where('t.ticket_code', 'like', '%' . $keyword . '%')
                    ->orWhere('t.user_id', 'like', '%' . $keyword . '%')
                    ->orWhere('u.full_name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.phone', 'like', '%' . $keyword . '%');
            });
        }

        $unusedTickets = $query
            ->orderByDesc('t.purchased_at')
            ->paginate(20, ['*'], 'page');

        $unusedTickets->appends($request->query());

        return view('admin.tickets.unused', [
            'summary' => $summary,
            'unusedTickets' => $unusedTickets,
        ]);
    }

    public function datatable(Request $request)
    {
        $validated = $request->validate([
            'draw' => ['required', 'integer'],
            'start' => ['required', 'integer'],
            'length' => ['required', 'integer'],
            'order' => ['nullable', 'array'],
            'search' => ['nullable', 'array'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:AVAILABLE,USED_IN_ROOM'],
            'source_type' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);

        $columns = [
            0 => 'ticket_code',
            1 => 'owner_name',
            2 => 'status',
            3 => 'purchased_at',
            4 => 'expired_at',
            5 => 'source_type',
            6 => 'purchased_price',
            7 => 'available_balance',
        ];

        $query = DB::table('tickets as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('user_balances as ub', 'ub.user_id', '=', 't.user_id')
            ->whereIn('t.status', ['AVAILABLE', 'USED_IN_ROOM'])
            ->whereNull('t.used_in_order_id')
            ->select([
                't.id',
                't.ticket_code',
                't.user_id',
                't.status',
                't.purchased_at',
                't.expired_at',
                't.source_type',
                't.batch_id',
                't.value_amount',
                't.purchased_price',
                'u.phone as user_phone',
                'u.email as user_email',
                'ub.available_balance',
            ])
            ->selectRaw("COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), 'N/A') as owner_name");

        $this->applySaleTicketScope($query, 't');

        if (!empty($validated['date_from'])) {
            $query->whereDate('t.purchased_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('t.purchased_at', '<=', $validated['date_to']);
        }

        if (!empty($validated['status'])) {
            $query->where('t.status', $validated['status']);
        }

        if (!empty($validated['source_type'])) {
            $query->where('t.source_type', trim((string) $validated['source_type']));
        }

        if (!empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($sub) use ($keyword) {
                $sub->where('t.ticket_code', 'like', '%' . $keyword . '%')
                    ->orWhere('t.user_id', 'like', '%' . $keyword . '%')
                    ->orWhere('u.full_name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.phone', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();

        // Sorting
        if (!empty($validated['order'])) {
            foreach ($validated['order'] as $order) {
                $col = $order['column'] ?? 0;
                $dir = $order['dir'] === 'asc' ? 'asc' : 'desc';
                if (isset($columns[$col])) {
                    $field = $columns[$col];
                    if ($field === 'owner_name') {
                        $query->orderBy(DB::raw("COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), 'N/A')"), $dir);
                    } elseif ($field === 'available_balance') {
                        $query->orderBy('ub.available_balance', $dir);
                    } else {
                        $query->orderBy('t.' . $field, $dir);
                    }
                }
            }
        } else {
            $query->orderByDesc('t.purchased_at');
        }

        $filtered = (clone $query)->count();
        $results = $query->skip($validated['start'])->take($validated['length'])->get();

        $data = $results->map(function ($ticket) {
            return [
                'ticket_code' => view('admin.tickets.partials.datatable-ticket-code', compact('ticket'))->render(),
                'owner_name' => view('admin.tickets.partials.datatable-owner-info', compact('ticket'))->render(),
                'status' => view('admin.tickets.partials.datatable-status', compact('ticket'))->render(),
                'purchased_at' => view('admin.tickets.partials.datatable-purchased-at', compact('ticket'))->render(),
                'expired_at' => view('admin.tickets.partials.datatable-expired-at', compact('ticket'))->render(),
                'source_type' => view('admin.tickets.partials.datatable-source', compact('ticket'))->render(),
                'purchased_price' => view('admin.tickets.partials.datatable-price', compact('ticket'))->render(),
                'available_balance' => view('admin.tickets.partials.datatable-balance', compact('ticket'))->render(),
            ];
        });

        return response()->json([
            'draw' => $validated['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    private function specialistTicketExpressions(): array
    {
        $storeId = 'COALESCE(source_order.store_id, used_order.store_id, linked_order.store_id, ste.earning_store_id, source_store.id)';
        $orderId = 'COALESCE(source_order.id, used_order.id, linked_order.id, ste.earning_order_id)';
        $orderCode = 'COALESCE(source_order.order_code, used_order.order_code, linked_order.order_code)';
        $orderStatus = 'COALESCE(source_order.status, used_order.status, linked_order.status)';
        $paymentStatus = 'COALESCE(source_order.status_payment, used_order.status_payment, linked_order.status_payment)';
        $ticketStatus = 'COALESCE(ste.earning_ticket_status, t.status)';
        $earningStatus = 'COALESCE(ste.earning_status, ste.status)';
        $generatedAt = 'COALESCE(ste.generated_at, ste.earned_at, t.purchased_at, t.created_at)';
        $scopeDate = 'COALESCE(source_order.delivered_at, used_order.delivered_at, linked_order.delivered_at, source_order.created_at, used_order.created_at, linked_order.created_at, ste.generated_at, ste.earned_at, t.purchased_at, t.created_at)';

        return [
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'ticket_status' => $ticketStatus,
            'earning_status' => $earningStatus,
            'generated_at' => $generatedAt,
            'scope_date' => $scopeDate,
        ];
    }

    private function specialistTicketBaseQuery(array $expressions)
    {
        $earningSubquery = DB::table('specialist_ticket_earnings as ste_base')
            ->select('ste_base.ticket_id')
            ->selectRaw('MAX(ste_base.specialist_id) as specialist_id')
            ->selectRaw('MAX(ste_base.order_id) as earning_order_id')
            ->selectRaw('MAX(ste_base.store_id) as earning_store_id')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'ticket_status') ? 'MAX(ste_base.ticket_status) as earning_ticket_status' : 'NULL as earning_ticket_status')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'earning_status') ? 'MAX(ste_base.earning_status) as earning_status' : 'NULL as earning_status')
            ->selectRaw('MAX(ste_base.status) as status')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'is_eligible') ? 'MAX(ste_base.is_eligible) as is_eligible' : 'NULL as is_eligible')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'fee_per_ticket') ? 'MAX(ste_base.fee_per_ticket) as fee_per_ticket' : 'NULL as fee_per_ticket')
            ->selectRaw('COALESCE(SUM(ste_base.earning_amount), 0) as earning_amount')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'reason_not_eligible') ? 'MAX(ste_base.reason_not_eligible) as reason_not_eligible' : 'NULL as reason_not_eligible')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'generated_at') ? 'MAX(ste_base.generated_at) as generated_at' : 'NULL as generated_at')
            ->selectRaw('MAX(ste_base.earned_at) as earned_at')
            ->groupBy('ste_base.ticket_id');

        if (AdminCmsAccess::isSale()) {
            $earningSubquery->where('ste_base.specialist_id', auth()->id());
        }

        return DB::table('tickets as t')
            ->leftJoinSub($earningSubquery, 'ste', function ($join): void {
                $join->on('ste.ticket_id', '=', 't.id');
            })
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('orders as source_order', function ($join): void {
                $join->on('source_order.id', '=', 't.source_id')
                    ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['ORDER', 'ORDER_REWARD', 'ORDER_REDEEM']);
            })
            ->leftJoin('orders as used_order', 'used_order.id', '=', 't.used_in_order_id')
            ->leftJoin('order_tickets as ot', 'ot.ticket_id', '=', 't.id')
            ->leftJoin('orders as linked_order', 'linked_order.id', '=', 'ot.order_id')
            ->leftJoin('stores as source_store', function ($join): void {
                $join->on('source_store.id', '=', 't.source_id')
                    ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['STORE']);
            })
            ->leftJoin('stores as s', function ($join) use ($expressions): void {
                $join->on('s.id', '=', DB::raw($expressions['store_id']));
            })
            ->select([
                't.id',
                't.ticket_code',
                't.user_id',
                't.status as raw_ticket_status',
                't.ticket_type_code',
                't.value_amount',
                't.purchased_price',
                't.locked_by',
                't.lock_expires_at',
                't.expired_at',
                't.source_type',
                'u.phone as user_phone',
                'u.email as user_email',
                's.name as store_name',
                's.store_code',
            ])
            ->selectRaw("COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), 'N/A') as owner_name")
            ->selectRaw($expressions['store_id'] . ' as store_id')
            ->selectRaw($expressions['order_id'] . ' as order_id')
            ->selectRaw($expressions['order_code'] . ' as order_code')
            ->selectRaw($expressions['order_status'] . ' as order_status')
            ->selectRaw($expressions['payment_status'] . ' as status_payment')
            ->selectRaw($expressions['ticket_status'] . ' as ticket_status')
            ->selectRaw($expressions['earning_status'] . ' as earning_status')
            ->selectRaw($expressions['generated_at'] . ' as generated_at')
            ->selectRaw('ste.specialist_id as earning_specialist_id')
            ->selectRaw('ste.is_eligible as is_eligible')
            ->selectRaw('ste.fee_per_ticket as fee_per_ticket')
            ->selectRaw('COALESCE(ste.earning_amount, 0) as earning_amount')
            ->selectRaw('ste.reason_not_eligible as reason_not_eligible');
    }

    private function applySpecialistTicketScope($query, string $storeExpression, string $scopeDateExpression): void
    {
        if (! AdminCmsAccess::isSale()) {
            return;
        }

        $storeIds = AdminCmsAccess::assignedStoreIds();
        if (empty($storeIds)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_from')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            $query->whereExists(function ($assignment) use ($storeExpression, $scopeDateExpression): void {
                $assignment->selectRaw('1')
                    ->from('specialist_store_assignments as ssa')
                    ->where('ssa.specialist_id', auth()->id())
                    ->whereRaw('ssa.store_id = ' . $storeExpression)
                    ->whereRaw('ssa.assigned_from <= ' . $scopeDateExpression)
                    ->where(function ($window) use ($scopeDateExpression): void {
                        $window->whereNull('ssa.assigned_to')
                            ->orWhereRaw('ssa.assigned_to >= ' . $scopeDateExpression);
                    });
            });

            return;
        }

        $query->whereIn(DB::raw($storeExpression), $storeIds);
    }

    private function applySpecialistTicketFilters($query, array $filters, array $expressions): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ($filters['search']['value'] ?? '')));
        if ($keyword !== '') {
            $query->where(function ($scope) use ($keyword, $expressions): void {
                $scope->where('t.ticket_code', 'like', '%' . $keyword . '%')
                    ->orWhere('t.id', 'like', '%' . $keyword . '%')
                    ->orWhere('u.full_name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.phone', 'like', '%' . $keyword . '%')
                    ->orWhere('s.name', 'like', '%' . $keyword . '%')
                    ->orWhere('s.store_code', 'like', '%' . $keyword . '%')
                    ->orWhereRaw($expressions['order_code'] . ' like ?', ['%' . $keyword . '%']);
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all' && isset(self::SPECIALIST_TICKET_STATUS_FILTERS[$status])) {
            $query->whereIn(
                DB::raw('UPPER(COALESCE(' . $expressions['ticket_status'] . ", ''))"),
                self::SPECIALIST_TICKET_STATUS_FILTERS[$status]
            );
        }

        if (!empty($filters['store_id'])) {
            $query->whereRaw($expressions['store_id'] . ' = ?', [(string) $filters['store_id']]);
        }

        if (!empty($filters['date_from'])) {
            $query->whereRaw('DATE(' . $expressions['generated_at'] . ') >= ?', [$filters['date_from']]);
        }

        if (!empty($filters['date_to'])) {
            $query->whereRaw('DATE(' . $expressions['generated_at'] . ') <= ?', [$filters['date_to']]);
        }
    }

    private function applySpecialistTicketOrdering($query, array $orders, array $expressions): void
    {
        $columns = [
            0 => 't.ticket_code',
            1 => 's.name',
            2 => $expressions['order_code'],
            3 => "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), 'N/A')",
            4 => $expressions['ticket_status'],
            5 => $expressions['generated_at'],
            6 => 'ste.earning_amount',
        ];

        if (empty($orders)) {
            $query->orderByRaw($expressions['generated_at'] . ' desc');

            return;
        }

        foreach ($orders as $order) {
            $column = (int) ($order['column'] ?? 5);
            $direction = ($order['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

            if (isset($columns[$column])) {
                $query->orderByRaw($columns[$column] . ' ' . $direction);
            }
        }
    }

    private function countSpecialistTickets($query): int
    {
        return (int) (clone $query)->count();
    }

    private function specialistTicketStatusInfo($ticket): array
    {
        $status = strtoupper(trim((string) ($ticket->ticket_status ?: $ticket->raw_ticket_status ?: '')));

        foreach (self::SPECIALIST_TICKET_STATUS_FILTERS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return [
                    'group' => $group,
                    'code' => $status,
                    'label' => $this->specialistTicketStatusOptions()[$group] ?? $status,
                    'class' => $this->specialistTicketStatusClass($group),
                ];
            }
        }

        return [
            'group' => 'generated',
            'code' => $status !== '' ? $status : 'UNKNOWN',
            'label' => $status !== '' ? $status : 'Không xác định',
            'class' => 'bg-secondary',
        ];
    }

    private function specialistTicketEligibility($ticket, array $statusInfo, int $activeComplaintCount, bool $hasRefund): array
    {
        if ($ticket->is_eligible !== null) {
            $eligible = (bool) $ticket->is_eligible;

            return [
                'eligible' => $eligible,
                'amount' => (float) ($ticket->earning_amount ?? 0),
                'reason' => $eligible
                    ? 'Theo bảng thu nhập chuyên viên'
                    : ((string) ($ticket->reason_not_eligible ?? '') ?: 'Không đủ điều kiện theo bảng thu nhập'),
                'needs_negative_adjustment' => false,
            ];
        }

        $group = (string) $statusInfo['group'];
        $reasons = [];

        if (in_array($group, ['held', 'disputed'], true)) {
            $reasons[] = 'Ticket treo/tranh chấp không tính thu nhập';
        } elseif (in_array($group, ['cancelled', 'refunded_cancelled', 'fraud_cancelled', 'expired'], true)) {
            $reasons[] = 'Ticket hủy/hoàn/hết hạn không tính thu nhập';
        } elseif (in_array($group, ['generated', 'pending_confirmation'], true)) {
            $reasons[] = 'Ticket chưa đủ điều kiện xác nhận';
        }

        if ($activeComplaintCount > 0) {
            $reasons[] = 'Có khiếu nại/tranh chấp chưa xử lý';
        }

        if ($hasRefund || strtolower((string) ($ticket->status_payment ?? '')) === 'refunded') {
            $reasons[] = 'Đơn liên quan đã hoàn tiền';
        }

        if (!empty($ticket->order_id)) {
            $orderStatus = strtolower(trim((string) ($ticket->order_status ?? '')));
            $paymentStatus = strtolower(trim((string) ($ticket->status_payment ?? '')));

            if (! in_array($orderStatus, self::SPECIALIST_SUCCESS_ORDER_STATUSES, true)) {
                $reasons[] = 'Đơn liên quan chưa hoàn thành';
            }

            if ($paymentStatus !== 'paid') {
                $reasons[] = 'Đơn liên quan chưa thanh toán thành công';
            }
        }

        $eligible = empty($reasons) && $group === 'eligible';
        $fee = (float) ($ticket->fee_per_ticket ?? 0);
        if ($fee <= 0) {
            $fee = $this->feeConfigService->feeFor(
                (int) ($ticket->earning_specialist_id ?? 0) ?: (AdminCmsAccess::isSale() ? (int) auth()->id() : null),
                (string) ($ticket->store_id ?? ''),
                $ticket->generated_at ?? null,
                null,
                null,
                (string) ($ticket->ticket_type_code ?? '')
            );
        }
        if ($fee <= 0) {
            $fee = SpecialistFeeConfigService::DEFAULT_FEE_PER_TICKET;
        }
        $amount = (float) ($ticket->earning_amount ?? 0);
        if ($eligible && $amount == 0.0) {
            $amount = $fee;
        }

        return [
            'eligible' => $eligible,
            'amount' => $eligible ? $amount : min(0, $amount),
            'reason' => $eligible ? 'Ticket eligible được tính thu nhập' : implode('; ', array_unique($reasons)),
            'needs_negative_adjustment' => ! $eligible
                && in_array($group, ['cancelled', 'refunded_cancelled', 'fraud_cancelled'], true)
                && (float) ($ticket->earning_amount ?? 0) >= 0,
        ];
    }

    private function activeComplaintCountsForOrders(array $orderIds): array
    {
        if (
            empty($orderIds)
            || ! Schema::hasTable('complaints')
            || ! Schema::hasColumn('complaints', 'order_id')
        ) {
            return [];
        }

        $query = DB::table('complaints')
            ->select('order_id')
            ->selectRaw('COUNT(*) as total')
            ->whereIn('order_id', $orderIds);

        if (Schema::hasColumn('complaints', 'status')) {
            $query->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), self::SPECIALIST_RESOLVED_COMPLAINT_STATUSES);
        }

        return $query->groupBy('order_id')
            ->pluck('total', 'order_id')
            ->mapWithKeys(static fn ($total, $orderId): array => [(string) $orderId => (int) $total])
            ->all();
    }

    private function refundedOrderIds(array $orderIds): array
    {
        if (
            empty($orderIds)
            || ! Schema::hasTable('refunds')
            || ! Schema::hasColumn('refunds', 'source_id')
        ) {
            return [];
        }

        return DB::table('refunds')
            ->whereIn('source_id', $orderIds)
            ->pluck('source_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function specialistTicketStatusOptions(): array
    {
        return [
            'all' => 'Tất cả',
            'generated' => 'Generated',
            'eligible' => 'Eligible',
            'pending_confirmation' => 'Pending confirmation',
            'held' => 'Held',
            'disputed' => 'Disputed',
            'cancelled' => 'Cancelled',
            'refunded_cancelled' => 'Refunded cancelled',
            'fraud_cancelled' => 'Fraud cancelled',
            'expired' => 'Expired',
        ];
    }

    private function specialistTicketStatusClass(string $group): string
    {
        return [
            'generated' => 'bg-secondary',
            'eligible' => 'bg-success',
            'pending_confirmation' => 'bg-info text-dark',
            'held' => 'bg-warning text-dark',
            'disputed' => 'bg-danger',
            'cancelled' => 'bg-dark',
            'refunded_cancelled' => 'bg-primary',
            'fraud_cancelled' => 'bg-danger',
            'expired' => 'bg-secondary',
        ][$group] ?? 'bg-secondary';
    }

    private function specialistTicketStoreOptions()
    {
        if (! Schema::hasTable('stores')) {
            return collect();
        }

        $query = DB::table('stores')
            ->select(['id', 'name', 'store_code'])
            ->orderBy('name');

        if (AdminCmsAccess::isSale()) {
            $storeIds = AdminCmsAccess::assignedStoreIds();
            if (empty($storeIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $storeIds);
            }
        }

        return $query->get();
    }

    private function formatSpecialistTicketCode($ticket): string
    {
        return '<div class="fw-semibold">' . e((string) $ticket->ticket_code) . '</div>'
            . '<small class="text-muted">ID: ' . e((string) $ticket->id) . '</small>';
    }

    private function formatSpecialistTicketStore($ticket): string
    {
        if (empty($ticket->store_id)) {
            return '<span class="text-muted">Chưa xác định</span>';
        }

        $code = !empty($ticket->store_code)
            ? '<div><small class="text-muted">' . e((string) $ticket->store_code) . '</small></div>'
            : '';

        return '<div class="fw-semibold">' . e((string) ($ticket->store_name ?? 'N/A')) . '</div>' . $code;
    }

    private function formatSpecialistTicketOrder($ticket): string
    {
        if (empty($ticket->order_id)) {
            return '<span class="text-muted">Không gắn đơn</span>';
        }

        return '<div class="fw-semibold">' . e((string) ($ticket->order_code ?: $ticket->order_id)) . '</div>'
            . '<small class="text-muted">' . e((string) $ticket->order_status) . ' / ' . e((string) $ticket->status_payment) . '</small>';
    }

    private function formatSpecialistTicketOwner($ticket): string
    {
        $phone = trim((string) ($ticket->user_phone ?? ''));
        $maskedPhone = mb_strlen($phone) > 5
            ? mb_substr($phone, 0, 3) . str_repeat('*', max(3, mb_strlen($phone) - 5)) . mb_substr($phone, -2)
            : $phone;

        return '<div class="fw-semibold">' . e((string) ($ticket->owner_name ?? 'N/A')) . '</div>'
            . ($maskedPhone !== '' ? '<small class="text-muted">' . e($maskedPhone) . '</small>' : '');
    }

    private function formatSpecialistTicketStatus(array $statusInfo, string $rawStatus): string
    {
        return '<span class="badge ' . e($statusInfo['class']) . '">' . e($statusInfo['label']) . '</span>'
            . '<div><small class="text-muted">Mã: ' . e($rawStatus !== '' ? $rawStatus : (string) $statusInfo['code']) . '</small></div>';
    }

    private function formatSpecialistIncomeEligibility(array $eligibility): string
    {
        return $eligibility['eligible']
            ? '<span class="badge bg-success">Có tính</span>'
            : '<span class="badge bg-danger">Không tính</span>';
    }

    private function formatSpecialistTicketAmount(array $eligibility): string
    {
        $amount = (float) ($eligibility['amount'] ?? 0);
        $class = $amount < 0 ? 'text-danger' : ($amount > 0 ? 'text-success' : 'text-muted');

        return '<span class="' . $class . '">' . number_format($amount, 0, ',', '.') . ' VND</span>';
    }

    private function formatSpecialistTicketReason($ticket, array $statusInfo, array $eligibility): string
    {
        $reason = trim((string) ($ticket->reason_not_eligible ?? ''));
        if ($reason === '') {
            $reason = trim((string) ($eligibility['reason'] ?? ''));
        }

        if ($reason === '' && in_array($statusInfo['group'], ['held', 'disputed'], true)) {
            $reason = 'Ticket đang treo/tranh chấp.';
        }

        if (!empty($ticket->locked_by) && in_array($statusInfo['group'], ['held', 'disputed'], true)) {
            $reason .= ($reason !== '' ? ' ' : '') . 'Khóa bởi: ' . $ticket->locked_by . '.';
        }

        if (!empty($ticket->lock_expires_at) && in_array($statusInfo['group'], ['held', 'disputed'], true)) {
            $reason .= ' Hết hạn khóa: ' . $this->formatDateTime($ticket->lock_expires_at) . '.';
        }

        if (!empty($eligibility['needs_negative_adjustment'])) {
            $reason .= ($reason !== '' ? ' ' : '') . 'Nếu ticket đã ghi nhận thu nhập trước đó thì cần tạo điều chỉnh âm.';
        }

        return $reason !== ''
            ? '<small>' . e($reason) . '</small>'
            : '<span class="text-muted">N/A</span>';
    }

    private function formatDateTime($value): string
    {
        if (empty($value)) {
            return '<span class="text-muted">N/A</span>';
        }

        try {
            return e(\Carbon\Carbon::parse($value)->format('d/m/Y H:i'));
        } catch (\Throwable) {
            return e((string) $value);
        }
    }

    private function applySaleTicketScope($query, string $ticketAlias): void
    {
        if (! AdminCmsAccess::isSale()) {
            return;
        }

        $saleId = (int) auth()->id();
        $ticketPrefix = $ticketAlias !== '' ? $ticketAlias . '.' : '';

        $query->where(function ($scopeQuery) use ($saleId, $ticketPrefix) {
            if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'sale_id')) {
                $scopeQuery->whereExists(function ($storeScope) use ($saleId, $ticketPrefix) {
                    $storeScope->selectRaw('1')
                        ->from('stores as ticket_scope_stores')
                        ->whereColumn('ticket_scope_stores.id', $ticketPrefix . 'source_id')
                        ->whereIn($ticketPrefix . 'source_type', ['store', 'STORE'])
                        ->where('ticket_scope_stores.sale_id', $saleId);
                });
            }

            if (
                Schema::hasTable('orders')
                && Schema::hasTable('stores')
                && Schema::hasColumn('orders', 'store_id')
                && Schema::hasColumn('stores', 'sale_id')
                && Schema::hasColumn('tickets', 'used_in_order_id')
            ) {
                $scopeQuery->orWhereExists(function ($orderScope) use ($saleId, $ticketPrefix) {
                    $orderScope->selectRaw('1')
                        ->from('orders as ticket_scope_orders')
                        ->join('stores as ticket_scope_order_stores', 'ticket_scope_order_stores.id', '=', 'ticket_scope_orders.store_id')
                        ->whereColumn('ticket_scope_orders.id', $ticketPrefix . 'used_in_order_id')
                        ->where('ticket_scope_order_stores.sale_id', $saleId);
                });
            }
        });
    }
}
