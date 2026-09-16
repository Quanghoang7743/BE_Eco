<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateStoreReportExportJob;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreAdminReportController extends Controller
{
    public function __construct(private readonly StoreAdminStaffAccess $access)
    {
    }

    public function createExport(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];
        if (!$store) {
            return $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false);
        }

        $validator = Validator::make($request->all(), [
            'report_type' => ['required', 'string', 'in:revenue,ticket_redemption,ticket_consumption,order_list'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period' => ['nullable', 'string', 'in:day,week,month'],
            'status' => ['nullable', 'string', 'max:50'],
            'format' => ['required', 'string', 'in:csv,xlsx'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        if (!Schema::hasTable('report_exports')) {
            return $this->errorResponse('EXPORT_NOT_SUPPORTED', 'He thong chua ho tro xuat bao cao tren moi truong hien tai.', 422, true, true);
        }

        $validated = $validator->validated();
        /** @var User|null $user */
        $user = $context['user'];
        $permissionError = $this->authorizeExportRequest($user, $store, (string) $validated['report_type']);
        if ($permissionError) {
            return $permissionError;
        }

        $jobId = (string) Str::uuid();
        $now = now();

        $payload = [
            'id' => $jobId,
            'store_id' => $store->id,
            'report_type' => (string) $validated['report_type'],
            'status' => 'pending',
            'progress_percent' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('report_exports')->insert($payload);

        $reportType = (string) $validated['report_type'];
        $status = $validated['status'] ?? null;
        if ($reportType !== 'order_list' && ($status === null || $status === '')) {
            $status = 'delivered';
        }

        GenerateStoreReportExportJob::dispatch($jobId, (string) $store->id, [
            'report_type' => $reportType,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'period' => $validated['period'] ?? 'day',
            'status' => $status,
            'format' => (string) $validated['format'],
        ]);

        return response()->json([
            'data' => [
                'job_id' => $jobId,
                'report_type' => (string) $validated['report_type'],
                'status' => 'pending',
                'progress' => 0,
                'progress_percent' => 0,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 202);
    }

    public function createOrderExport(Request $request): JsonResponse
    {
        $request->merge(['report_type' => 'order_list']);

        return $this->createExport($request);
    }

    public function revenue(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];
        if (!$store) {
            return $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false);
        }

        $validator = Validator::make($request->query(), [
            'period' => ['required', 'string', 'in:day,week,month'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $to = $validated['to'] ?? now()->toDateString();

        if (!empty($validated['from'])) {
            $from = $validated['from'];
        } elseif ($period === 'day') {
            $from = now()->subDays(29)->toDateString();
        } elseif ($period === 'week') {
            $from = now()->startOfWeek()->subWeeks(11)->toDateString();
        } else {
            $from = now()->startOfMonth()->subMonths(11)->toDateString();
        }

        if (!Schema::hasTable('orders')) {
            return response()->json([
                'data' => [
                    'period' => $period,
                    'from' => $from,
                    'to' => $to,
                    'currency' => 'VND',
                    'series' => [],
                    'totals' => [
                        'revenue' => 0,
                        'orders_count' => 0,
                        'tickets_used' => 0,
                        'ticket_value_vnd' => 0,
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $amountColumn = 'final_amount';
        if (!Schema::hasColumn('orders', 'final_amount')) {
            if (Schema::hasColumn('orders', 'total_amount')) {
                $amountColumn = 'total_amount';
            } elseif (Schema::hasColumn('orders', 'total')) {
                $amountColumn = 'total';
            }
        }

        $dateColumn = Schema::hasColumn('orders', 'delivered_at') ? 'delivered_at' : 'created_at';

        $bucketExpr = "DATE(`$dateColumn`)";
        if ($period === 'week') {
            $bucketExpr = "DATE_FORMAT(`$dateColumn`, '%x-W%v')";
        } elseif ($period === 'month') {
            $bucketExpr = "DATE_FORMAT(`$dateColumn`, '%Y-%m')";
        }

        $query = DB::table('orders')
            ->where('store_id', $store->id)
            ->whereDate($dateColumn, '>=', $from)
            ->whereDate($dateColumn, '<=', $to);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $rows = $query
            ->selectRaw($bucketExpr . ' as bucket')
            ->selectRaw('COALESCE(SUM(`' . $amountColumn . '`), 0) as revenue_total')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw(
                Schema::hasColumn('orders', 'total_tickets_used')
                    ? 'COALESCE(SUM(`total_tickets_used`), 0) as tickets_used'
                    : '0 as tickets_used'
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $series = $rows->map(function ($row) {
            $ticketsUsed = (int) ($row->tickets_used ?? 0);

            return [
                'bucket' => (string) ($row->bucket ?? ''),
                'revenue' => round((float) ($row->revenue_total ?? 0), 2),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'tickets_used' => $ticketsUsed,
                'ticket_value_vnd' => $ticketsUsed * 5000,
            ];
        })->values();

        $totalRevenue = (float) $series->sum('revenue');
        $totalOrders = (int) $series->sum('orders_count');
        $totalTickets = (int) $series->sum('tickets_used');

        return response()->json([
            'data' => [
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'currency' => 'VND',
                'series' => $series,
                'totals' => [
                    'revenue' => round($totalRevenue, 2),
                    'orders_count' => $totalOrders,
                    'tickets_used' => $totalTickets,
                    'ticket_value_vnd' => $totalTickets * 5000,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function ticketRedemptions(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];
        if (!$store) {
            return $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false);
        }

        $validator = Validator::make($request->query(), [
            'period' => ['nullable', 'string', 'in:day,week,month'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) ($validated['period'] ?? 'day');
        $status = mb_strtolower(trim((string) ($validated['status'] ?? 'delivered')));
        $to = $validated['to'] ?? now()->toDateString();

        if (!empty($validated['from'])) {
            $from = $validated['from'];
        } elseif ($period === 'day') {
            $from = now()->subDays(29)->toDateString();
        } elseif ($period === 'week') {
            $from = now()->startOfWeek()->subWeeks(11)->toDateString();
        } else {
            $from = now()->startOfMonth()->subMonths(11)->toDateString();
        }

        $requiredTablesReady = Schema::hasTable('orders')
            && Schema::hasTable('order_tickets')
            && Schema::hasColumn('order_tickets', 'order_id');

        if (!$requiredTablesReady) {
            return response()->json([
                'data' => [
                    'period' => $period,
                    'from' => $from,
                    'to' => $to,
                    'status' => $status,
                    'currency' => 'VND',
                    'series' => [],
                    'totals' => [
                        'redeemed_tickets' => 0,
                        'redemption_value_vnd' => 0,
                        'orders_count' => 0,
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $dateColumn = 'ot.used_at';
        if (!Schema::hasColumn('order_tickets', 'used_at')) {
            $dateColumn = Schema::hasColumn('orders', 'delivered_at') ? 'o.delivered_at' : 'o.created_at';
        }

        $bucketExpr = 'DATE(' . $dateColumn . ')';
        if ($period === 'week') {
            $bucketExpr = "DATE_FORMAT(" . $dateColumn . ", '%x-W%v')";
        } elseif ($period === 'month') {
            $bucketExpr = "DATE_FORMAT(" . $dateColumn . ", '%Y-%m')";
        }

        $hasTicketJoin = Schema::hasTable('tickets')
            && Schema::hasColumn('order_tickets', 'ticket_id')
            && Schema::hasColumn('tickets', 'id');

        $valueExpr = $hasTicketJoin && Schema::hasColumn('tickets', 'value_amount')
            ? 'COALESCE(t.value_amount, 5000)'
            : '5000';

        $query = DB::table('order_tickets as ot')
            ->join('orders as o', 'o.id', '=', 'ot.order_id')
            ->where('o.store_id', $store->id)
            ->whereDate(DB::raw($dateColumn), '>=', $from)
            ->whereDate(DB::raw($dateColumn), '<=', $to);

        if ($hasTicketJoin) {
            $query->leftJoin('tickets as t', 't.id', '=', 'ot.ticket_id');
        }

        if (Schema::hasColumn('orders', 'status') && $status !== '' && $status !== 'all') {
            if (in_array($status, $this->completedOrderStatuses(), true)) {
                $query->whereIn(DB::raw('LOWER(COALESCE(o.status, ""))'), $this->completedOrderStatuses());
            } else {
                $query->whereRaw('LOWER(o.status) = ?', [$status]);
            }
        }

        $rows = $query
            ->selectRaw($bucketExpr . ' as bucket')
            ->selectRaw('COUNT(*) as redeemed_tickets')
            ->selectRaw('COALESCE(SUM(' . $valueExpr . '), 0) as redemption_value_vnd')
            ->selectRaw('COUNT(DISTINCT o.id) as orders_count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $series = $rows->map(function ($row) {
            return [
                'bucket' => (string) ($row->bucket ?? ''),
                'redeemed_tickets' => (int) ($row->redeemed_tickets ?? 0),
                'redemption_value_vnd' => round((float) ($row->redemption_value_vnd ?? 0), 2),
                'orders_count' => (int) ($row->orders_count ?? 0),
            ];
        })->values();

        return response()->json([
            'data' => [
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'status' => $status,
                'currency' => 'VND',
                'series' => $series,
                'totals' => [
                    'redeemed_tickets' => (int) $series->sum('redeemed_tickets'),
                    'redemption_value_vnd' => round((float) $series->sum('redemption_value_vnd'), 2),
                    'orders_count' => (int) $series->sum('orders_count'),
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function ticketConsumption(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];
        if (!$store) {
            return $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false);
        }

        $validator = Validator::make($request->query(), [
            'period' => ['nullable', 'string', 'in:day,week,month'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) ($validated['period'] ?? 'day');
        $to = $validated['to'] ?? now()->toDateString();

        if (!empty($validated['from'])) {
            $from = $validated['from'];
        } elseif ($period === 'day') {
            $from = now()->subDays(29)->toDateString();
        } elseif ($period === 'week') {
            $from = now()->startOfWeek()->subWeeks(11)->toDateString();
        } else {
            $from = now()->startOfMonth()->subMonths(11)->toDateString();
        }

        if (!Schema::hasTable('store_ticket_transactions')) {
            return response()->json([
                'data' => [
                    'period' => $period,
                    'from' => $from,
                    'to' => $to,
                    'currency' => 'VND',
                    'series' => [],
                    'by_product' => [],
                    'totals' => [
                        'tickets_consumed' => 0,
                        'total_debit_amount' => 0,
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $ticketUnitValue = $this->resolveTicketUnitValue();

        $dateColumn = '`timestamp`';
        $bucketExpr = 'DATE(' . $dateColumn . ')';
        if ($period === 'week') {
            $bucketExpr = "DATE_FORMAT(" . $dateColumn . ", '%x-W%v')";
        } elseif ($period === 'month') {
            $bucketExpr = "DATE_FORMAT(" . $dateColumn . ", '%Y-%m')";
        }

        $baseQuery = DB::table('store_ticket_transactions as stt')
            ->where('stt.store_id', $store->id)
            ->whereDate('stt.timestamp', '>=', $from)
            ->whereDate('stt.timestamp', '<=', $to);

        if (Schema::hasColumn('store_ticket_transactions', 'event_type')) {
            $baseQuery->whereIn('stt.event_type', ['ORDER_DELIVERED', 'ORDER_REFUND']);
        }

        $series = (clone $baseQuery)
            ->selectRaw($bucketExpr . ' as bucket')
            ->selectRaw('COALESCE(SUM(stt.tickets_consumed), 0) as tickets_consumed')
            ->selectRaw('COALESCE(SUM(stt.total_debit_amount), 0) as total_debit_amount')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(static function ($row): array {
                $ticketsConsumed = (int) ($row->tickets_consumed ?? 0);
                $recordedDebit = round((float) ($row->total_debit_amount ?? 0), 2);

                return [
                    'bucket' => (string) ($row->bucket ?? ''),
                    'tickets_consumed' => $ticketsConsumed,
                    'total_debit_amount' => $recordedDebit,
                ];
            })
            ->values();

        $series = $series->map(function (array $row) use ($ticketUnitValue): array {
            $computedDebit = $row['tickets_consumed'] * $ticketUnitValue;

            $row['total_debit_amount'] = $computedDebit > 0 ? $computedDebit : $row['total_debit_amount'];

            return $row;
        });

        // series_by_ticket_type: group by bucket + ticket_type_code
        $ticketPricing = app(TicketPricingService::class);

        $ticketTypeExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(stt.metadata, '$.ticket_type_code')), p.ticket_type_code, '')";
        $ticketTypeAlias = 'resolved_ticket_type_code';
        $ticketTypeBaseQuery = (clone $baseQuery)
            ->leftJoin('products as p', 'p.id', '=', 'stt.product_id')
            ->selectRaw($bucketExpr . ' as bucket')
            ->selectRaw('stt.product_id as product_id')
            ->selectRaw('COALESCE(p.name, CONCAT(\'Product #\', stt.product_id)) as product_name')
            ->selectRaw($ticketTypeExpr . ' as ' . $ticketTypeAlias)
            ->selectRaw('COALESCE(stt.tickets_consumed, 0) as tickets_consumed')
            ->selectRaw('COALESCE(stt.total_debit_amount, 0) as total_debit_amount');

        $seriesByTicketTypeRows = DB::query()
            ->fromSub($ticketTypeBaseQuery, 'ticket_rows')
            ->selectRaw('ticket_rows.bucket as bucket')
            ->selectRaw('ticket_rows.' . $ticketTypeAlias . ' as ' . $ticketTypeAlias)
            ->selectRaw('COALESCE(SUM(ticket_rows.tickets_consumed), 0) as tickets_consumed')
            ->selectRaw('COALESCE(SUM(ticket_rows.total_debit_amount), 0) as total_debit_amount')
            ->groupBy('ticket_rows.bucket', 'ticket_rows.' . $ticketTypeAlias)
            ->orderBy('ticket_rows.bucket')
            ->get();

        $seriesByTicketType = $seriesByTicketTypeRows->map(function ($row) use ($ticketPricing, $ticketTypeAlias) {
            $code = strtoupper(trim((string) ($row->{$ticketTypeAlias} ?? '')));
            if ($code === '') {
                $code = 'UNKNOWN';
            }

            $resolved = $ticketPricing->resolveTicketType($code);
            $name = $resolved['name'] ?? 'Unknown Ticket';

            return [
                'bucket' => (string) ($row->bucket ?? ''),
                'ticket_type_code' => $code,
                'ticket_type_name' => $name,
                'tickets_consumed' => (int) ($row->tickets_consumed ?? 0),
                'total_debit_amount' => round((float) ($row->total_debit_amount ?? 0), 2),
            ];
        })->values();

        $productQuery = (clone $baseQuery)
            ->leftJoin('products as p', 'p.id', '=', 'stt.product_id')
            ->selectRaw('stt.product_id as product_id')
            ->selectRaw('COALESCE(MAX(p.name), CONCAT(\'Product #\', stt.product_id)) as product_name')
            ->selectRaw('COALESCE(SUM(stt.tickets_consumed), 0) as tickets_consumed')
            ->selectRaw('COALESCE(SUM(stt.total_debit_amount), 0) as total_debit_amount')
            ->groupBy('stt.product_id')
            ->orderByDesc('total_debit_amount');

        $byProduct = $productQuery
            ->get()
            ->map(function ($row) use ($ticketUnitValue): array {
                $ticketsConsumed = (int) ($row->tickets_consumed ?? 0);
                $recordedDebit = round((float) ($row->total_debit_amount ?? 0), 2);

                return [
                    'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                    'product_name' => (string) ($row->product_name ?? ''),
                    'tickets_consumed' => $ticketsConsumed,
                    'total_debit_amount' => ($ticketsConsumed * $ticketUnitValue) > 0 ? $ticketsConsumed * $ticketUnitValue : $recordedDebit,
                ];
            })
            ->values();

        // by_product_ticket_type: group by product + ticket_type_code
        $byProductTicketTypeRows = DB::query()
            ->fromSub(clone $ticketTypeBaseQuery, 'ticket_rows')
            ->selectRaw('ticket_rows.product_id as product_id')
            ->selectRaw('COALESCE(MAX(ticket_rows.product_name), CONCAT(\'Product #\', ticket_rows.product_id)) as product_name')
            ->selectRaw('ticket_rows.' . $ticketTypeAlias . ' as ' . $ticketTypeAlias)
            ->selectRaw('COALESCE(SUM(ticket_rows.tickets_consumed), 0) as tickets_consumed')
            ->selectRaw('COALESCE(SUM(ticket_rows.total_debit_amount), 0) as total_debit_amount')
            ->groupBy('ticket_rows.product_id', 'ticket_rows.' . $ticketTypeAlias)
            ->orderByDesc('total_debit_amount')
            ->get();

        $byProductTicketType = $byProductTicketTypeRows->map(function ($row) use ($ticketUnitValue, $ticketPricing, $ticketTypeAlias) {
            $code = strtoupper(trim((string) ($row->{$ticketTypeAlias} ?? '')));
            if ($code === '') {
                $code = 'UNKNOWN';
            }
            $resolved = $ticketPricing->resolveTicketType($code);
            $name = $resolved['name'] ?? 'Unknown Ticket';

            $ticketsConsumed = (int) ($row->tickets_consumed ?? 0);
            $recordedDebit = round((float) ($row->total_debit_amount ?? 0), 2);

            return [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_name' => (string) ($row->product_name ?? ''),
                'ticket_type_code' => $code,
                'ticket_type_name' => $name,
                'tickets_consumed' => $ticketsConsumed,
                'total_debit_amount' => ($ticketsConsumed * $ticketUnitValue) > 0 ? $ticketsConsumed * $ticketUnitValue : $recordedDebit,
            ];
        })->values();

        return response()->json([
            'data' => [
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'currency' => 'VND',
                'series' => $series,
                'series_by_ticket_type' => $seriesByTicketType,
                'by_product' => $byProduct,
                'by_product_ticket_type' => $byProductTicketType,
                'totals' => [
                    'tickets_consumed' => (int) $series->sum('tickets_consumed'),
                    'total_debit_amount' => round((float) $series->sum('total_debit_amount'), 2),
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function resolveTicketUnitValue(): float
    {
        $config = app(TicketPricingService::class)->getConfig();

        return max(0.0, (float) ($config['ticket_value'] ?? 0));
    }

    private function completedOrderStatuses(): array
    {
        return ['delivered', 'completed', 'call_completed'];
    }

    public function exportStatus(Request $request, string $jobId): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];
        if (!$store) {
            return $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false);
        }

        $validator = Validator::make([
            'job_id' => $jobId,
        ], [
            'job_id' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        if (!Schema::hasTable('report_exports')) {
            return $this->errorResponse('EXPORT_NOT_FOUND', 'Khong tim thay job export.', 404, true, true);
        }

        $query = DB::table('report_exports')->where('id', $jobId);

        if (Schema::hasColumn('report_exports', 'store_id')) {
            $query->where('store_id', $store->id);
        }

        $export = $query->first();
        if (!$export) {
            return $this->errorResponse('EXPORT_NOT_FOUND', 'Khong tim thay job export hoac job khong thuoc cua hang hien tai.', 404, true, true);
        }

        /** @var User|null $user */
        $user = $context['user'];
        $permissionError = $this->authorizeExportRequest($user, $store, (string) ($export->report_type ?? ''));
        if ($permissionError) {
            return $permissionError;
        }

        $status = mb_strtolower(trim((string) ($export->status ?? 'pending')));
        $downloadUrl = $status === 'completed'
            ? $this->buildExportDownloadUrl((string) ($export->id ?? $jobId), (string) ($export->report_type ?? ''), (string) $store->id)
            : null;

        return response()->json([
            'data' => [
                'job_id' => $export->id ?? $jobId,
                'report_type' => $export->report_type ?? null,
                'status' => $status,
                'progress' => isset($export->progress_percent) ? (int) $export->progress_percent : null,
                'progress_percent' => isset($export->progress_percent) ? (int) $export->progress_percent : null,
                'file_url' => $status === 'completed' ? ($export->file_url ?? null) : null,
                'download_url' => $downloadUrl,
                'error_message' => $status === 'failed' ? ($export->error_message ?? null) : null,
                'created_at' => isset($export->created_at) ? \Illuminate\Support\Carbon::parse($export->created_at)->toIso8601String() : null,
                'updated_at' => isset($export->updated_at) ? \Illuminate\Support\Carbon::parse($export->updated_at)->toIso8601String() : null,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function downloadExport(Request $request, string $jobId)
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];
        if (!$store) {
            return $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false);
        }

        $validator = Validator::make([
            'job_id' => $jobId,
        ], [
            'job_id' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        if (!Schema::hasTable('report_exports')) {
            return $this->errorResponse('EXPORT_NOT_FOUND', 'Khong tim thay job export.', 404, true, true);
        }

        $query = DB::table('report_exports')->where('id', $jobId);

        if (Schema::hasColumn('report_exports', 'store_id')) {
            $query->where('store_id', $store->id);
        }

        $export = $query->first();
        if (!$export) {
            return $this->errorResponse('EXPORT_NOT_FOUND', 'Khong tim thay job export hoac job khong thuoc cua hang hien tai.', 404, true, true);
        }

        /** @var User|null $user */
        $user = $context['user'];
        $permissionError = $this->authorizeExportRequest($user, $store, (string) ($export->report_type ?? ''));
        if ($permissionError) {
            return $permissionError;
        }

        $status = mb_strtolower(trim((string) ($export->status ?? 'pending')));
        if ($status !== 'completed') {
            return $this->errorResponse('EXPORT_NOT_READY', 'File bao cao chua san sang de tai.', 409, true, true);
        }

        $storagePath = $this->resolveExportStoragePath((string) ($export->file_url ?? ''));
        if ($storagePath === null || !Storage::disk('public')->exists($storagePath)) {
            return $this->errorResponse('EXPORT_FILE_NOT_FOUND', 'Khong tim thay file bao cao.', 404, true, true);
        }

        return Storage::disk('public')->download($storagePath, basename($storagePath));
    }

    private function buildExportDownloadUrl(string $jobId, string $reportType, string $storeId): string
    {
        $prefix = mb_strtolower(trim($reportType)) === 'order_list'
            ? 'orders'
            : 'reports';

        return sprintf(
            '%s/exports/%s/download?store_id=%s',
            $prefix,
            rawurlencode($jobId),
            rawurlencode($storeId)
        );
    }

    private function resolveExportStoragePath(string $fileUrl): ?string
    {
        $fileUrl = trim($fileUrl);
        if ($fileUrl === '') {
            return null;
        }

        $path = parse_url($fileUrl, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $fileUrl;
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, '/storage/')) {
            $path = substr($path, strlen('/storage/'));
        } elseif (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $path = ltrim(urldecode($path), '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private function resolveContext(Request $request): array
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);

        return [
            'user' => $user,
            'store' => $store,
            'error' => $error,
        ];
    }

    private function authorizeExportRequest(?User $user, ?Store $store, string $reportType): ?JsonResponse
    {
        if (!$user || !$store) {
            return null;
        }

        if ($this->hasStoreAdminOverride($user, $store)) {
            return null;
        }

        $reportType = trim(mb_strtolower($reportType));
        $requiredCode = $reportType === 'order_list' ? 'EXPORT_ORDER' : 'EXPORT_REPORT';

        if ($this->access->hasAnyPermission((int) $user->id, (string) $store->id, [$requiredCode])) {
            return null;
        }

        return $this->errorResponse('FORBIDDEN_PERMISSION', 'Ban khong co quyen xuat du lieu nay trong cua hang hien tai.', 403, true, true, [
            'report_type' => $reportType,
            'permission_code' => $requiredCode,
            'store_id' => (string) $store->id,
        ]);
    }

    private function hasStoreAdminOverride(User $user, Store $store): bool
    {
        if ((string) $store->owner_id === (string) $user->id) {
            return true;
        }

        return in_array((string) $user->role, ['admin', 'super_admin'], true);
    }

    private function errorResponse(string $code, string $message, int $status, bool $authenticated, bool $hasStore, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
