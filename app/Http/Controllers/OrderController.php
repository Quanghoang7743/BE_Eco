<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ClientController as ApiClientController;
use App\Models\Order;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminCmsAccess;
use App\Services\AffiliateCommissionService;
use App\Services\OrderStockRestorationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    private const SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const TICKET_STATUS_GROUPS = [
        'eligible' => ['ELIGIBLE', 'AVAILABLE', 'USED_FOR_ORDER', 'USED_IN_ROOM'],
        'held' => ['HELD', 'DISPUTED', 'LOCKED'],
        'cancelled' => ['CANCELLED', 'CANCELED', 'EXPIRED', 'REFUNDED'],
    ];
    private const RESOLVED_COMPLAINT_STATUSES = ['resolved', 'closed', 'rejected', 'cancelled', 'canceled', 'done'];

    private array $schemaTableCache = [];
    private array $schemaColumnCache = [];

    public function index(Request $request)
    {
        return view('admin.orders.index', [
            'isSaleUser' => AdminCmsAccess::isSale(),
            'canManageOrders' => ! AdminCmsAccess::isSale(),
            'storeOptions' => $this->orderStoreOptions(),
            'defaultStatus' => AdminCmsAccess::isSale() ? 'successful' : 'all',
        ]);
    }

    public function datatable(Request $request)
    {
        $columns = [
            1 => $this->hasColumn('orders', 'order_code') ? 'order_code' : 'id',
            3 => 'name',
            4 => 'shipping_method',
            5 => $this->orderAmountColumn(),
            6 => 'created_at',
            7 => 'status',
            8 => 'status_payment',
        ];

        $search = (string) $request->input('search.value', '');
        $status = (string) $request->input('status', AdminCmsAccess::isSale() ? 'successful' : 'all');
        if (AdminCmsAccess::isSale() && ! in_array($status, ['successful', 'completed', 'delivered', 'call_completed'], true)) {
            $status = 'successful';
        }
        $storeId = trim((string) $request->input('store_id', ''));
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $orderField = $columns[(int) $request->input('order.0.column', 1)] ?? 'id';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $query = Order::with([
            'store:id,name,store_code,sale_id,sale_commission_rate',
            'user:id,name,full_name,phone,email',
        ]);

        AdminCmsAccess::applyOrderScope($query);
        $this->applyOrderStatusFilter($query, $status);
        $this->applyOrderStoreFilter($query, $storeId);
        $this->applyOrderDateFilter($query, $fromDate, $toDate);

        $recordsTotal = $query->count();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%");

                if ($this->hasColumn('orders', 'order_code')) {
                    $q->orWhere('order_code', 'like', "%{$search}%");
                }

                if ($this->hasColumn('orders', 'phone')) {
                    $q->orWhere('phone', 'like', "%{$search}%");
                }

                $q->orWhereHas('store', function ($storeQuery) use ($search) {
                    $storeQuery->where('name', 'like', "%{$search}%");

                    if ($this->hasColumn('stores', 'store_code')) {
                        $storeQuery->orWhere('store_code', 'like', "%{$search}%");
                    }
                });
            });
        }

        $recordsFiltered = $search === '' ? $recordsTotal : $query->count();

        $orders = $query
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $ticketCounts = $this->ticketCountsForOrders($orders);
        $canManageOrders = ! AdminCmsAccess::isSale();
        $isSaleUser = AdminCmsAccess::isSale();

        $data = $orders->map(function (Order $order) use (
            $ticketCounts,
            $canManageOrders,
            $isSaleUser
        ) {
            $orderId = (string) $order->id;

            $status = $this->orderStatusLabel((string) $order->status);
            $statusPayment = $this->paymentStatusLabel((string) $order->status_payment);

            return [
                'checkbox' => $canManageOrders
                    ? '<input type="checkbox" class="order-row-checkbox" value="' . $order->id . '">'
                    : '',
                'id' => $this->formatOrderIdentifier($order),
                'store' => $this->formatStoreCell($order),
                'name' => e($this->customerNameForDisplay($order, $isSaleUser)),
                'shipping_method' => $this->formatShippingMethod($order->shipping_method),
                'total' => number_format($this->orderAmount($order), 0, ',', '.') . ' VND',
                'created_at' => $this->formatOrderTime($order),
                'status' => '<span class="badge fs-5 bg-' . $status['class'] . '">' . $status['label'] . '</span>',
                'status_payment' => '<span class="badge fs-5 bg-' . $statusPayment['class'] . '">' . $statusPayment['label'] . '</span>',
                'ticket_count' => number_format((int) $ticketCounts->get($orderId, 0)),
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    private function applyOrderStatusFilter($query, string $status): void
    {
        $status = strtolower(trim($status));

        if ($status === '' || $status === 'all') {
            return;
        }

        if ($status === 'new') {
            $query->whereIn('orders.status', ['pending', 'pending_payment']);
            return;
        }

        if ($status === 'processing') {
            $query->whereIn('orders.status', ['paid_pending_call', 'approved', 'preparing', 'ready', 'picked_up']);
            return;
        }

        if ($status === 'confirmed') {
            $query->where('orders.status', 'confirmed');
            return;
        }

        if ($status === 'completed') {
            $query->whereIn('orders.status', ['completed', 'delivered', 'call_completed']);
            return;
        }

        if ($status === 'cancelled') {
            $query->whereIn('orders.status', ['cancelled', 'canceled']);
            return;
        }

        if ($status === 'returned') {
            $query->where(function ($q) {
                $q->where('orders.status', 'returned')
                  ->orWhere('orders.status_payment', 'refunded');
            });
            return;
        }

        if ($status === 'partially_refunded') {
            $query->whereIn('orders.id', function ($q) {
                $q->select('source_id')->from('refunds');
            });
            return;
        }

        if ($status === 'successful') {
            $query->whereIn(DB::raw("LOWER(COALESCE(orders.status, ''))"), self::SUCCESS_ORDER_STATUSES);
            return;
        }

        $query->whereRaw("LOWER(COALESCE(orders.status, '')) = ?", [$status]);
    }

    private function applyOrderStoreFilter($query, string $storeId): void
    {
        if ($storeId === '' || ! $this->hasColumn('orders', 'store_id')) {
            return;
        }

        $query->where('orders.store_id', $storeId);
    }

    private function applyOrderDateFilter($query, mixed $fromDate, mixed $toDate): void
    {
        if (! $fromDate && ! $toDate) {
            return;
        }

        $from = $fromDate
            ? Carbon::parse($fromDate)->startOfDay()
            : Carbon::minValue();

        $to = $toDate
            ? Carbon::parse($toDate)->endOfDay()
            : Carbon::now()->endOfDay();

        $dateExpression = $this->hasColumn('orders', 'delivered_at')
            ? 'COALESCE(orders.delivered_at, orders.created_at)'
            : 'orders.created_at';

        $query->whereRaw($dateExpression . ' BETWEEN ? AND ?', [
            $from->toDateTimeString(),
            $to->toDateTimeString(),
        ]);
    }

    private function orderStoreOptions(): Collection
    {
        if (! $this->hasTable('stores')) {
            return collect();
        }

        $user = AdminCmsAccess::currentUser();
        $cacheKey = 'admin.orders.store_options.' . ($user?->id ?? 'guest') . '.' . ($user?->role ?? 'none');

        return Cache::remember($cacheKey, now()->addMinutes(5), function (): Collection {
            $columns = ['id', 'name'];
            foreach (['store_code', 'sale_id'] as $column) {
                if ($this->hasColumn('stores', $column)) {
                    $columns[] = $column;
                }
            }

            $query = Store::query()->select($columns);
            AdminCmsAccess::applyStoreScope($query);

            return $query->orderBy('name')->get();
        });
    }

    private function ticketCountsForOrders(Collection $orders): Collection
    {
        $orderIds = $orders->pluck('id')->map(static fn ($id): string => (string) $id)->values();

        if ($orderIds->isEmpty() || ! $this->hasTable('tickets')) {
            return collect();
        }

        $ticketQueries = [];

        if ($this->hasColumn('tickets', 'source_id')) {
            $ticketQueries[] = DB::table('tickets')
                ->selectRaw('source_id as order_id, id')
                ->whereIn('source_id', $orderIds);
        }

        if ($this->hasColumn('tickets', 'used_in_order_id')) {
            $ticketQueries[] = DB::table('tickets')
                ->selectRaw('used_in_order_id as order_id, id')
                ->whereIn('used_in_order_id', $orderIds);
        }

        if (
            $this->hasTable('order_tickets')
            && $this->hasColumn('order_tickets', 'order_id')
            && $this->hasColumn('order_tickets', 'ticket_id')
        ) {
            $ticketQueries[] = DB::table('order_tickets as ot')
                ->join('tickets as t', 't.id', '=', 'ot.ticket_id')
                ->selectRaw('ot.order_id as order_id, t.id')
                ->whereIn('ot.order_id', $orderIds);
        }

        if ($ticketQueries === []) {
            return collect();
        }

        $query = array_shift($ticketQueries);
        foreach ($ticketQueries as $ticketQuery) {
            $query->unionAll($ticketQuery);
        }

        $rows = $query->get();

        return $rows
            ->filter(fn ($row): bool => ! empty($row->order_id) && ! empty($row->id))
            ->unique(fn ($row): string => (string) $row->order_id . '|' . (string) $row->id)
            ->groupBy(fn ($row): string => (string) $row->order_id)
            ->map(static fn (Collection $ticketRows): int => $ticketRows->count());
    }

    private function emptyTicketSummary(): array
    {
        return [
            'total' => 0,
            'eligible' => 0,
            'held' => 0,
            'cancelled' => 0,
            'other' => 0,
            'ticket_value' => 0.0,
            'statuses' => [],
        ];
    }

    private function ticketStatusGroup(string $status): string
    {
        foreach (self::TICKET_STATUS_GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return 'other';
    }

    private function earningSummariesForOrders(Collection $orders): Collection
    {
        $orderIds = $orders->pluck('id')->map(static fn ($id): string => (string) $id)->values();

        if (
            $orderIds->isEmpty()
            || ! $this->hasTable('specialist_ticket_earnings')
            || ! $this->hasColumn('specialist_ticket_earnings', 'order_id')
        ) {
            return collect();
        }

        $query = DB::table('specialist_ticket_earnings')
            ->whereIn('order_id', $orderIds);

        if (AdminCmsAccess::isSale() && $this->hasColumn('specialist_ticket_earnings', 'specialist_id')) {
            $query->where('specialist_id', auth()->id());
        }

        return $query->get()->groupBy(fn ($row): string => (string) $row->order_id)
            ->map(function (Collection $rows): array {
                $hasEligibilityColumn = $this->hasColumn('specialist_ticket_earnings', 'is_eligible');
                $hasReasonColumn = $this->hasColumn('specialist_ticket_earnings', 'reason_not_eligible');

                $eligibleRows = $hasEligibilityColumn
                    ? $rows->where('is_eligible', 1)
                    : collect();
                $ineligibleRows = $hasEligibilityColumn
                    ? $rows->where('is_eligible', 0)
                    : collect();

                $reason = '';
                if ($hasReasonColumn) {
                    $reason = (string) ($ineligibleRows->first()->reason_not_eligible ?? '');
                }

                $status = strtolower((string) ($rows->first()->status ?? ''));
                $amount = (float) $rows->sum(fn ($row): float => (float) ($row->earning_amount ?? 0));

                return [
                    'exists' => true,
                    'is_eligible' => $hasEligibilityColumn
                        ? ($eligibleRows->isNotEmpty() ? true : ($ineligibleRows->isNotEmpty() ? false : null))
                        : null,
                    'status' => $status,
                    'amount' => $amount,
                    'reason' => $reason,
                ];
            });
    }

    private function refundedOrderIds(Collection $orders): Collection
    {
        $orderIds = $orders->pluck('id')->map(static fn ($id): string => (string) $id)->values();

        if (
            $orderIds->isEmpty()
            || ! $this->hasTable('refunds')
            || ! $this->hasColumn('refunds', 'source_id')
        ) {
            return collect();
        }

        return DB::table('refunds')
            ->whereIn('source_id', $orderIds)
            ->pluck('source_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();
    }

    private function activeComplaintCountsForOrders(Collection $orders): Collection
    {
        $orderIds = $orders->pluck('id')->map(static fn ($id): string => (string) $id)->values();

        if (
            $orderIds->isEmpty()
            || ! $this->hasTable('complaints')
            || ! $this->hasColumn('complaints', 'order_id')
        ) {
            return collect();
        }

        $query = DB::table('complaints')
            ->select('order_id')
            ->selectRaw('COUNT(*) as total')
            ->whereIn('order_id', $orderIds);

        if ($this->hasColumn('complaints', 'status')) {
            $query->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), self::RESOLVED_COMPLAINT_STATUSES);
        }

        return $query->groupBy('order_id')
            ->pluck('total', 'order_id')
            ->mapWithKeys(static fn ($total, $orderId): array => [(string) $orderId => (int) $total]);
    }

    private function incomeEligibilityForOrder(
        Order $order,
        array $ticketSummary,
        ?array $earningSummary,
        bool $hasRefund,
        int $activeComplaintCount
    ): array {
        if ($earningSummary && $earningSummary['is_eligible'] !== null) {
            return [
                'eligible' => (bool) $earningSummary['is_eligible'],
                'amount' => (float) ($earningSummary['amount'] ?? 0),
                'reason' => (bool) $earningSummary['is_eligible']
                    ? 'Theo bảng thu nhập chuyên viên'
                    : ((string) ($earningSummary['reason'] ?? '') ?: 'Không đủ điều kiện theo bảng thu nhập'),
            ];
        }

        $reasons = [];
        $orderStatus = strtolower(trim((string) ($order->status ?? '')));
        $paymentStatus = strtolower(trim((string) ($order->status_payment ?? '')));

        if (! in_array($orderStatus, self::SUCCESS_ORDER_STATUSES, true)) {
            $reasons[] = 'Đơn chưa hoàn thành';
        }

        if ($paymentStatus !== 'paid') {
            $reasons[] = 'Thanh toán chưa thành công';
        }

        if ($paymentStatus === 'refunded' || $hasRefund) {
            $reasons[] = 'Đơn đã hoàn tiền';
        }

        if ($activeComplaintCount > 0) {
            $reasons[] = 'Có khiếu nại/tranh chấp chưa xử lý';
        }

        if ($this->orderHasFraudFlag($order)) {
            $reasons[] = 'Đơn bị đánh dấu gian lận';
        }

        if ((int) ($ticketSummary['eligible'] ?? 0) < 1) {
            $reasons[] = 'Chưa có ticket hợp lệ';
        }

        $eligible = empty($reasons);
        $rate = (float) ($order->store->sale_commission_rate ?? 0);
        $estimatedAmount = $eligible ? (float) ($ticketSummary['ticket_value'] ?? 0) * ($rate / 100) : 0.0;

        return [
            'eligible' => $eligible,
            'amount' => $earningSummary ? (float) ($earningSummary['amount'] ?? 0) : round($estimatedAmount, 2),
            'reason' => $eligible ? 'Đủ điều kiện theo rule' : implode('; ', $reasons),
        ];
    }

    private function orderHasFraudFlag(Order $order): bool
    {
        foreach (['is_fraud', 'fraud_flag', 'is_flagged_fraud'] as $column) {
            if ($this->hasColumn('orders', $column) && (bool) ($order->{$column} ?? false)) {
                return true;
            }
        }

        foreach (['fraud_status', 'risk_status'] as $column) {
            if (! $this->hasColumn('orders', $column)) {
                continue;
            }

            $status = strtolower(trim((string) ($order->{$column} ?? '')));
            if (in_array($status, ['fraud', 'suspected_fraud', 'blocked', 'rejected'], true)) {
                return true;
            }
        }

        return false;
    }

    private function orderStatusLabel(string $status): array
    {
        $labels = [
            'pending' => ['label' => 'Khởi tạo', 'class' => 'secondary'],
            'pending_payment' => ['label' => 'Chờ thanh toán', 'class' => 'secondary'],
            'paid_pending_call' => ['label' => 'Chờ gọi xác nhận', 'class' => 'info'],
            'approved' => ['label' => 'Duyệt', 'class' => 'info'],
            'confirmed' => ['label' => 'Đã xác nhận', 'class' => 'info'],
            'packed' => ['label' => 'Đóng gói', 'class' => 'primary'],
            'preparing' => ['label' => 'Đang chuẩn bị', 'class' => 'primary'],
            'ready' => ['label' => 'Sẵn sàng', 'class' => 'warning'],
            'shipped' => ['label' => 'Xuất kho', 'class' => 'warning'],
            'picked_up' => ['label' => 'Đã lấy hàng', 'class' => 'warning'],
            'completed' => ['label' => 'Hoàn thành', 'class' => 'success'],
            'delivered' => ['label' => 'Đã giao', 'class' => 'success'],
            'call_completed' => ['label' => 'Gọi hoàn tất', 'class' => 'success'],
            'returned' => ['label' => 'Hoàn hàng', 'class' => 'dark'],
            'cancelled' => ['label' => 'Hủy đơn', 'class' => 'danger'],
            'canceled' => ['label' => 'Hủy đơn', 'class' => 'danger'],
        ];

        return $labels[strtolower($status)] ?? ['label' => $status !== '' ? $status : 'Không xác định', 'class' => 'dark'];
    }

    private function paymentStatusLabel(string $status): array
    {
        $labels = [
            'pending' => ['label' => 'Chờ thanh toán', 'class' => 'danger'],
            'paid' => ['label' => 'Đã thanh toán', 'class' => 'success'],
            'failed' => ['label' => 'Thất bại', 'class' => 'danger'],
            'refunded' => ['label' => 'Hoàn tiền', 'class' => 'primary'],
        ];

        return $labels[strtolower($status)] ?? ['label' => $status !== '' ? $status : 'Không xác định', 'class' => 'dark'];
    }

    private function formatTicketStatusSummary(array $summary): string
    {
        if ((int) ($summary['total'] ?? 0) < 1) {
            return '<span class="text-muted">Chưa có ticket</span>';
        }

        $parts = [];
        if (($summary['eligible'] ?? 0) > 0) {
            $parts[] = '<span class="badge bg-success">Hợp lệ: ' . (int) $summary['eligible'] . '</span>';
        }
        if (($summary['held'] ?? 0) > 0) {
            $parts[] = '<span class="badge bg-warning text-dark">Treo: ' . (int) $summary['held'] . '</span>';
        }
        if (($summary['cancelled'] ?? 0) > 0) {
            $parts[] = '<span class="badge bg-danger">Hủy: ' . (int) $summary['cancelled'] . '</span>';
        }
        if (($summary['other'] ?? 0) > 0) {
            $parts[] = '<span class="badge bg-secondary">Khác: ' . (int) $summary['other'] . '</span>';
        }

        return '<div class="d-flex flex-wrap gap-1">' . implode('', $parts) . '</div>';
    }

    private function formatIncomeEligibility(array $eligibility): string
    {
        $badge = $eligibility['eligible']
            ? '<span class="badge bg-success">Có tính</span>'
            : '<span class="badge bg-danger">Không tính</span>';

        $amount = (float) ($eligibility['amount'] ?? 0);
        $amountHtml = $amount > 0
            ? '<div><small class="text-muted">' . number_format($amount, 0, ',', '.') . ' VND</small></div>'
            : '';

        return $badge
            . $amountHtml
            . '<div><small class="text-muted">' . e((string) ($eligibility['reason'] ?? '')) . '</small></div>';
    }

    private function formatOrderIdentifier(Order $order): string
    {
        $code = (string) ($order->order_code ?: $order->id);
        $url = route('admin.orders.show', $order->id);

        return '<a href="' . $url . '" class="order-code-link fw-bold" style="text-decoration: none; color: #1e293b;">' . e($code) . '</a>';
    }

    private function formatStoreCell(Order $order): string
    {
        if (! $order->store) {
            return '<span class="text-muted">N/A</span>';
        }

        return '<strong>' . e((string) $order->store->name) . '</strong>';
    }

    private function toLocalTime(?Carbon $dt): ?Carbon
    {
        if (! $dt) {
            return null;
        }
        return Carbon::parse($dt->toDateTimeString(), 'UTC')->timezone('Asia/Ho_Chi_Minh');
    }

    private function formatOrderTime(Order $order): string
    {
        $completedAt = $this->toLocalTime($order->delivered_at) ?: $this->toLocalTime($order->created_at);
        $createdAt = $this->toLocalTime($order->created_at);

        $html = $completedAt ? e($completedAt->format('d/m/Y H:i')) : '';

        if ($createdAt && $completedAt && $createdAt->ne($completedAt)) {
            $html .= '<div><small class="text-muted">Tạo: ' . e($createdAt->format('d/m/Y H:i')) . '</small></div>';
        }

        return $html;
    }

    private function customerNameForDisplay(Order $order, bool $masked): string
    {
        $name = trim((string) ($order->user->full_name ?? $order->user->name ?? $order->name ?? ''));

        if (! $masked || $name === '') {
            return $name;
        }

        return mb_substr($name, 0, 1) . str_repeat('*', max(2, min(8, mb_strlen($name) - 1)));
    }

    private function phoneForDisplay(string $phone, bool $masked): string
    {
        $phone = trim($phone);

        if (! $masked || mb_strlen($phone) <= 5) {
            return $phone;
        }

        return mb_substr($phone, 0, 3) . str_repeat('*', max(3, mb_strlen($phone) - 5)) . mb_substr($phone, -2);
    }

    private function formatShippingMethod(?string $method): string
    {
        if (empty($method)) {
            return '<span class="badge fs-5 bg-secondary">Không rõ</span>';
        }

        $methodLower = strtolower(trim($method));
        switch ($methodLower) {
            case 'pickup':
                return '<span class="badge fs-5 bg-info text-dark">Tự đến lấy</span>';
            case 'delivery':
                return '<span class="badge fs-5 bg-success">Giao hàng</span>';
            case 'later':
                return '<span class="badge fs-5 bg-warning text-dark">Gọi món sau</span>';
            default:
                return '<span class="badge fs-5 bg-secondary">' . e(ucfirst($method)) . '</span>';
        }
    }

    private function orderAmount(Order $order): float
    {
        foreach (['final_amount', 'total_amount', 'total'] as $column) {
            if ($order->{$column} !== null) {
                return (float) $order->{$column};
            }
        }

        return 0.0;
    }

    private function orderAmountColumn(): string
    {
        foreach (['final_amount', 'total_amount', 'total'] as $column) {
            if ($this->hasColumn('orders', $column)) {
                return $column;
            }
        }

        return 'id';
    }

    private function hasTable(string $table): bool
    {
        return $this->schemaTableCache[$table]
            ??= Cache::remember(
                'admin.orders.schema.table.' . $table,
                now()->addHour(),
                static fn (): bool => Schema::hasTable($table)
            );
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;

        return $this->schemaColumnCache[$cacheKey]
            ??= Cache::remember(
                'admin.orders.schema.column.' . $cacheKey,
                now()->addHour(),
                static fn (): bool => Schema::hasColumn($table, $column)
            );
    }

    private function datatableLegacy(Request $request)
    {
        $columns = [
            1 => 'id',
            2 => 'name',
            3 => 'phone',
            4 => 'total',
            5 => 'created_at',
            6 => 'status',
            7 => 'status_payment',
        ];

        $search = (string) $request->input('search.value', '');
        $status = (string) $request->input('status', 'all');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $orderField = $columns[$request->input('order.0.column', 1)] ?? 'id';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $query = Order::with(['items.product', 'referrer', 'store']);
        AdminCmsAccess::applyOrderScope($query);
        $recordsTotal = $query->count();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($fromDate || $toDate) {
            $from = $fromDate
                ? Carbon::parse($fromDate)->startOfDay()
                : Carbon::minValue();

            $to = $toDate
                ? Carbon::parse($toDate)->endOfDay()
                : Carbon::now()->endOfDay();

            $query->whereBetween('created_at', [$from, $to]);
        }

        $recordsFiltered = $query->count();

        $orders = $query
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $data = $orders->map(function (Order $order) {
            $statusLabels = [
                'pending' => ['label' => 'Khởi tạo', 'class' => 'secondary'],
                'approved' => ['label' => 'Duyệt', 'class' => 'info'],
                'packed' => ['label' => 'Đóng gói', 'class' => 'primary'],
                'shipped' => ['label' => 'Xuất kho', 'class' => 'warning'],
                'completed' => ['label' => 'Hoàn thành', 'class' => 'success'],
                'returned' => ['label' => 'Hoàn hàng', 'class' => 'dark'],
                'cancelled' => ['label' => 'Hủy đơn', 'class' => 'danger'],
            ];

            $status = $statusLabels[$order->status] ?? ['label' => 'Không xác định', 'class' => 'dark'];

            $statusPaymentLabels = [
                'pending' => ['label' => 'Chờ thanh toán', 'class' => 'danger'],
                'paid' => ['label' => 'Đã thanh toán', 'class' => 'success'],
                'failed' => ['label' => 'Thất bại', 'class' => 'danger'],
                'refunded' => ['label' => 'Hoàn tiền', 'class' => 'primary'],
            ];

            $statusPayment = $statusPaymentLabels[$order->status_payment] ?? [
                'label' => 'Không xác định',
                'class' => 'dark',
            ];

            $itemsHtml = '';
            foreach ($order->items as $item) {
                if ($item->product) {
                    $itemsHtml .= '<div>- ' . e($item->product_name) . ' (x' . e($item->quantity) . ')</div>';
                } else {
                    $itemsHtml .= '<div>- Sản phẩm không tồn tại (x' . e($item->quantity) . ')</div>';
                }
            }

            $referrerHtml = $order->referrer
                ? '<span class="badge fs-5 bg-secondary">[#' . e($order->referrer->id) . '] - ' . e($order->referrer->full_name) . '</span>'
                : '<span class="badge fs-5 bg-danger">Không có</span>';

            $csrf = csrf_token();
            $updateStatusRoute = route('admin.orders.updateStatus', $order->id);

            return [
                'checkbox' => '<input type="checkbox" class="order-row-checkbox" value="' . $order->id . '">',
                'id' => $order->id,
                'name' => e($order->name),
                'phone' => e($order->phone),
                'total' => number_format($order->total, 0, ',', '.') . ' VND',
                'created_at' => $order->created_at ? e(Carbon::parse($order->created_at->toDateTimeString(), 'UTC')->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i')) : '',
                'status' => '<span class="badge fs-5 bg-' . $status['class'] . '">' . $status['label'] . '</span>',
                'status_payment' => '<span class="badge fs-5 bg-' . $statusPayment['class'] . '">' . $statusPayment['label'] . '</span>',
                'items' => $itemsHtml,
                'referrer' => $referrerHtml,
                'action' => '<div class="d-flex align-items-center gap-1">'
                    . '<a href="' . route('admin.orders.show', $order->id) . '" class="btn btn-warning btn-sm">'
                        . '<i class="fas fa-edit"></i>'
                    . '</a>'
                    . '<div class="dropdown">'
                        . '<button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
                            . '<i class="fas fa-ellipsis-h"></i>'
                        . '</button>'
                        . '<ul class="dropdown-menu">'
                            . '<li>'
                                . '<a href="#" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#updateStatusModal" data-id="' . $order->id . '" data-status="' . e($order->status) . '">Cập nhật trạng thái</a>'
                            . '</li>'
                            . '<li>'
                                . '<a href="#" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#updateStatusPaymentModal" data-id="' . $order->id . '" data-statusPayment="' . e($order->status_payment) . '">Trạng thái thanh toán</a>'
                            . '</li>'
                            . '<li>'
                                . '<form action="' . $updateStatusRoute . '" method="POST">'
                                    . '<input type="hidden" name="_token" value="' . $csrf . '">'
                                    . '<input type="hidden" name="_method" value="PUT">'
                                    . '<input type="hidden" name="status" value="cancelled">'
                                    . '<button type="submit" class="dropdown-item text-danger" onclick="return confirm(\'Bạn chắc chắn muốn hủy đơn hàng này?\')">Hủy đơn</button>'
                                . '</form>'
                            . '</li>'
                        . '</ul>'
                    . '</div>'
                    . '<a href="' . route('admin.orders.invoice', $order->id) . '" target="_blank" class="btn btn-secondary btn-sm">'
                        . '<i class="fas fa-print"></i>'
                    . '</a>'
                . '</div>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }


    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOrderRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        $this->ensureCanViewOrder($order);

        $order->load(['items.product', 'store', 'user', 'referrer']);

        // Convert timestamps to Vietnam timezone for display
        foreach (['created_at', 'updated_at', 'confirmed_at', 'preparing_at', 'ready_at', 'delivered_at', 'returned_at', 'cancelled_at', 'timeout_at'] as $field) {
            if ($order->$field) {
                $order->$field = Carbon::parse($order->$field->toDateTimeString(), 'UTC')->timezone('Asia/Ho_Chi_Minh');
            }
        }

        return view('admin.orders.edit', compact('order'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        $this->ensureCanMutateOrders();

        if ($request->has('delivery_note')) {
            $order->delivery_note = $request->input('delivery_note');
            $order->save();
            return back()->with('success', 'Cập nhật ghi chú thành công.');
        }

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        //
    }

    public function destroyMultiple(Request $request)
    {
        $this->ensureCanMutateOrders();

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string|distinct|exists:orders,id',
        ]);

        $deleted = Order::whereIn('id', $request->input('ids'))->delete();

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Đã xóa {$deleted} đơn hàng.",
            ]);
        }

        return redirect()->back()->with('success', "Đã xóa {$deleted} đơn hàng.");
    }

    public function updateStatusOld(Request $request, Order $order)
    {
        DB::beginTransaction();
        try {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $statusFlow = [
                'pending' => 'approved',
                'approved' => 'packed',
                'packed' => 'shipped',
                'shipped' => 'completed',
                'completed' => 'completed', // đã hoàn thành thì không nâng cấp nữa
                'cancelled' => 'cancelled', // hủy rồi thì giữ nguyên
            ];
            $status = $order->status;

            if ($order->status == 'completed' || $order->status == 'cancelled') {
                DB::rollBack();
                return redirect()->back()->with('success', 'Không thể cập nhật trạng thái!');
            }

            if ($request->status != $order->status) {
                $status = $request->status;
            }
            // return $status;
            if ($request->status === 'cancelled') {

                $this->restockAndCancelOrderItems($order);
                if ($order->payment_method == 'VNPAY' && $order->status_payment == 'paid') {
                    $user = User::find($order->user_id);
                    if ($user) {
                        $user->balance += $order->total;
                        $user->save();
                        $order->status_payment = 'refunded';
                        $order->save();
                    }
                }
            }
            $currentStatus = $status;
            $nextStatus = $statusFlow[$currentStatus] ?? $currentStatus;

            if ($status === 'completed' && strtolower((string) ($order->status_payment ?? 'pending')) !== 'paid') {
                app(ApiClientController::class)->settleOrderAsPaid(
                    $order,
                    (string) ($order->payment_method ?? 'BANK_TRANSFER')
                );
                $order->refresh();
            }

            $order->status = $nextStatus;
            $order->save();

            app(\App\Services\AffiliateCommissionService::class)->syncOrderLifecycle($order->fresh(), $status === 'cancelled' ? 'Đơn hàng bị hủy.' : null);

            if ($status === 'completed' && $order->referrer_id) {
                $this->settleOrderPendingCommissions($order);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Cập nhật trạng thái thành công!');
        } catch (\Throwable $th) {
            DB::rollBack();
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        $this->ensureCanMutateOrders();

        DB::beginTransaction();
        try {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $statusFlow = [
                'pending' => 'approved',
                'approved' => 'packed',
                'packed' => 'shipped',
                'shipped' => 'completed',
                'completed' => 'completed', // đã hoàn thành thì không nâng cấp nữa
                'cancelled' => 'cancelled', // hủy rồi thì giữ nguyên
            ];
            $status = $order->status;

            if ($order->status == 'completed' || $order->status == 'cancelled') {
                DB::rollBack();
                return redirect()->back()->with('success', 'Không thể cập nhật trạng thái!');
            }

            if ($request->status != $order->status) {
                $status = $request->status;
            }
            // return $status;
            if ($request->status === 'cancelled') {

                $this->restockAndCancelOrderItems($order);
                if ($order->payment_method == 'VNPAY' && $order->status_payment == 'paid') {
                    $user = User::find($order->user_id);
                    if ($user) {
                        $user->balance += $order->total;
                        $user->save();
                        $order->status_payment = 'refunded';
                        $order->save();
                    }
                }
            }
            $currentStatus = $status;
            $nextStatus = $statusFlow[$currentStatus] ?? $currentStatus;

            if ($status === 'completed' && strtolower((string) ($order->status_payment ?? 'pending')) !== 'paid') {
                app(ApiClientController::class)->settleOrderAsPaid(
                    $order,
                    (string) ($order->payment_method ?? 'BANK_TRANSFER')
                );
                $order->refresh();
            }

            $order->status = $nextStatus;
            $order->save();

            app(\App\Services\AffiliateCommissionService::class)->syncOrderLifecycle($order->fresh(), $status === 'cancelled' ? 'Đơn hàng bị hủy.' : null);

            if ($status === 'completed') {
                $this->settleOrderPendingCommissions($order);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Cập nhật trạng thái thành công!');
        } catch (\Throwable $th) {
            DB::rollBack();
        }
    }

    public function updateStatusPayment(Request $request, Order $order)
    {
        $this->ensureCanMutateOrders();

        try {
            $statusFlow = [
                'pending' => 'paid',
                'paid' => 'paid', // đã hoàn thành thì không nâng cấp nữa
                'refunded' => 'refunded', // hủy rồi thì giữ nguyên
                'failed' => 'failed', // hủy rồi thì giữ nguyên
            ];
            $statusPayment = $order->status_payment;
            if ($statusPayment == 'paid' || $statusPayment == 'refunded' || $statusPayment == 'failed') {
                return redirect()->back()->with('success', 'Không thể cập nhật trạng thái thanh toán!');
            }

            if ($request->status_payment != $order->status_payment) {
                $statusPayment = $request->status_payment;
            }

            $currentStatus = $statusPayment;
            $nextStatus = $statusFlow[$currentStatus] ?? $currentStatus;

            if ($nextStatus === 'paid') {
                app(ApiClientController::class)->settleOrderAsPaid(
                    $order,
                    (string) ($order->payment_method ?? 'BANK_TRANSFER')
                );
            } else {
                $order->status_payment = $nextStatus;
                $order->save();
            }

            return redirect()->back()->with('success', 'Cập nhật trạng thái thành công!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', 'Cập nhật trạng thái thanh toán thất bại!');
        }
    }

    private function settleOrderPendingCommissions(Order $order): void
    {
        $commissionService = app(AffiliateCommissionService::class);
        $commissionOrder = $order->fresh(['items.product', 'items.variant']) ?? $order->load(['items.product', 'items.variant']);
        $commissionService->syncOrderCommissions($commissionOrder);
        $commissionService->syncOrderLifecycle($commissionOrder->fresh(['items.product', 'items.variant']) ?? $commissionOrder);
        $commissionService->settleLegacyPendingCommissions($commissionOrder);
    }

    /**
     * Restocks products and cancels pending commissions for a cancelled order.
     * Batches product and commission queries instead of querying per order item
     * (fixes N+1).
     */
    private function restockAndCancelOrderItems(Order $order): void
    {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            return;
        }

        app(OrderStockRestorationService::class)->restoreForCancellation($order, (string) $order->status);
        $commissionOrder = $order->fresh(['items.product', 'items.variant']) ?? $order->load(['items.product', 'items.variant']);
        app(AffiliateCommissionService::class)->reverseLegacyPaidCommissions($commissionOrder, 'Đơn hàng bị hủy/hoàn.');
        app(AffiliateCommissionService::class)->syncOrderLifecycle($commissionOrder->fresh(['items.product', 'items.variant']) ?? $commissionOrder, 'Đơn hàng bị hủy/hoàn.');
    }

    private function ensureCanViewOrder(Order $order): void
    {
        if (! AdminCmsAccess::isSale()) {
            return;
        }

        $scopedOrder = Order::query()->whereKey($order->getKey());
        AdminCmsAccess::applyOrderScope($scopedOrder);

        abort_unless(
            $scopedOrder->exists(),
            403,
            'Ban khong co quyen xem don hang nay.'
        );
    }

    private function ensureCanMutateOrders(): void
    {
        abort_if(AdminCmsAccess::isSale(), 403, 'Chuyen vien khong co quyen sua don hang.');
    }

    public function invoice(Order $order)
    {
        $this->ensureCanViewOrder($order);

        $order->load('items.product'); // Load các sản phẩm trong đơn
        return view('admin.orders.invoice', compact('order'));
    }

    // OrderController.php
    public function history()
    {
        $orders = Order::with('items.product')
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get();

        return view('ordersHistory', compact('orders'));
    }

    public function historyAffilate()
    {
        $orders = Order::with('items.product')
            ->where('referrer_id', Auth::id())
            ->orderByDesc('created_at')
            ->get();

        return view('ordersHistoryAffilate', compact('orders'));
    }

    public function cancel(Order $order)
    {
        // if (in_array($order->status, ['completed', 'cancelled'])) {
        //     return back()->with('error', 'Không thể hủy đơn hàng đã hoàn thành hoặc đã bị hủy.');
        // }

        // $order->status = 'cancelled';
        // $order->save();

        // return back()->with('success', 'Đơn hàng đã được hủy thành công.');
        // Chỉ cho hủy khi status chưa hoàn thành và chưa hủy
        DB::beginTransaction();
        try {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['completed', 'cancelled', 'canceled'], true)) {
                DB::rollBack();
                return back()->with('error', 'Không thể hủy đơn hàng đã hoàn thành hoặc đã bị hủy.');
            }

            // 1. Trả lại stock + huỷ hoa hồng (batched)
            $this->restockAndCancelOrderItems($order);

            // 2. Cập nhật trạng thái đơn
            $order->status = 'cancelled';
            if ($order->payment_method == 'VNPAY' && $order->status_payment == 'paid') {
                $user = User::find($order->user_id);
                if ($user) {
                    $user->balance += $order->total;
                    $user->save();
                    $order->status_payment = 'refunded';
                    $order->save();
                }
            }
            $order->save();

            app(\App\Services\AffiliateCommissionService::class)->syncOrderLifecycle($order->fresh(), 'Đơn hàng bị hủy.');

            DB::commit();
            return back()->with('success', 'Đơn hàng đã được hủy và trả lại tồn kho thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            // \Log::error('Cancel order failed: ' . $e->getMessage());
            return back()->with('error', 'Hủy đơn không thành công, vui lòng thử lại.');
        }
    }
}
