<?php

namespace App\Services;

use App\Models\SpecialistSettlement;
use App\Models\SpecialistTicketEarning;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SpecialistSettlementService
{
    private const SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const RESOLVED_COMPLAINT_STATUSES = ['resolved', 'closed', 'rejected', 'cancelled', 'canceled', 'done'];
    private const ELIGIBLE_TICKET_STATUSES = ['ELIGIBLE', 'AVAILABLE', 'USED_FOR_ORDER', 'USED_IN_ROOM'];
    private const CANCELLED_TICKET_STATUSES = [
        'CANCELLED',
        'CANCELED',
        'REFUNDED',
        'REFUNDED_CANCELLED',
        'REFUNDED CANCELED',
        'FRAUD',
        'FRAUD_CANCELLED',
        'FRAUD CANCELED',
        'EXPIRED',
    ];
    private const HELD_TICKET_STATUSES = ['HELD', 'DISPUTED', 'LOCKED', 'PENDING', 'PENDING_CONFIRMATION'];
    private const EXCLUDED_EARNING_STATUSES = [
        'held',
        'disputed',
        'locked',
        'pending_confirmation',
        'cancelled',
        'canceled',
        'refunded',
        'fraud',
        'fraud_cancelled',
        'refunded_cancelled',
    ];

    public function __construct(
        private readonly SpecialistFeeConfigService $feeConfigService
    ) {
    }

    public function statusLabels(): array
    {
        return [
            SpecialistSettlement::STATUS_DRAFT => 'Nháp',
            SpecialistSettlement::STATUS_WAITING_APPROVAL => 'Chờ duyệt',
            SpecialistSettlement::STATUS_APPROVED => 'Đã duyệt',
            SpecialistSettlement::STATUS_PAID => 'Đã thanh toán',
            SpecialistSettlement::STATUS_CANCELLED => 'Đã hủy',
        ];
    }

    public function list(array $filters, bool $isSaleUser, ?int $currentUserId): LengthAwarePaginator
    {
        $query = SpecialistSettlement::query()
            ->with([
                'specialist:id,name,full_name,email,role',
                'creator:id,name,full_name,email',
                'approver:id,name,full_name,email',
                'payer:id,name,full_name,email',
            ])
            ->orderByDesc('period_from')
            ->orderByDesc('id');

        if ($isSaleUser) {
            $query->where('specialist_id', (int) $currentUserId);
        } elseif (! empty($filters['specialist_id'])) {
            $query->where('specialist_id', (int) $filters['specialist_id']);
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['period_from'])) {
            $query->whereDate('period_to', '>=', $filters['period_from']);
        }

        if (! empty($filters['period_to'])) {
            $query->whereDate('period_from', '<=', $filters['period_to']);
        }

        return $query->paginate(20)->appends($filters);
    }

    public function create(array $payload, int $actorId): SpecialistSettlement
    {
        $specialistId = (int) $payload['specialist_id'];
        $from = Carbon::parse($payload['period_from'])->startOfDay();
        $to = Carbon::parse($payload['period_to'])->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $manualAdjustment = (float) ($payload['adjustment_amount'] ?? 0);
        $note = trim((string) ($payload['note'] ?? ''));
        if ($manualAdjustment != 0.0 && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'Vui lòng nhập ghi chú/lý do khi có điều chỉnh cộng hoặc trừ.',
            ]);
        }

        $this->ensureNoDuplicateSettlement($specialistId, $from, $to);

        return DB::transaction(function () use ($specialistId, $from, $to, $manualAdjustment, $note, $payload, $actorId): SpecialistSettlement {
            $this->backfillEligibleTicketEarnings($specialistId, $from, $to);

            $eligibleRows = $this->eligibleRows($specialistId, $from, $to);
            $preparedRows = $this->prepareEligibleRows($eligibleRows, $specialistId);

            $totalEligible = $preparedRows->count();
            $grossIncome = $preparedRows->sum('settlement_amount');
            $feePerTicket = $totalEligible > 0
                ? round($grossIncome / $totalEligible, 2)
                : $this->feeConfigService->currentFeePerTicket($specialistId);

            $settlement = SpecialistSettlement::query()->create([
                'specialist_id' => $specialistId,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'status' => $payload['status'] ?? SpecialistSettlement::STATUS_WAITING_APPROVAL,
                'total_eligible_tickets' => $totalEligible,
                'fee_per_ticket' => $feePerTicket,
                'gross_income' => $grossIncome,
                'adjustment_amount' => $manualAdjustment,
                'final_income' => $grossIncome + $manualAdjustment,
                'amount' => $grossIncome + $manualAdjustment,
                'created_by' => $actorId,
                'notes' => $note !== '' ? $note : null,
                'note' => $note !== '' ? $note : null,
            ]);

            $this->attachPreparedRowsToSettlement($settlement, $preparedRows);

            $automaticAdjustment = $this->createLateCancellationAdjustments($settlement, $specialistId, $from, $to);
            if ($automaticAdjustment != 0.0) {
                $newAdjustment = (float) $settlement->adjustment_amount + $automaticAdjustment;
                $noteSuffix = 'Điều chỉnh âm tự động do vé đã chốt kỳ trước bị hủy/refund/fraud: '
                    . number_format($automaticAdjustment, 0, ',', '.') . ' đ.';
                $combinedNote = trim(($settlement->note ?: '') . "\n" . $noteSuffix);

                $settlement->forceFill([
                    'adjustment_amount' => $newAdjustment,
                    'final_income' => (float) $settlement->gross_income + $newAdjustment,
                    'amount' => (float) $settlement->gross_income + $newAdjustment,
                    'notes' => $combinedNote,
                    'note' => $combinedNote,
                ])->save();
            }

            return $settlement->refresh();
        });
    }

    public function syncSettlementFromTicketSource(
        SpecialistSettlement $settlement,
        bool $backfillMissingEarnings = true
    ): SpecialistSettlement
    {
        $specialistId = (int) $settlement->specialist_id;
        $from = Carbon::parse($settlement->period_from)->startOfDay();
        $to = Carbon::parse($settlement->period_to)->endOfDay();

        return DB::transaction(function () use ($settlement, $specialistId, $from, $to, $backfillMissingEarnings): SpecialistSettlement {
            if ($backfillMissingEarnings) {
                $this->backfillEligibleTicketEarnings($specialistId, $from, $to);
            }

            $eligibleRows = $this->eligibleRows($specialistId, $from, $to, (int) $settlement->id);
            $preparedRows = $this->prepareEligibleRows($eligibleRows, $specialistId);

            $totalEligible = $preparedRows->count();
            $grossIncome = $preparedRows->sum('settlement_amount');
            $feePerTicket = $totalEligible > 0
                ? round($grossIncome / $totalEligible, 2)
                : $this->feeConfigService->currentFeePerTicket($specialistId);
            $adjustment = (float) ($settlement->adjustment_amount ?? 0);

            $settlement->forceFill([
                'total_eligible_tickets' => $totalEligible,
                'fee_per_ticket' => $feePerTicket,
                'gross_income' => $grossIncome,
                'final_income' => $grossIncome + $adjustment,
                'amount' => $grossIncome + $adjustment,
            ])->save();

            $this->attachPreparedRowsToSettlement($settlement->refresh(), $preparedRows);

            return $settlement->refresh();
        });
    }

    public function approve(SpecialistSettlement $settlement, int $actorId): SpecialistSettlement
    {
        abort_unless(
            in_array((string) $settlement->status, [SpecialistSettlement::STATUS_DRAFT, SpecialistSettlement::STATUS_WAITING_APPROVAL], true),
            403,
            'Chỉ kỳ đối soát nháp/chờ duyệt mới được duyệt.'
        );

        $settlement = $this->syncSettlementFromTicketSource($settlement);

        return DB::transaction(function () use ($settlement, $actorId): SpecialistSettlement {
            $settlement->forceFill([
                'status' => SpecialistSettlement::STATUS_APPROVED,
                'approved_by' => $actorId,
                'approved_at' => now('Asia/Ho_Chi_Minh'),
            ])->save();

            SpecialistTicketEarning::query()
                ->where('settlement_id', $settlement->id)
                ->update([
                    'status' => 'approved',
                    'earning_status' => DB::raw("CASE WHEN earning_amount < 0 THEN 'adjusted' ELSE 'approved' END"),
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            return $settlement->refresh();
        });
    }

    public function markPaid(SpecialistSettlement $settlement, int $actorId): SpecialistSettlement
    {
        abort_unless((string) $settlement->status === SpecialistSettlement::STATUS_APPROVED, 403, 'Chỉ kỳ đã duyệt mới được đánh dấu thanh toán.');

        $settlement = $this->syncSettlementFromTicketSource($settlement);

        return DB::transaction(function () use ($settlement, $actorId): SpecialistSettlement {
            $settlement->forceFill([
                'status' => SpecialistSettlement::STATUS_PAID,
                'paid_by' => $actorId,
                'paid_at' => now('Asia/Ho_Chi_Minh'),
            ])->save();

            SpecialistTicketEarning::query()
                ->where('settlement_id', $settlement->id)
                ->update([
                    'status' => 'paid',
                    'earning_status' => DB::raw("CASE WHEN earning_amount < 0 THEN 'adjusted' ELSE 'paid' END"),
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            return $settlement->refresh();
        });
    }

    public function adjust(SpecialistSettlement $settlement, float $amount, string $note, int $actorId): SpecialistSettlement
    {
        abort_unless(
            in_array((string) $settlement->status, [SpecialistSettlement::STATUS_DRAFT, SpecialistSettlement::STATUS_WAITING_APPROVAL], true),
            403,
            'Chỉ kỳ nháp/chờ duyệt mới được điều chỉnh.'
        );

        if ($amount == 0.0) {
            throw ValidationException::withMessages(['adjustment_amount' => 'Giá trị điều chỉnh phải khác 0.']);
        }

        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['adjustment_note' => 'Vui lòng nhập lý do điều chỉnh.']);
        }

        $prefix = $amount > 0 ? 'Điều chỉnh cộng' : 'Điều chỉnh trừ';
        $newAdjustment = (float) $settlement->adjustment_amount + $amount;
        $combinedNote = trim(($settlement->note ?: '') . "\n" . $prefix . ' '
            . number_format($amount, 0, ',', '.') . ' đ bởi admin #' . $actorId . ': ' . $note);

        $settlement->forceFill([
            'adjustment_amount' => $newAdjustment,
            'final_income' => (float) $settlement->gross_income + $newAdjustment,
            'amount' => (float) $settlement->gross_income + $newAdjustment,
            'notes' => $combinedNote,
            'note' => $combinedNote,
        ])->save();

        return $settlement->refresh();
    }

    public function detailRows(SpecialistSettlement $settlement): Collection
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return collect();
        }

        return DB::table('specialist_ticket_earnings as ste')
            ->leftJoin('stores as s', 's.id', '=', 'ste.store_id')
            ->leftJoin('tickets as t', 't.id', '=', 'ste.ticket_id')
            ->leftJoin('orders as o', 'o.id', '=', 'ste.order_id')
            ->where('ste.settlement_id', $settlement->id)
            ->orderByDesc(DB::raw('COALESCE(ste.generated_at, ste.earned_at, ste.created_at)'))
            ->get([
                'ste.id',
                'ste.ticket_id',
                'ste.ticket_status',
                'ste.order_id',
                'ste.is_eligible',
                'ste.fee_per_ticket',
                'ste.earning_amount',
                'ste.reason_not_eligible',
                'ste.status',
                'ste.earning_status',
                'ste.generated_at',
                'ste.earned_at',
                'ste.adjustment_source_earning_id',
                's.name as store_name',
                's.store_code',
                't.ticket_code',
                't.ticket_type_code',
                'o.order_code',
            ]);
    }

    private function eligibleRows(int $specialistId, Carbon $from, Carbon $to, ?int $settlementId = null): Collection
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return collect();
        }

        $dateExpression = 'COALESCE(ste.generated_at, ste.earned_at, ste.created_at)';
        $query = DB::table('specialist_ticket_earnings as ste')
            ->leftJoin('tickets as t', 't.id', '=', 'ste.ticket_id')
            ->where('ste.specialist_id', $specialistId)
            ->whereRaw($dateExpression . ' >= ?', [$from->toDateTimeString()])
            ->whereRaw($dateExpression . ' <= ?', [$to->toDateTimeString()]);

        if ($settlementId !== null) {
            $query->where(function ($scope) use ($settlementId): void {
                $scope->whereNull('ste.settlement_id')
                    ->orWhere('ste.settlement_id', $settlementId);
            });
        } else {
            $query->whereNull('ste.settlement_id');
        }

        if (Schema::hasColumn('specialist_ticket_earnings', 'is_eligible')) {
            $query->where('ste.is_eligible', true);
        } elseif (Schema::hasColumn('specialist_ticket_earnings', 'ticket_status')) {
            $query->whereIn(DB::raw("UPPER(COALESCE(ste.ticket_status, ''))"), self::ELIGIBLE_TICKET_STATUSES);
        }

        if (Schema::hasColumn('specialist_ticket_earnings', 'ticket_status')) {
            $query->whereNotIn(DB::raw("UPPER(COALESCE(ste.ticket_status, ''))"), array_merge(self::CANCELLED_TICKET_STATUSES, self::HELD_TICKET_STATUSES));
        }

        foreach (['status', 'earning_status'] as $statusColumn) {
            if (Schema::hasColumn('specialist_ticket_earnings', $statusColumn)) {
                $query->whereNotIn(DB::raw("LOWER(COALESCE(ste.{$statusColumn}, ''))"), self::EXCLUDED_EARNING_STATUSES);
            }
        }

        return $query
            ->orderByRaw($dateExpression)
            ->get([
                'ste.*',
                't.ticket_type_code',
            ]);
    }

    private function attachPreparedRowsToSettlement(SpecialistSettlement $settlement, Collection $preparedRows): void
    {
        $childStatus = match ((string) $settlement->status) {
            SpecialistSettlement::STATUS_DRAFT => 'draft',
            SpecialistSettlement::STATUS_APPROVED => 'approved',
            SpecialistSettlement::STATUS_PAID => 'paid',
            default => 'pending_settlement',
        };

        foreach ($preparedRows as $row) {
            $payload = [
                'settlement_id' => $settlement->id,
                'status' => $childStatus,
                'earning_status' => $childStatus,
                'fee_per_ticket' => $row->settlement_fee,
                'earning_amount' => $row->settlement_amount,
                'updated_at' => now(),
            ];

            if ((string) $settlement->status === SpecialistSettlement::STATUS_APPROVED) {
                $payload['approved_at'] = $settlement->approved_at ?: now();
            }

            if ((string) $settlement->status === SpecialistSettlement::STATUS_PAID) {
                $payload['approved_at'] = $settlement->approved_at ?: now();
                $payload['paid_at'] = $settlement->paid_at ?: now();
            }

            SpecialistTicketEarning::query()
                ->whereKey($row->id)
                ->update($payload);
        }
    }

    private function backfillEligibleTicketEarnings(int $specialistId, Carbon $from, Carbon $to): void
    {
        if (
            ! Schema::hasTable('specialist_ticket_earnings')
            || ! Schema::hasTable('tickets')
            || ! Schema::hasTable('orders')
            || ! Schema::hasTable('stores')
        ) {
            return;
        }

        $storeExpression = 'COALESCE(source_order.store_id, used_order.store_id, linked_order.store_id)';
        $orderExpression = 'COALESCE(source_order.id, used_order.id, linked_order.id)';
        $orderStatusExpression = 'LOWER(COALESCE(source_order.status, used_order.status, linked_order.status, \'\'))';
        $paymentStatusExpression = 'LOWER(COALESCE(source_order.status_payment, used_order.status_payment, linked_order.status_payment, \'\'))';
        $generatedAtExpression = 'COALESCE(t.purchased_at, t.created_at)';
        $scopeDateExpression = 'COALESCE(source_order.delivered_at, used_order.delivered_at, linked_order.delivered_at, source_order.created_at, used_order.created_at, linked_order.created_at, t.purchased_at, t.created_at)';

        $query = DB::table('tickets as t')
            ->leftJoin('orders as source_order', function ($join): void {
                $join->on('source_order.id', '=', 't.source_id')
                    ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['ORDER', 'ORDER_REWARD', 'ORDER_REDEEM']);
            })
            ->leftJoin('orders as used_order', 'used_order.id', '=', 't.used_in_order_id')
            ->leftJoin('order_tickets as ot', 'ot.ticket_id', '=', 't.id')
            ->leftJoin('orders as linked_order', 'linked_order.id', '=', 'ot.order_id')
            ->leftJoin('stores as s', function ($join) use ($storeExpression): void {
                $join->on('s.id', '=', DB::raw($storeExpression));
            })
            ->whereRaw($generatedAtExpression . ' >= ?', [$from->toDateTimeString()])
            ->whereRaw($generatedAtExpression . ' <= ?', [$to->toDateTimeString()])
            ->whereIn(DB::raw($orderStatusExpression), self::SUCCESS_ORDER_STATUSES)
            ->whereRaw($paymentStatusExpression . " = 'paid'")
            ->whereIn(DB::raw("UPPER(COALESCE(t.status, ''))"), self::ELIGIBLE_TICKET_STATUSES)
            ->whereNotExists(function ($exists) use ($specialistId): void {
                $exists->selectRaw('1')
                    ->from('specialist_ticket_earnings as existing_earning')
                    ->whereColumn('existing_earning.ticket_id', 't.id')
                    ->where('existing_earning.specialist_id', $specialistId);
            });

        $this->applyTicketSourceSpecialistScope($query, $specialistId, $storeExpression, $scopeDateExpression);
        $this->excludeDisputedOrRefundedOrders($query, $orderExpression);

        $rows = $query
            ->selectRaw('t.id as ticket_id')
            ->selectRaw('t.status as ticket_status')
            ->selectRaw('t.ticket_type_code as ticket_type_code')
            ->selectRaw($orderExpression . ' as order_id')
            ->selectRaw($storeExpression . ' as store_id')
            ->selectRaw($generatedAtExpression . ' as generated_at')
            ->selectRaw(Schema::hasColumn('tickets', 'value_amount') ? 'COALESCE(t.value_amount, 0) as ticket_value_amount' : '0 as ticket_value_amount')
            ->get();

        foreach ($rows as $row) {
            if (empty($row->ticket_id)) {
                continue;
            }

            $fee = $this->feeConfigService->feeFor(
                $specialistId,
                (string) ($row->store_id ?? ''),
                $row->generated_at ?? null,
                null,
                null,
                (string) ($row->ticket_type_code ?? '')
            );

            SpecialistTicketEarning::query()->create([
                'specialist_id' => $specialistId,
                'store_id' => $row->store_id,
                'ticket_id' => $row->ticket_id,
                'ticket_status' => $row->ticket_status ?: 'ELIGIBLE',
                'order_id' => $row->order_id,
                'status' => 'estimated',
                'earning_status' => 'estimated',
                'is_eligible' => true,
                'ticket_value_amount' => (float) ($row->ticket_value_amount ?? 0),
                'fee_per_ticket' => $fee,
                'commission_rate' => 0,
                'earning_amount' => $fee,
                'reason_not_eligible' => null,
                'generated_at' => $row->generated_at,
                'earned_at' => $row->generated_at,
                'metadata' => [
                    'source' => 'backfilled_from_ticket_order_for_settlement',
                    'rule' => 'completed_paid_non_refund_non_dispute_eligible_ticket',
                    'ticket_type_code' => $this->feeConfigService->normalizeTicketTypeCode(
                        (string) ($row->ticket_type_code ?? '')
                    ),
                ],
            ]);
        }
    }

    private function applyTicketSourceSpecialistScope($query, int $specialistId, string $storeExpression, string $scopeDateExpression): void
    {
        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_from')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            $query->whereExists(function ($assignment) use ($specialistId, $storeExpression, $scopeDateExpression): void {
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

        if (Schema::hasColumn('stores', 'sale_id')) {
            $query->where('s.sale_id', $specialistId);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function excludeDisputedOrRefundedOrders($query, string $orderExpression): void
    {
        if (Schema::hasTable('complaints') && Schema::hasColumn('complaints', 'order_id')) {
            $query->whereNotExists(function ($exists) use ($orderExpression): void {
                $exists->selectRaw('1')
                    ->from('complaints as c')
                    ->whereRaw('c.order_id = ' . $orderExpression);

                if (Schema::hasColumn('complaints', 'status')) {
                    $exists->whereNotIn(DB::raw("LOWER(COALESCE(c.status, ''))"), self::RESOLVED_COMPLAINT_STATUSES);
                }
            });
        }

        if (Schema::hasTable('refunds') && Schema::hasColumn('refunds', 'source_id')) {
            $query->whereNotExists(function ($exists) use ($orderExpression): void {
                $exists->selectRaw('1')
                    ->from('refunds as r')
                    ->whereRaw('r.source_id = ' . $orderExpression);
            });
        }
    }

    private function prepareEligibleRows(Collection $rows, int $specialistId): Collection
    {
        return $rows->map(function ($row) use ($specialistId): object {
            $ticketTypeCode = $this->ticketTypeCodeFromRow($row);
            $fee = $this->feeConfigService->feeFor(
                $specialistId,
                (string) ($row->store_id ?? ''),
                $row->generated_at ?? $row->earned_at ?? $row->created_at ?? null,
                null,
                null,
                $ticketTypeCode
            );

            $row->settlement_fee = $fee;
            $row->settlement_amount = $fee;
            $row->settlement_ticket_type_code = $ticketTypeCode;

            return $row;
        });
    }

    private function ticketTypeCodeFromRow(object $row): string
    {
        $ticketTypeCode = trim((string) ($row->ticket_type_code ?? ''));

        if ($ticketTypeCode === '' && ! empty($row->metadata)) {
            $metadata = is_array($row->metadata)
                ? $row->metadata
                : json_decode((string) $row->metadata, true);

            if (is_array($metadata)) {
                $ticketTypeCode = trim((string) ($metadata['ticket_type_code'] ?? ''));
            }
        }

        return $this->feeConfigService->normalizeTicketTypeCode($ticketTypeCode);
    }

    private function createLateCancellationAdjustments(SpecialistSettlement $settlement, int $specialistId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return 0.0;
        }

        $query = DB::table('specialist_ticket_earnings as ste')
            ->leftJoin('specialist_settlements as ss', 'ss.id', '=', 'ste.settlement_id')
            ->where('ste.specialist_id', $specialistId)
            ->whereNotNull('ste.settlement_id')
            ->where('ste.settlement_id', '<>', $settlement->id)
            ->where('ste.earning_amount', '>', 0)
            ->whereBetween('ste.updated_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->where(function ($paid): void {
                $paid->whereNotNull('ste.paid_at')
                    ->orWhereIn(DB::raw("UPPER(COALESCE(ss.status, ''))"), [SpecialistSettlement::STATUS_PAID, SpecialistSettlement::STATUS_APPROVED])
                    ->orWhereIn(DB::raw("LOWER(COALESCE(ste.status, ''))"), ['paid', 'approved']);
            })
            ->where(function ($cancelled): void {
                $cancelled->whereIn(DB::raw("UPPER(COALESCE(ste.ticket_status, ''))"), self::CANCELLED_TICKET_STATUSES)
                    ->orWhereIn(DB::raw("LOWER(COALESCE(ste.status, ''))"), ['cancelled', 'canceled', 'refunded', 'fraud', 'fraud_cancelled', 'refunded_cancelled'])
                    ->orWhereIn(DB::raw("LOWER(COALESCE(ste.earning_status, ''))"), ['cancelled', 'canceled', 'refunded', 'fraud', 'fraud_cancelled', 'refunded_cancelled']);

                if (Schema::hasColumn('specialist_ticket_earnings', 'is_eligible')) {
                    $cancelled->orWhere('ste.is_eligible', false);
                }
            });

        if (Schema::hasColumn('specialist_ticket_earnings', 'adjustment_source_earning_id')) {
            $query->whereNotExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('specialist_ticket_earnings as adjusted')
                    ->whereColumn('adjusted.adjustment_source_earning_id', 'ste.id');
            });
        }

        $sourceRows = $query->get([
            'ste.id',
            'ste.settlement_id',
            'ste.specialist_id',
            'ste.store_id',
            'ste.ticket_id',
            'ste.ticket_status',
            'ste.order_id',
            'ste.ticket_value_amount',
            'ste.fee_per_ticket',
            'ste.commission_rate',
            'ste.earning_amount',
        ]);

        $totalAdjustment = 0.0;
        foreach ($sourceRows as $source) {
            $amount = -1 * abs((float) ($source->earning_amount ?? 0));
            if ($amount == 0.0) {
                continue;
            }

            $metadata = [
                'type' => 'late_cancel_adjustment',
                'source_earning_id' => (int) $source->id,
                'source_settlement_id' => isset($source->settlement_id) ? (int) $source->settlement_id : null,
                'created_by_settlement_id' => (int) $settlement->id,
            ];

            SpecialistTicketEarning::query()->create([
                'specialist_id' => (int) $source->specialist_id,
                'store_id' => $source->store_id,
                'ticket_id' => $source->ticket_id,
                'ticket_status' => $source->ticket_status ?: 'CANCELLED',
                'order_id' => $source->order_id,
                'settlement_id' => $settlement->id,
                'adjustment_source_earning_id' => (int) $source->id,
                'status' => 'adjusted',
                'earning_status' => 'adjusted',
                'is_eligible' => false,
                'ticket_value_amount' => (float) ($source->ticket_value_amount ?? 0),
                'fee_per_ticket' => (float) ($source->fee_per_ticket ?? 0),
                'commission_rate' => (float) ($source->commission_rate ?? 0),
                'earning_amount' => $amount,
                'reason_not_eligible' => 'Điều chỉnh âm do ticket đã chốt kỳ trước bị hủy/refund/fraud',
                'generated_at' => now('Asia/Ho_Chi_Minh'),
                'earned_at' => now('Asia/Ho_Chi_Minh'),
                'metadata' => $metadata,
            ]);

            $totalAdjustment += $amount;
        }

        return $totalAdjustment;
    }

    private function ensureNoDuplicateSettlement(int $specialistId, Carbon $from, Carbon $to): void
    {
        $exists = SpecialistSettlement::query()
            ->where('specialist_id', $specialistId)
            ->where('period_from', $from->toDateString())
            ->where('period_to', $to->toDateString())
            ->whereIn('status', [
                SpecialistSettlement::STATUS_DRAFT,
                SpecialistSettlement::STATUS_WAITING_APPROVAL,
                SpecialistSettlement::STATUS_APPROVED,
                SpecialistSettlement::STATUS_PAID,
            ])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'period_from' => 'Chuyên viên này đã có kỳ đối soát cùng khoảng thời gian.',
            ]);
        }
    }
}
