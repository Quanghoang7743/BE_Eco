<?php

namespace App\Services;

use App\Models\User;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialistIncomeService
{
    private const DEFAULT_FEE_PER_TICKET = 100;
    private const SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const RESOLVED_COMPLAINT_STATUSES = ['resolved', 'closed', 'rejected', 'cancelled', 'canceled', 'done'];
    private const TICKET_STATUS_FILTERS = [
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
    private const EARNING_STATUS_LABELS = [
        'pending_settlement' => 'Chờ đối soát',
        'approved' => 'Đã duyệt',
        'paid' => 'Đã thanh toán',
        'held' => 'Đang treo',
        'cancelled' => 'Đã hủy',
        'adjusted' => 'Điều chỉnh',
    ];

    public function __construct(
        private readonly SpecialistFeeConfigService $feeConfigService,
        private readonly TicketPricingService $ticketPricingService
    ) {
    }

    public function report(Request $request, ?User $user = null): array
    {
        $user ??= AdminCmsAccess::currentUser();
        $filters = $this->filters($request, $user);
        $currentFee = $this->feeConfigService->currentFeePerTicket(
            $filters['specialist_id'],
            null,
            $filters['ticket_type_code'] !== '' ? $filters['ticket_type_code'] : SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE
        );

        $rows = $this->ticketIncomeRows($filters);
        $orderIds = $rows
            ->pluck('order_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $complaintCounts = $this->activeComplaintCountsForOrders($orderIds);
        $refundedOrderIds = $this->refundedOrderIds($orderIds);

        $items = $rows->map(function ($row) use ($complaintCounts, $refundedOrderIds, $currentFee, $filters): array {
            $orderId = (string) ($row->order_id ?? '');

            return $this->normalizeIncomeItem(
                $row,
                (int) ($complaintCounts[$orderId] ?? 0),
                in_array($orderId, $refundedOrderIds, true),
                $currentFee,
                $filters['specialist_id']
            );
        });

        if ($filters['status'] !== 'all') {
            $items = $items->filter(fn (array $item): bool => $item['earning_status'] === $filters['status'])->values();
        }

        return [
            'filters' => $filters,
            'isSaleUser' => AdminCmsAccess::isSale($user),
            'summary' => $this->summary($items, $currentFee),
            'dailyBreakdown' => $this->dailyBreakdown($items),
            'storeBreakdown' => $this->storeBreakdown($items),
            'ticketTypeBreakdown' => $this->ticketTypeBreakdown($items),
            'periodBreakdown' => $this->periodBreakdown($items, $filters),
            'statusBreakdown' => $this->statusBreakdown($items),
            'storeOptions' => $this->storeOptions($filters['specialist_id']),
            'specialistOptions' => $this->specialistOptions($user),
            'ticketTypeOptions' => $this->ticketTypeOptions(),
            'statusOptions' => ['all' => 'Tất cả trạng thái'] + self::EARNING_STATUS_LABELS,
            'totalTicketsInRange' => $items->count(),
        ];
    }

    public function currentFeePerTicket(): float
    {
        return $this->feeConfigService->currentFeePerTicket();
    }

    private function filters(Request $request, ?User $user): array
    {
        $today = now('Asia/Ho_Chi_Minh');
        $dateFrom = (string) ($request->input('date_from') ?: $today->copy()->startOfMonth()->toDateString());
        $dateTo = (string) ($request->input('date_to') ?: $today->toDateString());

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $status = trim((string) $request->input('status', 'all'));
        if ($status !== 'all' && ! array_key_exists($status, self::EARNING_STATUS_LABELS)) {
            $status = 'all';
        }

        $specialistId = AdminCmsAccess::isSale($user)
            ? (int) $user->id
            : (int) $request->input('specialist_id', 0);

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'store_id' => trim((string) $request->input('store_id', '')),
            'ticket_type_code' => strtoupper(trim((string) $request->input('ticket_type_code', ''))),
            'status' => $status,
            'specialist_id' => $specialistId > 0 ? $specialistId : null,
        ];
    }

    private function ticketIncomeRows(array $filters): Collection
    {
        if ($this->canUseStoredTicketEarnings()) {
            $storedRows = $this->storedTicketIncomeRows($filters);
            if ($storedRows->isNotEmpty() || $filters['specialist_id'] === null) {
                return $storedRows;
            }
        }

        if (! Schema::hasTable('tickets') || ! Schema::hasTable('orders') || ! Schema::hasTable('stores')) {
            return collect();
        }

        $expressions = $this->ticketExpressions();
        $query = $this->ticketIncomeBaseQuery($expressions, $filters['specialist_id']);

        if ($filters['specialist_id'] !== null) {
            $storeIds = $this->assignedStoreIdsForSpecialist((int) $filters['specialist_id']);
            if (empty($storeIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn(DB::raw($expressions['store_id']), $storeIds);
                $this->applySpecialistAssignmentScope($query, $expressions['store_id'], $expressions['scope_date'], $filters['specialist_id']);
            }
        }

        if ($filters['store_id'] !== '') {
            $query->whereRaw($expressions['store_id'] . ' = ?', [$filters['store_id']]);
        }

        if ($filters['ticket_type_code'] !== '') {
            $query->whereRaw("UPPER(COALESCE(t.ticket_type_code, 'TICKET_DEFAULT')) = ?", [
                $this->feeConfigService->normalizeTicketTypeCode($filters['ticket_type_code']),
            ]);
        }

        $query->whereRaw('DATE(' . $expressions['generated_at'] . ') >= ?', [$filters['date_from']])
            ->whereRaw('DATE(' . $expressions['generated_at'] . ') <= ?', [$filters['date_to']])
            ->orderByRaw($expressions['generated_at'] . ' desc');

        return $query->get();
    }

    private function canUseStoredTicketEarnings(): bool
    {
        return Schema::hasTable('specialist_ticket_earnings')
            && Schema::hasColumn('specialist_ticket_earnings', 'specialist_id')
            && Schema::hasColumn('specialist_ticket_earnings', 'ticket_id');
    }

    private function storedTicketIncomeRows(array $filters): Collection
    {
        if (
            ! Schema::hasTable('specialist_ticket_earnings')
            || ! Schema::hasTable('tickets')
            || ! Schema::hasTable('orders')
            || ! Schema::hasTable('stores')
            || ! Schema::hasTable('order_tickets')
            || ! Schema::hasTable('specialist_settlements')
        ) {
            return collect();
        }

        $expressions = $this->storedTicketExpressions();
        $linkedOrderSubquery = DB::table('order_tickets')
            ->select('ticket_id')
            ->selectRaw('MAX(order_id) as order_id')
            ->groupBy('ticket_id');

        $query = DB::table('specialist_ticket_earnings as ste')
            ->leftJoin('tickets as t', 't.id', '=', 'ste.ticket_id')
            ->leftJoin('orders as earning_order', 'earning_order.id', '=', 'ste.order_id')
            ->leftJoin('orders as source_order', function ($join): void {
                $join->on('source_order.id', '=', 't.source_id')
                    ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['ORDER', 'ORDER_REWARD', 'ORDER_REDEEM']);
            })
            ->leftJoin('orders as used_order', 'used_order.id', '=', 't.used_in_order_id')
            ->leftJoinSub($linkedOrderSubquery, 'ot', function ($join): void {
                $join->on('ot.ticket_id', '=', 't.id');
            })
            ->leftJoin('orders as linked_order', 'linked_order.id', '=', 'ot.order_id')
            ->leftJoin('stores as source_store', function ($join): void {
                $join->on('source_store.id', '=', 't.source_id')
                    ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['STORE']);
            })
            ->leftJoin('stores as s', function ($join) use ($expressions): void {
                $join->on('s.id', '=', DB::raw($expressions['store_id']));
            })
            ->leftJoin('specialist_settlements as ss', 'ss.id', '=', 'ste.settlement_id')
            ->selectRaw('COALESCE(t.id, ste.ticket_id, ste.id) as id')
            ->selectRaw("COALESCE(t.ticket_code, CONCAT('STE-', ste.id)) as ticket_code")
            ->selectRaw('t.status as raw_ticket_status')
            ->selectRaw("COALESCE(t.ticket_type_code, JSON_UNQUOTE(JSON_EXTRACT(ste.metadata, '$.ticket_type_code')), 'TICKET_DEFAULT') as ticket_type_code")
            ->selectRaw('s.name as store_name')
            ->selectRaw('s.store_code as store_code')
            ->selectRaw($expressions['store_id'] . ' as store_id')
            ->selectRaw($expressions['order_id'] . ' as order_id')
            ->selectRaw($expressions['order_code'] . ' as order_code')
            ->selectRaw($expressions['order_status'] . ' as order_status')
            ->selectRaw($expressions['payment_status'] . ' as status_payment')
            ->selectRaw($expressions['ticket_status'] . ' as ticket_status')
            ->selectRaw($expressions['earning_status'] . ' as earning_status')
            ->selectRaw($expressions['generated_at'] . ' as generated_at')
            ->selectRaw('ste.specialist_id as earning_specialist_id')
            ->selectRaw('ste.settlement_id as settlement_id')
            ->selectRaw('ss.status as settlement_status')
            ->selectRaw('ste.is_eligible as is_eligible')
            ->selectRaw('COALESCE(ste.fee_per_ticket, ' . self::DEFAULT_FEE_PER_TICKET . ') as fee_per_ticket')
            ->selectRaw('COALESCE(ste.earning_amount, 0) as earning_amount')
            ->selectRaw('ste.reason_not_eligible as reason_not_eligible')
            ->selectRaw('COALESCE(ste.approved_at, ss.approved_at) as approved_at')
            ->selectRaw('COALESCE(ste.paid_at, ss.paid_at) as paid_at');

        if ($filters['specialist_id'] !== null) {
            $query->where('ste.specialist_id', $filters['specialist_id']);
        }

        if ($filters['store_id'] !== '') {
            $query->whereRaw($expressions['store_id'] . ' = ?', [$filters['store_id']]);
        }

        if ($filters['ticket_type_code'] !== '') {
            $query->whereRaw("UPPER(COALESCE(t.ticket_type_code, JSON_UNQUOTE(JSON_EXTRACT(ste.metadata, '$.ticket_type_code')), 'TICKET_DEFAULT')) = ?", [
                $this->feeConfigService->normalizeTicketTypeCode($filters['ticket_type_code']),
            ]);
        }

        return $query
            ->whereRaw('DATE(' . $expressions['generated_at'] . ') >= ?', [$filters['date_from']])
            ->whereRaw('DATE(' . $expressions['generated_at'] . ') <= ?', [$filters['date_to']])
            ->orderByRaw($expressions['generated_at'] . ' desc')
            ->get();
    }

    private function storedTicketExpressions(): array
    {
        $storeId = 'COALESCE(ste.store_id, earning_order.store_id, source_order.store_id, used_order.store_id, linked_order.store_id, source_store.id)';
        $orderId = 'COALESCE(ste.order_id, earning_order.id, source_order.id, used_order.id, linked_order.id)';
        $orderCode = 'COALESCE(earning_order.order_code, source_order.order_code, used_order.order_code, linked_order.order_code)';
        $orderStatus = 'COALESCE(earning_order.status, source_order.status, used_order.status, linked_order.status)';
        $paymentStatus = 'COALESCE(earning_order.status_payment, source_order.status_payment, used_order.status_payment, linked_order.status_payment)';
        $ticketStatus = 'COALESCE(ste.ticket_status, t.status)';
        $earningStatus = 'COALESCE(ss.status, ste.earning_status, ste.status)';
        $generatedAt = 'COALESCE(ste.generated_at, ste.earned_at, t.purchased_at, t.created_at, ste.created_at)';

        return [
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'ticket_status' => $ticketStatus,
            'earning_status' => $earningStatus,
            'generated_at' => $generatedAt,
        ];
    }

    private function ticketExpressions(): array
    {
        $storeId = 'COALESCE(source_order.store_id, used_order.store_id, linked_order.store_id, ste.earning_store_id, source_store.id)';
        $orderId = 'COALESCE(source_order.id, used_order.id, linked_order.id, ste.earning_order_id)';
        $orderCode = 'COALESCE(source_order.order_code, used_order.order_code, linked_order.order_code)';
        $orderStatus = 'COALESCE(source_order.status, used_order.status, linked_order.status)';
        $paymentStatus = 'COALESCE(source_order.status_payment, used_order.status_payment, linked_order.status_payment)';
        $ticketStatus = 'COALESCE(ste.earning_ticket_status, t.status)';
        $earningStatus = 'COALESCE(ste.settlement_status, ste.earning_status, ste.status)';
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

    private function ticketIncomeBaseQuery(array $expressions, ?int $specialistId)
    {
        $earningSubquery = $this->earningSubquery($specialistId);

        return DB::table('tickets as t')
            ->leftJoinSub($earningSubquery, 'ste', function ($join): void {
                $join->on('ste.ticket_id', '=', 't.id');
            })
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
                't.status as raw_ticket_status',
                't.ticket_type_code',
                's.name as store_name',
                's.store_code',
            ])
            ->selectRaw($expressions['store_id'] . ' as store_id')
            ->selectRaw($expressions['order_id'] . ' as order_id')
            ->selectRaw($expressions['order_code'] . ' as order_code')
            ->selectRaw($expressions['order_status'] . ' as order_status')
            ->selectRaw($expressions['payment_status'] . ' as status_payment')
            ->selectRaw($expressions['ticket_status'] . ' as ticket_status')
            ->selectRaw($expressions['earning_status'] . ' as earning_status')
            ->selectRaw($expressions['generated_at'] . ' as generated_at')
            ->selectRaw('ste.specialist_id as earning_specialist_id')
            ->selectRaw('ste.settlement_id as settlement_id')
            ->selectRaw('ste.settlement_status as settlement_status')
            ->selectRaw('ste.is_eligible as is_eligible')
            ->selectRaw('ste.fee_per_ticket as fee_per_ticket')
            ->selectRaw('COALESCE(ste.earning_amount, 0) as earning_amount')
            ->selectRaw('ste.reason_not_eligible as reason_not_eligible')
            ->selectRaw('ste.approved_at as approved_at')
            ->selectRaw('ste.paid_at as paid_at');
    }

    private function earningSubquery(?int $specialistId)
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return DB::query()
                ->fromRaw('(select null as ticket_id, null as specialist_id, null as earning_order_id, null as earning_store_id, null as settlement_id, null as settlement_status, null as earning_ticket_status, null as earning_status, null as status, null as is_eligible, ' . self::DEFAULT_FEE_PER_TICKET . ' as fee_per_ticket, 0 as earning_amount, null as reason_not_eligible, null as generated_at, null as earned_at, null as approved_at, null as paid_at where 1 = 0) as empty_specialist_ticket_earnings');
        }

        $query = DB::table('specialist_ticket_earnings as ste_base');

        if (
            Schema::hasTable('specialist_settlements')
            && Schema::hasColumn('specialist_ticket_earnings', 'settlement_id')
        ) {
            $query->leftJoin('specialist_settlements as ss_base', 'ss_base.id', '=', 'ste_base.settlement_id');
        }

        if ($specialistId !== null && Schema::hasColumn('specialist_ticket_earnings', 'specialist_id')) {
            $query->where('ste_base.specialist_id', $specialistId);
        }

        $settlementStatusSelect = Schema::hasTable('specialist_settlements') && Schema::hasColumn('specialist_settlements', 'status')
            ? 'MAX(ss_base.status) as settlement_status'
            : 'NULL as settlement_status';
        $approvedAtSelect = Schema::hasTable('specialist_settlements') && Schema::hasColumn('specialist_settlements', 'approved_at')
            ? 'MAX(COALESCE(ste_base.approved_at, ss_base.approved_at)) as approved_at'
            : 'MAX(ste_base.approved_at) as approved_at';
        $paidAtSelect = Schema::hasTable('specialist_settlements') && Schema::hasColumn('specialist_settlements', 'paid_at')
            ? 'MAX(COALESCE(ste_base.paid_at, ss_base.paid_at)) as paid_at'
            : 'MAX(ste_base.paid_at) as paid_at';

        return $query
            ->select('ste_base.ticket_id')
            ->selectRaw('MAX(ste_base.specialist_id) as specialist_id')
            ->selectRaw('MAX(ste_base.order_id) as earning_order_id')
            ->selectRaw('MAX(ste_base.store_id) as earning_store_id')
            ->selectRaw('MAX(ste_base.settlement_id) as settlement_id')
            ->selectRaw($settlementStatusSelect)
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'ticket_status') ? 'MAX(ste_base.ticket_status) as earning_ticket_status' : 'NULL as earning_ticket_status')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'earning_status') ? 'MAX(ste_base.earning_status) as earning_status' : 'NULL as earning_status')
            ->selectRaw('MAX(ste_base.status) as status')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'is_eligible') ? 'MAX(ste_base.is_eligible) as is_eligible' : 'NULL as is_eligible')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'fee_per_ticket') ? 'MAX(ste_base.fee_per_ticket) as fee_per_ticket' : self::DEFAULT_FEE_PER_TICKET . ' as fee_per_ticket')
            ->selectRaw('COALESCE(SUM(ste_base.earning_amount), 0) as earning_amount')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'reason_not_eligible') ? 'MAX(ste_base.reason_not_eligible) as reason_not_eligible' : 'NULL as reason_not_eligible')
            ->selectRaw(Schema::hasColumn('specialist_ticket_earnings', 'generated_at') ? 'MAX(ste_base.generated_at) as generated_at' : 'NULL as generated_at')
            ->selectRaw('MAX(ste_base.earned_at) as earned_at')
            ->selectRaw($approvedAtSelect)
            ->selectRaw($paidAtSelect)
            ->whereNotNull('ste_base.ticket_id')
            ->groupBy('ste_base.ticket_id');
    }

    private function applySpecialistAssignmentScope($query, string $storeExpression, string $scopeDateExpression, int $specialistId): void
    {
        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_from')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            $query->whereExists(function ($assignment) use ($storeExpression, $scopeDateExpression, $specialistId): void {
                $assignment->selectRaw('1')
                    ->from('specialist_store_assignments as ssa')
                    ->where('ssa.specialist_id', $specialistId)
                    ->whereRaw('ssa.store_id = ' . $storeExpression)
                    ->whereRaw('ssa.assigned_from <= ' . $scopeDateExpression)
                    ->where(function ($window) use ($scopeDateExpression): void {
                        $window->whereNull('ssa.assigned_to')
                            ->orWhereRaw('ssa.assigned_to >= ' . $scopeDateExpression);
                    });
            });

            return;
        }

        $storeIds = $this->assignedStoreIdsForSpecialist($specialistId);
        if (empty($storeIds)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn(DB::raw($storeExpression), $storeIds);
    }

    private function normalizeIncomeItem($row, int $activeComplaintCount, bool $hasRefund, float $currentFee, ?int $fallbackSpecialistId): array
    {
        $ticketGroup = $this->ticketStatusGroup($row);
        $fee = (float) ($row->fee_per_ticket ?? 0);
        if ($fee <= 0) {
            $fee = $this->feeConfigService->feeFor(
                (int) ($row->earning_specialist_id ?? 0) ?: $fallbackSpecialistId,
                (string) ($row->store_id ?? ''),
                $row->generated_at ?? null,
                null,
                null,
                (string) ($row->ticket_type_code ?? '')
            );
        }
        if ($fee <= 0) {
            $fee = $currentFee;
        }

        $storedEligibility = $row->is_eligible;
        $reasons = [];

        if ($storedEligibility !== null) {
            $eligible = (bool) $storedEligibility;
            if (! $eligible) {
                $reasons[] = (string) ($row->reason_not_eligible ?? 'Không đủ điều kiện theo bảng thu nhập');
            }
        } else {
            $eligible = false;

            if (empty($row->order_id)) {
                $reasons[] = 'Ticket chưa gắn đơn hàng hoàn tất';
            } else {
                $orderStatus = strtolower(trim((string) ($row->order_status ?? '')));
                $paymentStatus = strtolower(trim((string) ($row->status_payment ?? '')));

                if (! in_array($orderStatus, self::SUCCESS_ORDER_STATUSES, true)) {
                    $reasons[] = 'Đơn hàng chưa hoàn tất';
                }

                if ($paymentStatus !== 'paid') {
                    $reasons[] = 'Đơn hàng chưa thanh toán thành công';
                }
            }

            if (in_array($ticketGroup, ['held', 'disputed'], true)) {
                $reasons[] = 'Ticket đang treo hoặc tranh chấp';
            } elseif (in_array($ticketGroup, ['cancelled', 'refunded_cancelled', 'fraud_cancelled', 'expired'], true)) {
                $reasons[] = 'Ticket đã hủy, hoàn hoặc hết hạn';
            } elseif (in_array($ticketGroup, ['generated', 'pending_confirmation'], true)) {
                $reasons[] = 'Ticket chưa chuyển eligible';
            }

            if ($activeComplaintCount > 0) {
                $reasons[] = 'Đơn hàng có khiếu nại/tranh chấp chưa xử lý';
            }

            if ($hasRefund || strtolower((string) ($row->status_payment ?? '')) === 'refunded') {
                $reasons[] = 'Đơn hàng đã hoàn tiền';
            }

            $eligible = empty($reasons) && $ticketGroup === 'eligible';
        }

        $rawAmount = (float) ($row->earning_amount ?? 0);
        $earningStatus = $this->earningStatus($row, $eligible, $ticketGroup);
        $earningAmount = $eligible ? ($rawAmount > 0 ? $rawAmount : $fee) : min(0, $rawAmount);
        $heldAmount = $earningStatus === 'held' || in_array($ticketGroup, ['held', 'disputed'], true)
            ? ($rawAmount > 0 ? $rawAmount : $fee)
            : 0;
        $cancelledAmount = $earningStatus === 'cancelled' || in_array($ticketGroup, ['cancelled', 'refunded_cancelled', 'fraud_cancelled', 'expired'], true)
            ? (abs($rawAmount < 0 ? $rawAmount : 0) ?: $fee)
            : 0;
        $generatedAt = $this->parseDate($row->generated_at ?? null);

        return [
            'ticket_id' => (string) ($row->id ?? ''),
            'ticket_code' => (string) ($row->ticket_code ?? ''),
            'ticket_type_code' => $this->feeConfigService->normalizeTicketTypeCode((string) ($row->ticket_type_code ?? '')),
            'store_id' => (string) ($row->store_id ?? ''),
            'store_name' => (string) ($row->store_name ?? 'Chưa xác định'),
            'store_code' => (string) ($row->store_code ?? ''),
            'order_id' => (string) ($row->order_id ?? ''),
            'order_code' => (string) ($row->order_code ?? ''),
            'generated_at' => $generatedAt,
            'day' => $generatedAt?->toDateString() ?? 'N/A',
            'month' => $generatedAt?->format('Y-m') ?? 'N/A',
            'ticket_status_group' => $ticketGroup,
            'earning_status' => $earningStatus,
            'earning_status_label' => self::EARNING_STATUS_LABELS[$earningStatus] ?? $earningStatus,
            'is_eligible' => $eligible,
            'fee_per_ticket' => $fee,
            'earning_amount' => $earningAmount,
            'held_amount' => $heldAmount,
            'cancelled_amount' => $cancelledAmount,
            'reason' => implode('; ', array_filter(array_unique($reasons))),
        ];
    }

    private function ticketStatusGroup($row): string
    {
        $status = strtoupper(trim((string) (($row->ticket_status ?? null) ?: ($row->raw_ticket_status ?? ''))));

        foreach (self::TICKET_STATUS_FILTERS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return 'generated';
    }

    private function earningStatus($row, bool $eligible, string $ticketGroup): string
    {
        if (in_array($ticketGroup, ['held', 'disputed'], true)) {
            return 'held';
        }

        if (in_array($ticketGroup, ['cancelled', 'refunded_cancelled', 'fraud_cancelled', 'expired'], true)) {
            return 'cancelled';
        }

        $status = $this->normalizeStatus((string) (($row->earning_status ?? null) ?: ($row->settlement_status ?? '')));

        if (! empty($row->paid_at) || $status === 'paid') {
            return 'paid';
        }

        if (! empty($row->approved_at) || $status === 'approved') {
            return 'approved';
        }

        if (in_array($status, ['held', 'disputed'], true)) {
            return 'held';
        }

        if (in_array($status, ['cancelled', 'canceled', 'refunded', 'fraud_cancelled', 'refunded_cancelled'], true)) {
            return 'cancelled';
        }

        if ($status === 'adjusted') {
            return 'adjusted';
        }

        return $eligible ? 'pending_settlement' : 'cancelled';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'pending', 'estimated', 'draft', 'pending_approval', 'awaiting_approval', 'waiting_approval', 'waiting_review', 'pending_settlement' => 'pending_settlement',
            'settled' => 'paid',
            default => $status,
        };
    }

    private function summary(Collection $items, float $currentFee): array
    {
        $eligibleItems = $items->where('is_eligible', true);

        return [
            'total_eligible_tickets' => $eligibleItems->count(),
            'fee_per_ticket' => $currentFee,
            'estimated_income' => $eligibleItems->sum('earning_amount'),
            'pending_settlement_income' => $eligibleItems->where('earning_status', 'pending_settlement')->sum('earning_amount'),
            'approved_income' => $eligibleItems->where('earning_status', 'approved')->sum('earning_amount'),
            'paid_income' => $eligibleItems->where('earning_status', 'paid')->sum('earning_amount'),
            'held_income' => $items->sum('held_amount'),
            'cancelled_income' => $items->sum('cancelled_amount'),
        ];
    }

    private function dailyBreakdown(Collection $items): Collection
    {
        return $items
            ->groupBy('day')
            ->map(fn (Collection $group, string $day): array => $this->breakdownRow($group, $day))
            ->sortKeysDesc()
            ->values();
    }

    private function storeBreakdown(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (array $item): string => $item['store_id'] !== '' ? $item['store_id'] : 'unknown')
            ->map(function (Collection $group): array {
                $first = $group->first();
                $label = trim(($first['store_name'] ?? 'Chưa xác định') . (! empty($first['store_code']) ? ' - ' . $first['store_code'] : ''));

                return $this->breakdownRow($group, $label !== '' ? $label : 'Chưa xác định');
            })
            ->sortByDesc('income')
            ->values();
    }

    private function ticketTypeBreakdown(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (array $item): string => $item['ticket_type_code'] ?: SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE)
            ->map(function (Collection $group, string $ticketTypeCode): array {
                $row = $this->breakdownRow($group, $ticketTypeCode);
                $fees = $group
                    ->pluck('fee_per_ticket')
                    ->filter(fn ($fee): bool => (float) $fee > 0)
                    ->map(fn ($fee): float => (float) $fee)
                    ->unique()
                    ->values();

                $row['ticket_type_code'] = $ticketTypeCode;
                $row['fee_label'] = $fees->count() === 1
                    ? number_format((float) $fees->first(), 0, ',', '.') . ' đ'
                    : 'Nhiều mức phí';

                return $row;
            })
            ->sortByDesc('income')
            ->values();
    }

    private function statusBreakdown(Collection $items): Collection
    {
        return $items
            ->groupBy('earning_status')
            ->map(function (Collection $group, string $status): array {
                $amount = match ($status) {
                    'held' => $group->sum('held_amount'),
                    'cancelled' => $group->sum('cancelled_amount'),
                    default => $group->where('is_eligible', true)->sum('earning_amount'),
                };

                return [
                    'label' => self::EARNING_STATUS_LABELS[$status] ?? $status,
                    'total_tickets' => $group->count(),
                    'eligible_tickets' => $group->where('is_eligible', true)->count(),
                    'income' => $amount,
                    'held_amount' => $group->sum('held_amount'),
                    'cancelled_amount' => $group->sum('cancelled_amount'),
                ];
            })
            ->sortByDesc('income')
            ->values();
    }

    private function periodBreakdown(Collection $items, array $filters): Collection
    {
        $settlements = $this->settlementBreakdown($filters);
        if ($settlements->isNotEmpty()) {
            return $settlements;
        }

        return $items
            ->groupBy('month')
            ->map(function (Collection $group, string $month): array {
                return [
                    'label' => $month !== 'N/A' ? Carbon::createFromFormat('Y-m', $month)->format('m/Y') : 'N/A',
                    'status' => 'Tạm tính',
                    'eligible_tickets' => $group->where('is_eligible', true)->count(),
                    'gross_income' => $group->where('is_eligible', true)->sum('earning_amount'),
                    'adjustment_amount' => -1 * $group->sum('cancelled_amount'),
                    'final_income' => max(0, $group->where('is_eligible', true)->sum('earning_amount') - $group->sum('cancelled_amount')),
                ];
            })
            ->sortByDesc('label')
            ->values();
    }

    private function settlementBreakdown(array $filters): Collection
    {
        if (! Schema::hasTable('specialist_settlements')) {
            return collect();
        }

        $query = DB::table('specialist_settlements as ss');
        if ($filters['specialist_id'] !== null && Schema::hasColumn('specialist_settlements', 'specialist_id')) {
            $query->where('ss.specialist_id', $filters['specialist_id']);
        }

        if (Schema::hasColumn('specialist_settlements', 'period_from') && Schema::hasColumn('specialist_settlements', 'period_to')) {
            $query->where(function ($period) use ($filters): void {
                $period->whereNull('ss.period_from')
                    ->orWhereNull('ss.period_to')
                    ->orWhere(function ($intersect) use ($filters): void {
                        $intersect->whereDate('ss.period_from', '<=', $filters['date_to'])
                            ->whereDate('ss.period_to', '>=', $filters['date_from']);
                    });
            });
        }

        if ($filters['status'] !== 'all') {
            if (! Schema::hasColumn('specialist_settlements', 'status')) {
                return collect();
            }

            match ($filters['status']) {
                'pending_settlement' => $query->whereIn(DB::raw("LOWER(COALESCE(ss.status, ''))"), ['pending', 'estimated', 'draft', 'pending_approval', 'awaiting_approval', 'waiting_approval', 'waiting_review', 'pending_settlement']),
                'approved' => $query->whereIn(DB::raw("LOWER(COALESCE(ss.status, ''))"), ['approved']),
                'paid' => $query->whereIn(DB::raw("LOWER(COALESCE(ss.status, ''))"), ['paid', 'settled']),
                default => null,
            };

            if (! in_array($filters['status'], ['pending_settlement', 'approved', 'paid'], true)) {
                return collect();
            }
        }

        $totalTicketsExpression = Schema::hasColumn('specialist_settlements', 'total_eligible_tickets') ? 'ss.total_eligible_tickets' : '0';
        $grossExpression = Schema::hasColumn('specialist_settlements', 'gross_income') ? 'ss.gross_income' : (Schema::hasColumn('specialist_settlements', 'amount') ? 'ss.amount' : '0');
        $adjustmentExpression = Schema::hasColumn('specialist_settlements', 'adjustment_amount') ? 'ss.adjustment_amount' : '0';
        $finalExpression = Schema::hasColumn('specialist_settlements', 'final_income') ? 'ss.final_income' : (Schema::hasColumn('specialist_settlements', 'amount') ? 'ss.amount' : '0');

        return $query
            ->selectRaw("CONCAT(COALESCE(DATE_FORMAT(ss.period_from, '%d/%m/%Y'), 'N/A'), ' - ', COALESCE(DATE_FORMAT(ss.period_to, '%d/%m/%Y'), 'N/A')) as label")
            ->selectRaw('COALESCE(ss.status, "pending") as status')
            ->selectRaw($totalTicketsExpression . ' as eligible_tickets')
            ->selectRaw($grossExpression . ' as gross_income')
            ->selectRaw($adjustmentExpression . ' as adjustment_amount')
            ->selectRaw($finalExpression . ' as final_income')
            ->orderByDesc('ss.period_from')
            ->limit(24)
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->label,
                'status' => self::EARNING_STATUS_LABELS[$this->normalizeStatus((string) $row->status)] ?? (string) $row->status,
                'eligible_tickets' => (int) $row->eligible_tickets,
                'gross_income' => (float) $row->gross_income,
                'adjustment_amount' => (float) $row->adjustment_amount,
                'final_income' => (float) $row->final_income,
            ]);
    }

    private function breakdownRow(Collection $group, string $label): array
    {
        return [
            'label' => $label,
            'total_tickets' => $group->count(),
            'eligible_tickets' => $group->where('is_eligible', true)->count(),
            'income' => $group->where('is_eligible', true)->sum('earning_amount'),
            'held_amount' => $group->sum('held_amount'),
            'cancelled_amount' => $group->sum('cancelled_amount'),
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
            $query->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), self::RESOLVED_COMPLAINT_STATUSES);
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

    private function storeOptions(?int $specialistId): Collection
    {
        if (! Schema::hasTable('stores')) {
            return collect();
        }

        $query = DB::table('stores')
            ->select(['id', 'name', 'store_code'])
            ->orderBy('name');

        if ($specialistId !== null) {
            $storeIds = $this->assignedStoreIdsForSpecialist($specialistId);
            if (empty($storeIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $storeIds);
            }
        }

        return $query->get();
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

    private function assignedStoreIdsForSpecialist(int $specialistId): array
    {
        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
        ) {
            $query = DB::table('specialist_store_assignments')->where('specialist_id', $specialistId);

            if (Schema::hasColumn('specialist_store_assignments', 'assigned_to')) {
                $now = now('Asia/Ho_Chi_Minh')->toDateTimeString();
                $query->where(function ($window) use ($now): void {
                    $window->whereNull('assigned_to')->orWhere('assigned_to', '>=', $now);
                });
            }

            $storeIds = $query->pluck('store_id')
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            if (! empty($storeIds)) {
                return $storeIds;
            }
        }

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'sale_id')) {
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

    private function ticketTypeOptions(): array
    {
        return collect($this->ticketPricingService->getTicketTypes())
            ->map(fn (array $type): array => [
                'code' => $this->feeConfigService->normalizeTicketTypeCode((string) ($type['code'] ?? '')),
                'name' => (string) ($type['name'] ?? ($type['code'] ?? '')),
            ])
            ->prepend([
                'code' => SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE,
                'name' => 'Mặc định / fallback',
            ])
            ->unique('code')
            ->values()
            ->all();
    }

    private function parseDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
