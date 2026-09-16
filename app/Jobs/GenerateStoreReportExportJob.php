<?php

namespace App\Jobs;

use App\Exports\StoreAdminReportArrayExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class GenerateStoreReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TICKET_VALUE_VND = 5000;

    public string $jobId;

    public string $storeId;

    public array $filters;

    public int $tries = 1;

    public function __construct(string $jobId, string $storeId, array $filters)
    {
        $this->jobId = $jobId;
        $this->storeId = $storeId;
        $this->filters = $filters;
    }

    public function handle(): void
    {
        if (! Schema::hasTable('report_exports')) {
            return;
        }

        $this->updateExport([
            'status' => 'processing',
            'progress_percent' => 10,
            'error_message' => null,
            'updated_at' => now(),
        ]);

        $reportType = (string) ($this->filters['report_type'] ?? 'revenue');
        $format = (string) ($this->filters['format'] ?? 'csv');

        try {
            [$headings, $rows, $sheetTitle] = $this->buildRows($reportType);

            $this->updateExport([
                'progress_percent' => 70,
                'updated_at' => now(),
            ]);

            $extension = $format === 'xlsx' ? 'xlsx' : 'csv';
            $fileName = sprintf(
                '%s_%s_%s.%s',
                $reportType,
                now()->format('Ymd_His'),
                Str::lower(substr(str_replace('-', '', $this->jobId), 0, 8)),
                $extension
            );

            $relativePath = 'reports/store-admin/'.$this->storeId.'/'.$fileName;
            $writerType = $extension === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV;

            Excel::store(new StoreAdminReportArrayExport($headings, $rows, $sheetTitle), $relativePath, 'public', $writerType);

            $fileUrl = asset('storage/'.ltrim($relativePath, '/'));

            $this->updateExport([
                'status' => 'completed',
                'progress_percent' => 100,
                'file_url' => $fileUrl,
                'error_message' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->updateExport([
                'status' => 'failed',
                'progress_percent' => 100,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        }
    }

    private function buildRows(string $reportType): array
    {
        if ($reportType === 'ticket_consumption') {
            return $this->buildTicketConsumptionRows();
        }

        if ($reportType === 'ticket_redemption') {
            return $this->buildTicketRedemptionRows();
        }

        if ($reportType === 'order_list') {
            return $this->buildOrderListRows();
        }

        return $this->buildRevenueRows();
    }

    private function buildRevenueRows(): array
    {
        $period = (string) ($this->filters['period'] ?? 'day');
        $to = (string) ($this->filters['to'] ?? now()->toDateString());
        $from = $this->resolveFromDate($period, $this->filters['from'] ?? null);
        $headings = [
            'Thời gian',
            'Mã đơn nội bộ',
            'Mã đơn hàng',
            'Trạng thái',
            'Phương thức thanh toán',
            'Doanh thu đơn hàng',
            'Giảm giá',
            'Số ticket đã dùng',
            'Giá trị ticket (VND)',
            'Ngày tạo',
            'Ngày giao',
        ];

        if (! Schema::hasTable('orders')) {
            return [
                $headings,
                $this->appendRevenueSummaryRows([], $headings, $this->emptyRevenueSummary()),
                'Báo cáo doanh thu',
            ];
        }

        $amountColumn = $this->resolveAmountColumn();
        $dateExpression = $this->resolveOrderDateExpression('orders');
        $bucketExpr = $this->bucketExpr($dateExpression, $period);

        $query = DB::table('orders')
            ->where('store_id', $this->storeId)
            ->whereRaw('DATE('.$dateExpression.') >= ?', [$from])
            ->whereRaw('DATE('.$dateExpression.') <= ?', [$to]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(orders.status, ""))'), $this->completedOrderStatuses());
        }

        $rows = $query
            ->selectRaw($bucketExpr.' as bucket')
            ->selectRaw('orders.id as order_id')
            ->selectRaw($this->orderCodeExpression('orders').' as order_code')
            ->selectRaw(Schema::hasColumn('orders', 'status') ? 'LOWER(COALESCE(orders.status, "")) as status' : '"" as status')
            ->selectRaw(Schema::hasColumn('orders', 'payment_method') ? 'LOWER(COALESCE(orders.payment_method, "")) as payment_method' : '"" as payment_method')
            ->selectRaw('COALESCE(orders.'.$amountColumn.', 0) as final_amount')
            ->selectRaw(Schema::hasColumn('orders', 'discount_amount') ? 'COALESCE(orders.discount_amount, 0) as discount_amount' : '0 as discount_amount')
            ->selectRaw(
                Schema::hasColumn('orders', 'total_tickets_used')
                    ? 'COALESCE(orders.total_tickets_used, 0) as tickets_used'
                    : '0 as tickets_used'
            )
            ->selectRaw('COALESCE(orders.created_at, orders.updated_at) as created_at')
            ->selectRaw(Schema::hasColumn('orders', 'delivered_at') ? 'orders.delivered_at as delivered_at' : 'NULL as delivered_at')
            ->orderBy('bucket')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row) {
                $tickets = (int) ($row->tickets_used ?? 0);

                return [
                    (string) ($row->bucket ?? ''),
                    (string) ($row->order_id ?? ''),
                    (string) ($row->order_code ?? ''),
                    (string) ($row->status ?? ''),
                    (string) ($row->payment_method ?? ''),
                    round((float) ($row->final_amount ?? 0), 2),
                    round((float) ($row->discount_amount ?? 0), 2),
                    $tickets,
                    $tickets * self::TICKET_VALUE_VND,
                    (string) ($row->created_at ?? ''),
                    (string) ($row->delivered_at ?? ''),
                ];
            })
            ->values()
            ->all();

        return [
            $headings,
            $this->appendRevenueSummaryRows($rows, $headings, $this->buildRevenueSummary($from, $to)),
            'Báo cáo doanh thu',
        ];
    }

    private function buildTicketRedemptionRows(): array
    {
        $period = (string) ($this->filters['period'] ?? 'day');
        $status = mb_strtolower(trim((string) ($this->filters['status'] ?? 'delivered')));
        $to = (string) ($this->filters['to'] ?? now()->toDateString());
        $from = $this->resolveFromDate($period, $this->filters['from'] ?? null);
        $headings = ['Thời gian', 'Số ticket đã đổi', 'Giá trị quy đổi (VND)', 'Số đơn hàng'];

        $requiredTablesReady = Schema::hasTable('orders')
            && Schema::hasTable('order_tickets')
            && Schema::hasColumn('order_tickets', 'order_id');

        if (! $requiredTablesReady) {
            return [
                $headings,
                [],
                'Báo cáo đổi ticket',
            ];
        }

        $dateColumn = 'ot.used_at';
        if (! Schema::hasColumn('order_tickets', 'used_at')) {
            $dateColumn = Schema::hasColumn('orders', 'delivered_at') ? 'o.delivered_at' : 'o.created_at';
        }

        $bucketExpr = $this->bucketExpr($dateColumn, $period);

        $hasTicketJoin = Schema::hasTable('tickets')
            && Schema::hasColumn('order_tickets', 'ticket_id')
            && Schema::hasColumn('tickets', 'id');

        $valueExpr = $hasTicketJoin && Schema::hasColumn('tickets', 'value_amount')
            ? 'COALESCE(t.value_amount, '.self::TICKET_VALUE_VND.')'
            : (string) self::TICKET_VALUE_VND;

        $query = DB::table('order_tickets as ot')
            ->join('orders as o', 'o.id', '=', 'ot.order_id')
            ->where('o.store_id', $this->storeId)
            ->whereDate(DB::raw($dateColumn), '>=', $from)
            ->whereDate(DB::raw($dateColumn), '<=', $to);

        if ($hasTicketJoin) {
            $query->leftJoin('tickets as t', 't.id', '=', 'ot.ticket_id');
        }

        if (Schema::hasColumn('orders', 'status') && $status !== '' && $status !== 'all') {
            $query->whereRaw('LOWER(o.status) = ?', [$status]);
        }

        $rows = $query
            ->selectRaw($bucketExpr.' as bucket')
            ->selectRaw('COUNT(*) as redeemed_tickets')
            ->selectRaw('COALESCE(SUM('.$valueExpr.'), 0) as redemption_value_vnd')
            ->selectRaw('COUNT(DISTINCT o.id) as orders_count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(function ($row) {
                return [
                    (string) ($row->bucket ?? ''),
                    (int) ($row->redeemed_tickets ?? 0),
                    round((float) ($row->redemption_value_vnd ?? 0), 2),
                    (int) ($row->orders_count ?? 0),
                ];
            })
            ->values()
            ->all();

        return [
            $headings,
            $rows,
            'Báo cáo đổi ticket',
        ];
    }

    private function buildTicketConsumptionRows(): array
    {
        $period = (string) ($this->filters['period'] ?? 'day');
        $to = (string) ($this->filters['to'] ?? now()->toDateString());
        $from = $this->resolveFromDate($period, $this->filters['from'] ?? null);
        $headings = ['Thời gian', 'Mã sản phẩm', 'Tên sản phẩm', 'Số ticket đã tiêu hao', 'Tổng giá trị ticket (VND)'];

        if (! Schema::hasTable('store_ticket_transactions')) {
            return [
                $headings,
                [],
                'Báo cáo dùng ticket',
            ];
        }

        $bucketExpr = $this->bucketExpr('stt.timestamp', $period);

        $rows = DB::table('store_ticket_transactions as stt')
            ->leftJoin('products as p', 'p.id', '=', 'stt.product_id')
            ->where('stt.store_id', $this->storeId)
            ->whereDate('stt.timestamp', '>=', $from)
            ->whereDate('stt.timestamp', '<=', $to)
            ->when(Schema::hasColumn('store_ticket_transactions', 'event_type'), function ($query) {
                $query->whereIn('stt.event_type', ['ORDER_DELIVERED', 'ORDER_REFUND']);
            })
            ->selectRaw($bucketExpr.' as bucket')
            ->selectRaw('stt.product_id as product_id')
            ->selectRaw('COALESCE(MAX(p.name), CONCAT(\'Product #\', stt.product_id)) as product_name')
            ->selectRaw('COALESCE(SUM(stt.tickets_consumed), 0) as tickets_consumed')
            ->selectRaw('COALESCE(SUM(stt.total_debit_amount), 0) as total_debit_amount')
            ->groupBy('bucket', 'stt.product_id')
            ->orderBy('bucket')
            ->orderBy('stt.product_id')
            ->get()
            ->map(static function ($row): array {
                return [
                    (string) ($row->bucket ?? ''),
                    $row->product_id !== null ? (int) $row->product_id : null,
                    (string) ($row->product_name ?? ''),
                    (int) ($row->tickets_consumed ?? 0),
                    round((float) ($row->total_debit_amount ?? 0), 2),
                ];
            })
            ->values()
            ->all();

        return [
            $headings,
            $rows,
            'Báo cáo dùng ticket',
        ];
    }

    private function buildOrderListRows(): array
    {
        $headings = [
            'Mã đơn nội bộ',
            'Mã đơn hàng',
            'Trạng thái',
            'Tên khách hàng',
            'Số điện thoại',
            'Tên sản phẩm',
            'Số lượng',
            'Đơn giá',
            'Thành tiền',
            'Ngày tạo',
            'Ngày giao',
        ];

        if (! Schema::hasTable('orders')) {
            return [
                $headings,
                [],
                'Danh sách đơn hàng',
            ];
        }

        $status = mb_strtolower(trim((string) ($this->filters['status'] ?? '')));
        $from = (string) ($this->filters['from'] ?? '');
        $to = (string) ($this->filters['to'] ?? '');

        $query = DB::table('orders as o')
            ->where('o.store_id', $this->storeId);

        if ($from !== '') {
            $query->whereDate('o.created_at', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('o.created_at', '<=', $to);
        }

        if ($status !== '' && $status !== 'all' && Schema::hasColumn('orders', 'status')) {
            $query->whereRaw('LOWER(o.status) = ?', [$status]);
        }

        $canJoinItems = Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'order_id');
        if ($canJoinItems) {
            $query->leftJoin('order_items as oi', 'oi.order_id', '=', 'o.id');
        }

        $canJoinUsers = Schema::hasTable('users') && Schema::hasColumn('orders', 'user_id') && Schema::hasColumn('users', 'id');
        if ($canJoinUsers) {
            $query->leftJoin('users as u', 'u.id', '=', 'o.user_id');
        }

        $customerNameExpr = "''";
        if ($canJoinUsers && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
        } elseif ($canJoinUsers && Schema::hasColumn('users', 'full_name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
        } elseif ($canJoinUsers && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
        }

        $hasItemName = $canJoinItems && Schema::hasColumn('order_items', 'item_name');
        $hasProductName = $canJoinItems && Schema::hasColumn('order_items', 'product_name');
        $itemNameExpr = $hasItemName ? 'oi.item_name' : ($hasProductName ? 'oi.product_name' : "''");

        $quantityExpr = $canJoinItems && Schema::hasColumn('order_items', 'quantity') ? 'oi.quantity' : 'NULL as quantity';
        $unitPriceExpr = $canJoinItems && Schema::hasColumn('order_items', 'unit_price')
            ? 'oi.unit_price'
            : (Schema::hasColumn('order_items', 'price') ? 'oi.price' : 'NULL as unit_price');
        $totalPriceExpr = $canJoinItems && Schema::hasColumn('order_items', 'total_price') ? 'oi.total_price' : 'NULL as total_price';

        $rows = $query
            ->select([
                'o.id as order_id',
                DB::raw($this->orderCodeExpression('o').' as order_code'),
                DB::raw('LOWER(COALESCE(o.status, "")) as status'),
                DB::raw($customerNameExpr.' as customer_name'),
                DB::raw($canJoinUsers && Schema::hasColumn('users', 'phone') ? 'u.phone as customer_phone' : 'NULL as customer_phone'),
                DB::raw($itemNameExpr.' as item_name'),
                DB::raw($quantityExpr),
                DB::raw($unitPriceExpr),
                DB::raw($totalPriceExpr),
                'o.created_at',
                DB::raw(Schema::hasColumn('orders', 'delivered_at') ? 'o.delivered_at' : 'NULL as delivered_at'),
            ])
            ->orderByDesc('o.created_at')
            ->get()
            ->map(function ($row) {
                return [
                    (string) ($row->order_id ?? ''),
                    (string) ($row->order_code ?? ''),
                    (string) ($row->status ?? ''),
                    (string) ($row->customer_name ?? ''),
                    (string) ($row->customer_phone ?? ''),
                    (string) ($row->item_name ?? ''),
                    isset($row->quantity) ? (int) $row->quantity : null,
                    isset($row->unit_price) ? round((float) $row->unit_price, 2) : null,
                    isset($row->total_price) ? round((float) $row->total_price, 2) : null,
                    (string) ($row->created_at ?? ''),
                    (string) ($row->delivered_at ?? ''),
                ];
            })
            ->values()
            ->all();

        return [
            $headings,
            $rows,
            'Danh sách đơn hàng',
        ];
    }

    private function appendRevenueSummaryRows(array $rows, array $headings, array $summary): array
    {
        $blankRow = array_fill(0, count($headings), '');
        $rows[] = $blankRow;

        $summaryHeader = $blankRow;
        $summaryHeader[0] = 'Tổng hợp';
        $summaryHeader[1] = 'Giá trị';
        $summaryHeader[2] = 'Đơn vị';
        $rows[] = $summaryHeader;

        $summaryRows = [
            ['Tổng doanh thu', round((float) ($summary['revenue_total'] ?? 0), 2)],
            ['Hoa hồng affiliate', round((float) ($summary['affiliate_commission'] ?? 0), 2)],
            ['Chi phí ticket', round((float) ($summary['ticket_reward'] ?? 0), 2)],
            ['Thực nhận', round(max(0.0, (float) ($summary['net_amount'] ?? 0)), 2)],
        ];

        foreach ($summaryRows as [$label, $amount]) {
            $row = $blankRow;
            $row[0] = $label;
            $row[1] = $amount;
            $row[2] = 'VND';
            $rows[] = $row;
        }

        return $rows;
    }

    private function emptyRevenueSummary(): array
    {
        return [
            'revenue_total' => 0.0,
            'affiliate_commission' => 0.0,
            'ticket_reward' => 0.0,
            'net_amount' => 0.0,
        ];
    }

    private function buildRevenueSummary(string $from, string $to): array
    {
        if (! Schema::hasTable('orders')) {
            return $this->emptyRevenueSummary();
        }

        $fromDate = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        $toDate = Carbon::createFromFormat('Y-m-d', $to)->endOfDay();
        $amountColumn = $this->resolveAmountColumn();
        $orderDateExpression = $this->resolveOrderDateExpression('orders');

        $ordersQuery = DB::table('orders')
            ->where('store_id', $this->storeId)
            ->whereRaw('DATE('.$orderDateExpression.') >= ?', [$fromDate->toDateString()])
            ->whereRaw('DATE('.$orderDateExpression.') <= ?', [$toDate->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $ordersQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $revenueBuckets = [
            'cash' => 0.0,
            'transfer' => 0.0,
            'cod' => 0.0,
            'other' => 0.0,
        ];

        if (Schema::hasColumn('orders', 'payment_method')) {
            $paymentRows = $ordersQuery
                ->selectRaw('LOWER(COALESCE(payment_method, "")) as method')
                ->selectRaw('COALESCE(SUM(`'.$amountColumn.'`), 0) as total_amount')
                ->groupBy('method')
                ->get();

            foreach ($paymentRows as $row) {
                $bucket = $this->mapPaymentMethodBucket((string) ($row->method ?? ''));
                $revenueBuckets[$bucket] += (float) ($row->total_amount ?? 0);
            }
        } else {
            $revenueBuckets['other'] = (float) $ordersQuery->sum($amountColumn);
        }

        $revenueBuckets['transfer'] += $this->sumPendingTransferRevenue($this->storeId, $fromDate, $toDate);

        $revenueTotal = array_sum($revenueBuckets);
        $commissionTotal = $this->sumAffiliateCommission($this->storeId, $fromDate, $toDate)
            + $this->sumPendingTransferAffiliateCommission($this->storeId, $fromDate, $toDate);
        $ticketRewardTotal = $this->sumTicketReward($this->storeId, $fromDate, $toDate);

        return [
            'revenue_total' => $revenueTotal,
            'affiliate_commission' => $commissionTotal,
            'ticket_reward' => $ticketRewardTotal,
            'net_amount' => $revenueTotal - $commissionTotal - $ticketRewardTotal,
        ];
    }

    private function resolveFromDate(string $period, ?string $from): string
    {
        if (! empty($from)) {
            return (string) $from;
        }

        if ($period === 'week') {
            return now()->startOfWeek()->subWeeks(11)->toDateString();
        }

        if ($period === 'month') {
            return now()->startOfMonth()->subMonths(11)->toDateString();
        }

        return now()->subDays(29)->toDateString();
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
            return 'COALESCE('.$alias.'.delivered_at, '.$alias.'.created_at)';
        }

        return $alias.'.created_at';
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

    private function sumAffiliateCommission(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (
            ! Schema::hasTable('orders')
            || ! Schema::hasTable('commission_users')
            || ! Schema::hasTable('order_items')
            || ! Schema::hasColumn('commission_users', 'order_item_id')
            || ! Schema::hasColumn('commission_users', 'amount')
            || ! Schema::hasColumn('order_items', 'order_id')
        ) {
            return 0.0;
        }

        $orderDateExpression = $this->resolveOrderDateExpression('orders');

        $query = DB::table('commission_users')
            ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereRaw('DATE('.$orderDateExpression.') >= ?', [$from->toDateString()])
            ->whereRaw('DATE('.$orderDateExpression.') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(orders.status, ""))'), $this->completedOrderStatuses());
        }

        if ($confirmedAfter) {
            $query->where('orders.created_at', '>', $confirmedAfter);
        }

        if (Schema::hasColumn('commission_users', 'status')) {
            $query->whereIn('commission_users.status', ['paid', 'pending']);
        }

        return (float) $query->sum('commission_users.amount');
    }

    private function sumPendingTransferAffiliateCommission(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (
            ! Schema::hasTable('orders')
            || ! Schema::hasTable('commission_users')
            || ! Schema::hasTable('order_items')
            || ! Schema::hasColumn('commission_users', 'order_item_id')
            || ! Schema::hasColumn('commission_users', 'amount')
            || ! Schema::hasColumn('order_items', 'order_id')
        ) {
            return 0.0;
        }

        $orderDateExpression = $this->resolveOrderDateExpression('orders');

        $query = DB::table('commission_users')
            ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereRaw('DATE('.$orderDateExpression.') >= ?', [$from->toDateString()])
            ->whereRaw('DATE('.$orderDateExpression.') <= ?', [$to->toDateString()]);

        if ($confirmedAfter) {
            $query->where('orders.created_at', '>', $confirmedAfter);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereNotIn(DB::raw('LOWER(COALESCE(orders.status, ""))'), $this->completedOrderStatuses());
        }

        if (Schema::hasColumn('orders', 'payment_method')) {
            $query->where(function ($builder) {
                $builder
                    ->whereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%bank%'])
                    ->orWhereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%transfer%'])
                    ->orWhereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%vnpay%'])
                    ->orWhereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%momo%']);
            });
        }

        if (Schema::hasColumn('commission_users', 'status')) {
            $query->where('commission_users.status', 'pending');
        }

        return (float) $query->sum('commission_users.amount');
    }

    private function sumPendingTransferRevenue(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'payment_method')) {
            return 0.0;
        }

        $amountColumn = $this->resolveAmountColumn();
        $query = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString());

        if ($confirmedAfter) {
            $query->where('created_at', '>', $confirmedAfter);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereNotIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $query->where(function ($builder) {
            $builder
                ->whereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%bank%'])
                ->orWhereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%transfer%'])
                ->orWhereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%vnpay%'])
                ->orWhereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%momo%']);
        });

        return (float) $query->sum($amountColumn);
    }

    private function sumTicketReward(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'order_id')) {
            return 0.0;
        }

        $orderDateExpression = $this->resolveOrderDateExpression('o');

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.store_id', $storeId)
            ->whereRaw('DATE('.$orderDateExpression.') >= ?', [$from->toDateString()])
            ->whereRaw('DATE('.$orderDateExpression.') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(o.status, ""))'), $this->completedOrderStatuses());
        }

        if ($confirmedAfter) {
            $query->where('o.created_at', '>', $confirmedAfter);
        }

        $hasVariantReward = Schema::hasTable('product_variants')
            && Schema::hasColumn('product_variants', 'ticket_reward_quantity')
            && Schema::hasColumn('order_items', 'variant_id');
        $hasProductReward = Schema::hasTable('products')
            && Schema::hasColumn('products', 'ticket_reward_quantity')
            && Schema::hasColumn('order_items', 'product_id');

        if ($hasVariantReward) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        if ($hasProductReward) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }

        $quantityExpr = Schema::hasColumn('order_items', 'quantity')
            ? 'COALESCE(oi.quantity, 0) as quantity'
            : '0 as quantity';
        $rewardExpr = $hasVariantReward && $hasProductReward
            ? 'COALESCE(pv.ticket_reward_quantity, p.ticket_reward_quantity, 0) as ticket_reward_quantity'
            : ($hasVariantReward
                ? 'COALESCE(pv.ticket_reward_quantity, 0) as ticket_reward_quantity'
                : ($hasProductReward
                    ? 'COALESCE(p.ticket_reward_quantity, 0) as ticket_reward_quantity'
                    : '0 as ticket_reward_quantity'));

        $rows = $query
            ->selectRaw($quantityExpr)
            ->selectRaw($rewardExpr)
            ->get();

        $totalTickets = 0;
        foreach ($rows as $row) {
            $quantity = max(0, (int) ($row->quantity ?? 0));
            $rewardPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));
            $totalTickets += $quantity * $rewardPerUnit;
        }

        if (
            $totalTickets <= 0
            && Schema::hasTable('store_ticket_transactions')
            && Schema::hasColumn('store_ticket_transactions', 'timestamp')
            && Schema::hasColumn('store_ticket_transactions', 'tickets_consumed')
        ) {
            $query = DB::table('store_ticket_transactions')
                ->where('store_id', $storeId)
                ->whereBetween('timestamp', [$from->toDateTimeString(), $to->toDateTimeString()]);

            if ($confirmedAfter) {
                $query->where('timestamp', '>', $confirmedAfter);
            }

            if (Schema::hasColumn('store_ticket_transactions', 'event_type')) {
                $query->whereIn('event_type', ['ORDER_DELIVERED', 'ORDER_REFUND']);
            }

            $totalTickets = (int) $query->sum('tickets_consumed');
        }

        return (float) $totalTickets * self::TICKET_VALUE_VND;
    }

    private function orderCodeExpression(string $ordersAlias): string
    {
        $columns = [];

        if (Schema::hasColumn('orders', 'order_code')) {
            $columns[] = $ordersAlias.'.order_code';
        }

        if (Schema::hasColumn('orders', 'code')) {
            $columns[] = $ordersAlias.'.code';
        }

        $columns[] = $ordersAlias.'.id';

        return count($columns) === 1 ? $columns[0] : 'COALESCE('.implode(', ', $columns).')';
    }

    private function bucketExpr(string $column, string $period): string
    {
        if ($period === 'week') {
            return 'DATE_FORMAT('.$column.", '%x-W%v')";
        }

        if ($period === 'month') {
            return 'DATE_FORMAT('.$column.", '%Y-%m')";
        }

        return 'DATE('.$column.')';
    }

    private function updateExport(array $values): void
    {
        DB::table('report_exports')
            ->where('id', $this->jobId)
            ->where('store_id', $this->storeId)
            ->update($values);
    }
}
