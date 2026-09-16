<?php

namespace App\Services\StoreAdmin;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreAdminPageOverviewService
{
    public function dashboardOverview(Store $store, Request $request): array
    {
        $from = (string) $request->query('from', now()->subDays(6)->toDateString());
        $to = (string) $request->query('to', now()->toDateString());
        $date = (string) $request->query('date', $to);

        return $this->collectSlices([
            'summary' => fn () => $this->reportsSummary($store, $from, $to),
            'orderCounts' => fn () => $this->orderCounts($store),
            'recentOrders' => fn () => $this->ordersPage($store, $request, 20),
            'notifications' => fn () => $this->notificationsPage($store, 20),
            'complaints' => fn () => $this->complaintsPage($store, 20),
            'menuItems' => fn () => $this->menuItemsPage($store, 100),
            'revenueSeries' => fn () => $this->revenueSeries($store, $from, $to),
            'reviewsOverview' => fn () => $this->reviewStatsSummary($store),
            'recentReviews' => fn () => $this->recentReviews($store, 5),
            'financeToday' => fn () => $this->financeToday($store, $date),
            'flashSales' => fn () => $this->flashSalesPage($store, 50),
        ]);
    }

    public function ordersOverview(Store $store, Request $request): array
    {
        return $this->collectSlices([
            'orders' => fn () => $this->ordersPage($store, $request, $this->pageSize($request, 20)),
            'counts' => fn () => $this->orderCounts($store, $request),
            'rejectionReasons' => fn () => $this->rejectionReasons(),
            'creditSummary' => fn () => $this->storeCreditSummary($store),
        ]);
    }

    public function menuOverview(Store $store, Request $request): array
    {
        return $this->collectSlices([
            'categoryTree' => fn () => $this->categoryTree($store),
            'menuItems' => fn () => $this->menuItemsPage($store, $this->pageSize($request, 100)),
            'productRequests' => fn () => $this->productRequests($store, 20),
            'approvalStatuses' => fn () => $this->approvalStatuses($store),
            'reviewStats' => fn () => $this->reviewStatsSummary($store),
        ]);
    }

    public function reviewsOverview(Store $store, Request $request): array
    {
        return $this->collectSlices([
            'reviewStats' => fn () => $this->reviewStatsSummary($store),
            'recentReviews' => fn () => $this->recentReviews($store, $this->pageSize($request, 20)),
            'productRows' => fn () => $this->reviewProductRows($store),
        ]);
    }

    public function slaOverview(Store $store, Request $request): array
    {
        return $this->collectSlices([
            'slaConfig' => fn () => $this->slaConfig($store),
            'warningCount' => fn () => $this->slaWarningCount($store),
            'timeouts' => fn () => $this->timeoutOrders($store, $this->pageSize($request, 20)),
        ]);
    }

    public function profileOverview(Store $store): array
    {
        return $this->collectSlices([
            'profile' => fn () => $this->profileLight($store),
            'licenses' => fn () => $this->licenseSummary($store),
            'storeStats' => fn () => $this->storeStats($store),
        ]);
    }

    private function collectSlices(array $slices): array
    {
        $data = [];
        $partialErrors = [];

        foreach ($slices as $key => $resolver) {
            try {
                $data[$key] = $resolver();
            } catch (\Throwable $error) {
                $data[$key] = null;
                $partialErrors[] = [
                    'slice' => $key,
                    'code' => 'SLICE_FAILED',
                    'message' => $error->getMessage(),
                ];
            }
        }

        return [
            'data' => $data,
            'partial_errors' => $partialErrors,
        ];
    }

    private function reportsSummary(Store $store, string $from, string $to): array
    {
        $orders = $this->ordersBaseQuery($store);
        if (!$orders) {
            return [
                'revenue' => ['total' => 0, 'orders' => 0],
                'ticket_redemptions' => ['total' => 0],
                'range' => ['from' => $from, 'to' => $to],
            ];
        }

        if (Schema::hasColumn('orders', 'status')) {
            $orders->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $dateColumn = $this->firstExistingColumn('orders', ['delivered_at', 'created_at', 'updated_at']);
        if ($dateColumn) {
            $orders->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to);
        }

        $amountColumn = $this->firstExistingColumn('orders', ['final_amount', 'total_amount', 'total', 'amount']);
        $ticketColumn = $this->firstExistingColumn('orders', ['total_tickets_used', 'tickets_used', 'ticket_count']);

        return [
            'revenue' => [
                'total' => $amountColumn ? (float) (clone $orders)->sum($amountColumn) : 0,
                'orders' => (int) (clone $orders)->count(),
                'orders_count' => (int) (clone $orders)->count(),
                'currency' => 'VND',
            ],
            'ticket_redemptions' => [
                'total' => $ticketColumn ? (int) (clone $orders)->sum($ticketColumn) : 0,
            ],
            'ticketRedemptions' => [
                'total' => $ticketColumn ? (int) (clone $orders)->sum($ticketColumn) : 0,
            ],
            'ticket_refunds' => [
                'total' => 0,
                'tickets' => 0,
            ],
            'approval' => [
                'current_status' => (string) ($store->status ?? 'pending'),
                'last_changed_at' => optional($store->updated_at ?? $store->created_at)?->toIso8601String(),
                'latest_reason' => (string) ($store->status ?? '') === 'rejected'
                    ? ($store->rejection_reason ?: null)
                    : null,
            ],
            'range' => ['from' => $from, 'to' => $to],
            'from' => $from,
            'to' => $to,
            'meta' => ['from' => $from, 'to' => $to],
        ];
    }

    private function orderCounts(Store $store, ?Request $request = null): array
    {
        $query = $this->ordersBaseQuery($store);
        $counts = [
            'all' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'preparing' => 0,
            'ready' => 0,
            'picked_up' => 0,
            'delivered' => 0,
            'returned' => 0,
            'canceled' => 0,
            'cancelled' => 0,
            'timeout' => 0,
            'disputed' => 0,
        ];

        if (!$query) {
            return [
                'status_counts' => [
                    'pending' => 0,
                    'confirmed' => 0,
                    'preparing' => 0,
                    'ready' => 0,
                    'picked_up' => 0,
                    'delivered' => 0,
                    'canceled' => 0,
                    'timeout' => 0,
                    'disputed' => 0,
                ],
                'total' => 0,
                'sla_warning' => 0,
                'raw_counts' => $counts,
            ];
        }

        $this->applyOrderOverviewFilters($query, $request, false);
        $counts['all'] = (int) (clone $query)->count();

        if (!Schema::hasColumn('orders', 'status')) {
            return [
                'status_counts' => [
                    'pending' => 0,
                    'confirmed' => 0,
                    'preparing' => 0,
                    'ready' => 0,
                    'picked_up' => 0,
                    'delivered' => 0,
                    'canceled' => 0,
                    'timeout' => 0,
                    'disputed' => 0,
                ],
                'total' => $counts['all'],
                'sla_warning' => 0,
                'raw_counts' => $counts,
            ];
        }

        $rows = (clone $query)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = strtolower((string) ($row->status ?? ''));
            if ($status === 'pending_payment') {
                continue;
            }
            if ($status === 'cancelled') {
                $status = 'canceled';
            }
            $counts[$status] = (int) $row->total;
        }

        return [
            'status_counts' => [
                'pending' => $counts['pending'],
                'confirmed' => $counts['confirmed'],
                'preparing' => $counts['preparing'],
                'ready' => $counts['ready'],
                'picked_up' => $counts['picked_up'],
                'delivered' => $counts['delivered'],
                'canceled' => $counts['canceled'],
                'timeout' => $counts['timeout'],
                'disputed' => $counts['disputed'],
            ],
            'total' => (int) (clone $query)->whereRaw('LOWER(COALESCE(status, "")) != ?', ['pending_payment'])->count(),
            'sla_warning' => Schema::hasColumn('orders', 'sla_warning_sent')
                ? (int) (clone $query)->where('sla_warning_sent', true)->count()
                : 0,
            'raw_counts' => $counts,
        ];
    }

    private function ordersPage(Store $store, Request $request, int $defaultPageSize): array
    {
        $query = $this->ordersBaseQuery($store);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request, $defaultPageSize);

        if (!$query) {
            return $this->page([], 0, $page, $pageSize);
        }

        $this->applyOrderOverviewFilters($query, $request, true);

        $total = (int) (clone $query)->count();
        $sortColumn = $this->firstExistingColumn('orders', ['created_at', 'updated_at', 'id']) ?? 'id';
        $rows = $query
            ->orderByDesc($sortColumn)
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn ($row) => $this->serializeOrder($row))
            ->values()
            ->all();

        return $this->page($rows, $total, $page, $pageSize);
    }

    private function notificationsPage(Store $store, int $pageSize): array
    {
        if (!Schema::hasTable('store_admin_notifications')) {
            return $this->page([], 0, 1, $pageSize, ['unread_count' => 0]);
        }

        $query = DB::table('store_admin_notifications')->where('store_id', $store->id);
        $total = (int) (clone $query)->count();
        $unread = Schema::hasColumn('store_admin_notifications', 'is_read')
            ? (int) (clone $query)->where(function ($builder) {
                $builder->where('is_read', false)->orWhereNull('is_read');
            })->count()
            : 0;
        $sortColumn = $this->firstExistingColumn('store_admin_notifications', ['created_at', 'updated_at', 'id']) ?? 'id';

        $items = $query
            ->orderByDesc($sortColumn)
            ->limit($pageSize)
            ->get()
            ->map(fn ($row) => $this->serializeLooseRow($row))
            ->values()
            ->all();

        return $this->page($items, $total, 1, $pageSize, ['unread_count' => $unread]);
    }

    private function complaintsPage(Store $store, int $pageSize): array
    {
        if (!Schema::hasTable('complaints')) {
            return $this->page([], 0, 1, $pageSize);
        }

        $query = DB::table('complaints as c');
        if (Schema::hasColumn('complaints', 'store_id')) {
            $query->where('c.store_id', $store->id);
        } elseif (Schema::hasColumn('complaints', 'order_id') && Schema::hasTable('orders')) {
            $query->join('orders as o', 'o.id', '=', 'c.order_id')->where('o.store_id', $store->id);
        }

        $total = (int) (clone $query)->count();
        $sortColumn = Schema::hasColumn('complaints', 'updated_at') ? 'c.updated_at' : (Schema::hasColumn('complaints', 'created_at') ? 'c.created_at' : 'c.id');
        $items = $query
            ->orderByDesc($sortColumn)
            ->limit($pageSize)
            ->select('c.*')
            ->get()
            ->map(fn ($row) => $this->serializeLooseRow($row))
            ->values()
            ->all();

        return $this->page($items, $total, 1, $pageSize);
    }

    private function menuItemsPage(Store $store, int $pageSize): array
    {
        if (!Schema::hasTable('store_menu')) {
            return $this->page([], 0, 1, $pageSize);
        }

        $query = DB::table('store_menu')->where('store_id', $store->id);
        $total = (int) (clone $query)->count();
        $sortColumn = $this->firstExistingColumn('store_menu', ['updated_at', 'created_at', 'item_name', 'id']) ?? 'id';
        $direction = $sortColumn === 'item_name' ? 'asc' : 'desc';
        $items = $query
            ->orderBy($sortColumn, $direction)
            ->limit($pageSize)
            ->get()
            ->map(fn ($row) => $this->serializeMenuItem($row))
            ->values()
            ->all();

        return $this->page($items, $total, 1, $pageSize);
    }

    private function revenueSeries(Store $store, string $from, string $to): array
    {
        $query = $this->ordersBaseQuery($store);
        $amountColumn = $this->firstExistingColumn('orders', ['final_amount', 'total_amount', 'total', 'amount']);
        $dateColumn = $this->firstExistingColumn('orders', ['created_at', 'updated_at']);

        if (!$query || !$amountColumn || !$dateColumn) {
            return [
                'period' => 'day',
                'from' => $from,
                'to' => $to,
                'currency' => 'VND',
                'series' => [],
                'points' => [],
                'totals' => [
                    'revenue' => 0,
                    'orders_count' => 0,
                    'tickets_used' => 0,
                    'ticket_value_vnd' => 0,
                ],
            ];
        }

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $rows = $query
            ->whereDate($dateColumn, '>=', $from)
            ->whereDate($dateColumn, '<=', $to)
            ->selectRaw('DATE(' . $dateColumn . ') as bucket')
            ->selectRaw('COALESCE(SUM(' . $amountColumn . '), 0) as revenue_total')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw(
                Schema::hasColumn('orders', 'total_tickets_used')
                    ? 'COALESCE(SUM(total_tickets_used), 0) as tickets_used'
                    : '0 as tickets_used'
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();
        $series = $rows->map(function ($row) {
            $ticketsUsed = (int) ($row->tickets_used ?? 0);

            return [
                'bucket' => (string) $row->bucket,
                'date' => (string) $row->bucket,
                'revenue' => (float) $row->revenue_total,
                'orders_count' => (int) $row->orders_count,
                'orders' => (int) $row->orders_count,
                'tickets_used' => $ticketsUsed,
                'ticket_value_vnd' => $ticketsUsed * 5000,
            ];
        })->values();
        $totalRevenue = (float) $series->sum('revenue');
        $totalOrders = (int) $series->sum('orders_count');
        $totalTickets = (int) $series->sum('tickets_used');

        return [
            'period' => 'day',
            'from' => $from,
            'to' => $to,
            'currency' => 'VND',
            'series' => $series->all(),
            'points' => $series->all(),
            'totals' => [
                'revenue' => $totalRevenue,
                'orders_count' => $totalOrders,
                'tickets_used' => $totalTickets,
                'ticket_value_vnd' => $totalTickets * 5000,
            ],
        ];
    }

    private function reviewStatsSummary(Store $store): array
    {
        if (!Schema::hasTable('store_reviews')) {
            return ['totalComments' => 0, 'unrepliedCount' => 0, 'averageRating' => 0];
        }

        $query = DB::table('store_reviews')->where('store_id', $store->id);
        if (Schema::hasColumn('store_reviews', 'is_hidden')) {
            $query->where('is_hidden', false);
        }

        $replyColumn = $this->firstExistingColumn('store_reviews', ['admin_reply', 'reply', 'admin_response']);

        return [
            'totalComments' => (int) (clone $query)->count(),
            'total_comments' => (int) (clone $query)->count(),
            'unrepliedCount' => $replyColumn ? (int) (clone $query)->where(function ($builder) use ($replyColumn) {
                $builder->whereNull($replyColumn)->orWhere($replyColumn, '');
            })->count() : 0,
            'unreplied_count' => $replyColumn ? (int) (clone $query)->where(function ($builder) use ($replyColumn) {
                $builder->whereNull($replyColumn)->orWhere($replyColumn, '');
            })->count() : 0,
            'averageRating' => Schema::hasColumn('store_reviews', 'rating') ? round((float) (clone $query)->avg('rating'), 1) : 0,
            'average_rating' => Schema::hasColumn('store_reviews', 'rating') ? round((float) (clone $query)->avg('rating'), 1) : 0,
        ];
    }

    private function recentReviews(Store $store, int $pageSize): array
    {
        if (!Schema::hasTable('store_reviews')) {
            return $this->page([], 0, 1, $pageSize);
        }

        $query = DB::table('store_reviews')->where('store_id', $store->id);
        if (Schema::hasColumn('store_reviews', 'is_hidden')) {
            $query->where('is_hidden', false);
        }

        $total = (int) (clone $query)->count();
        $sortColumn = $this->firstExistingColumn('store_reviews', ['created_at', 'updated_at', 'id']) ?? 'id';
        $items = $query
            ->orderByDesc($sortColumn)
            ->limit($pageSize)
            ->get()
            ->map(fn ($row) => $this->serializeLooseRow($row))
            ->values()
            ->all();

        return $this->page($items, $total, 1, $pageSize);
    }

    private function financeToday(Store $store, string $date): array
    {
        $grossRevenue = $this->sumOrdersAmount($store, $date);

        return [
            'period' => 'day',
            'date' => $date,
            'from' => $date,
            'to' => $date,
            'currency' => 'VND',
            'credit_summary' => $this->storeCreditSummary($store),
            'summary' => [
                'period' => 'day',
                'date' => $date,
                'from' => $date,
                'to' => $date,
                'currency' => 'VND',
                'revenue' => [
                    'total' => $grossRevenue,
                    'cash' => $this->sumOrdersAmount($store, $date, ['cash']),
                    'transfer' => $this->sumOrdersAmount($store, $date, ['transfer', 'bank_transfer', 'banking', 'qr']),
                    'cod' => $this->sumOrdersAmount($store, $date, ['cod']),
                    'other' => 0,
                ],
                'deductions' => [
                    'affiliate_commission' => 0,
                    'ticket_reward' => 0,
                ],
                'net_amount' => $grossRevenue,
                'reconciliation' => null,
                'last_reconciliation' => null,
            ],
            'status' => [
                'step' => 1,
                'has_record' => false,
            ],
        ];
    }

    private function flashSalesPage(Store $store, int $pageSize): array
    {
        if (!Schema::hasTable('flash_sales')) {
            return $this->page([], 0, 1, $pageSize);
        }

        $query = DB::table('flash_sales');
        if (Schema::hasColumn('flash_sales', 'store_id')) {
            $query->where('store_id', $store->id);
        }
        $total = (int) (clone $query)->count();
        $sortColumn = $this->firstExistingColumn('flash_sales', ['starts_at', 'start_at', 'created_at', 'id']) ?? 'id';
        $items = $query
            ->orderByDesc($sortColumn)
            ->limit($pageSize)
            ->get()
            ->map(fn ($row) => $this->serializeLooseRow($row))
            ->values()
            ->all();

        return $this->page($items, $total, 1, $pageSize);
    }

    private function categoryTree(Store $store): array
    {
        if (Schema::hasTable('product_categories')) {
            $query = DB::table('product_categories');
            if (Schema::hasColumn('product_categories', 'store_id')) {
                $query->where(function ($builder) use ($store) {
                    $builder->whereNull('store_id')->orWhere('store_id', $store->id);
                });
            }

            return $query->orderBy($this->firstExistingColumn('product_categories', ['sort_order', 'name', 'id']) ?? 'id')
                ->get()
                ->map(fn ($row) => $this->serializeLooseRow($row))
                ->values()
                ->all();
        }

        if (!Schema::hasTable('store_menu') || !Schema::hasColumn('store_menu', 'category')) {
            return [];
        }

        return DB::table('store_menu')
            ->where('store_id', $store->id)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->map(fn ($name) => ['id' => (string) $name, 'name' => (string) $name])
            ->values()
            ->all();
    }

    private function productRequests(Store $store, int $pageSize): array
    {
        if (!Schema::hasTable('product_drafts')) {
            return $this->page([], 0, 1, $pageSize);
        }

        $query = DB::table('product_drafts')->where('store_id', $store->id);
        $total = (int) (clone $query)->count();
        $sortColumn = $this->firstExistingColumn('product_drafts', ['updated_at', 'created_at', 'id']) ?? 'id';

        return $this->page(
            $query->orderByDesc($sortColumn)->limit($pageSize)->get()->map(fn ($row) => $this->serializeLooseRow($row))->values()->all(),
            $total,
            1,
            $pageSize
        );
    }

    private function approvalStatuses(Store $store): array
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'store_id')) {
            return [];
        }

        $statusColumn = $this->firstExistingColumn('products', ['approval_status', 'status']);
        if (!$statusColumn) {
            return [];
        }

        return DB::table('products')
            ->where('store_id', $store->id)
            ->select($statusColumn . ' as status', DB::raw('COUNT(*) as total'))
            ->groupBy($statusColumn)
            ->get()
            ->map(fn ($row) => ['status' => (string) $row->status, 'total' => (int) $row->total])
            ->values()
            ->all();
    }

    private function reviewProductRows(Store $store): array
    {
        if (!Schema::hasTable('store_menu')) {
            return [];
        }

        return DB::table('store_menu')
            ->where('store_id', $store->id)
            ->limit(100)
            ->get()
            ->map(fn ($row) => $this->serializeMenuItem($row))
            ->values()
            ->all();
    }

    private function slaConfig(Store $store): array
    {
        $custom = Schema::hasColumn('stores', 'custom_sla_minutes') && $store->custom_sla_minutes !== null
            ? (int) $store->custom_sla_minutes
            : null;
        $default = (int) config('ecoluck.order_sla_timeout_minutes', 15);
        $timeout = $custom ?? max(1, $default);

        return [
            'store_id' => (string) $store->id,
            'sla_timeout_minutes' => $timeout,
            'custom_sla_minutes' => $custom,
            'default_sla_minutes' => max(1, $default),
            'description' => 'Xac nhan don trong vong ' . $timeout . ' phut.',
            'read_only' => false,
        ];
    }

    private function slaWarningCount(Store $store): int
    {
        $query = $this->ordersBaseQuery($store);
        if (!$query || !Schema::hasColumn('orders', 'status')) {
            return 0;
        }

        return (int) $query->whereRaw('LOWER(status) = ?', ['timeout'])->count();
    }

    private function timeoutOrders(Store $store, int $pageSize): array
    {
        $query = $this->ordersBaseQuery($store);
        if (!$query || !Schema::hasColumn('orders', 'status')) {
            return $this->page([], 0, 1, $pageSize);
        }

        $query->whereRaw('LOWER(status) = ?', ['timeout']);
        $total = (int) (clone $query)->count();
        $sortColumn = $this->firstExistingColumn('orders', ['timeout_at', 'created_at', 'updated_at', 'id']) ?? 'id';

        return $this->page(
            $query->orderByDesc($sortColumn)->limit($pageSize)->get()->map(fn ($row) => $this->serializeOrder($row))->values()->all(),
            $total,
            1,
            $pageSize
        );
    }

    private function profileLight(Store $store): array
    {
        return [
            'store' => $this->serializeStore($store),
            'store_status' => (string) ($store->status ?? 'draft'),
            'profile_completion' => $this->profileCompletion($store),
        ];
    }

    private function licenseSummary(Store $store): array
    {
        if (!Schema::hasTable('store_licenses')) {
            return ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        }

        $query = DB::table('store_licenses')->where('store_id', $store->id);
        $summary = ['total' => (int) (clone $query)->count(), 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        if (!Schema::hasColumn('store_licenses', 'status')) {
            return $summary;
        }

        foreach ((clone $query)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->get() as $row) {
            $summary[strtolower((string) $row->status)] = (int) $row->total;
        }

        return $summary;
    }

    private function storeStats(Store $store): array
    {
        return [
            'orders_total' => ($query = $this->ordersBaseQuery($store)) ? (int) $query->count() : 0,
            'menu_items_total' => Schema::hasTable('store_menu') ? (int) DB::table('store_menu')->where('store_id', $store->id)->count() : 0,
            'reviews_total' => Schema::hasTable('store_reviews') ? (int) DB::table('store_reviews')->where('store_id', $store->id)->count() : 0,
        ];
    }

    private function storeCreditSummary(Store $store): array
    {
        $creditLimit = (float) ($store->credit_limit ?? 0);
        $ticketDebt = (float) ($store->ticket_debt ?? 0);
        $walletBalance = (float) ($store->wallet_balance ?? 0);

        return [
            'credit_limit' => $creditLimit,
            'ticket_debt' => $ticketDebt,
            'wallet_balance' => $walletBalance,
            'account_balance' => $walletBalance - $ticketDebt,
            'remaining_capacity' => max(0, $creditLimit - $ticketDebt + $walletBalance),
        ];
    }

    private function rejectionReasons(): array
    {
        return [
            ['code' => 'out_of_stock', 'label' => 'Hết món / hết hàng'],
            ['code' => 'store_overloaded', 'label' => 'Cửa hàng đang quá tải'],
            ['code' => 'customer_request', 'label' => 'Khách hàng yêu cầu hủy'],
            ['code' => 'other', 'label' => 'Lý do khác'],
        ];
    }

    private function applyOrderOverviewFilters(
        \Illuminate\Database\Query\Builder $query,
        ?Request $request,
        bool $includeStatusFilter
    ): void {
        if (!$request) {
            if ($includeStatusFilter && Schema::hasColumn('orders', 'status')) {
                $query->whereRaw('LOWER(COALESCE(status, "")) != ?', ['pending_payment']);
            }

            return;
        }

        if ($includeStatusFilter && Schema::hasColumn('orders', 'status')) {
            $status = trim((string) $request->query('status', ''));
            if ($status !== '' && strtolower($status) !== 'all') {
                $statuses = array_filter(array_map(
                    fn ($item) => strtolower(trim($item)),
                    explode(',', $status)
                ));

                if (count($statuses) > 1) {
                    $query->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $statuses);
                } elseif (count($statuses) === 1) {
                    $query->whereRaw('LOWER(COALESCE(status, "")) = ?', [array_values($statuses)[0]]);
                }
            } else {
                $query->whereRaw('LOWER(COALESCE(status, "")) != ?', ['pending_payment']);
            }
        }

        $paymentStatus = trim((string) $request->query('payment_status', ''));
        if ($includeStatusFilter && $paymentStatus !== '' && Schema::hasColumn('orders', 'payment_status')) {
            $query->where('payment_status', $paymentStatus);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '' && Schema::hasColumn('orders', 'order_code')) {
            $query->where('order_code', 'like', '%' . $q . '%');
        }

        $dateColumn = $this->firstExistingColumn('orders', ['created_at', 'updated_at']);
        if (!$dateColumn) {
            return;
        }

        $from = trim((string) $request->query('from', ''));
        if ($from !== '') {
            $query->whereDate($dateColumn, '>=', $from);
        }

        $to = trim((string) $request->query('to', ''));
        if ($to !== '') {
            $query->whereDate($dateColumn, '<=', $to);
        }
    }

    private function ordersBaseQuery(Store $store): ?\Illuminate\Database\Query\Builder
    {
        if (!Schema::hasTable('orders')) {
            return null;
        }

        $query = DB::table('orders');
        if (Schema::hasColumn('orders', 'store_id')) {
            $query->where('store_id', $store->id);
        }

        return $query;
    }

    private function sumOrdersAmount(Store $store, string $date, array $paymentMethods = []): float
    {
        $query = $this->ordersBaseQuery($store);
        $amountColumn = $this->firstExistingColumn('orders', ['final_amount', 'total_amount', 'total', 'amount']);
        $dateColumn = $this->firstExistingColumn('orders', ['delivered_at', 'created_at', 'updated_at']);
        if (!$query || !$amountColumn) {
            return 0;
        }
        if ($dateColumn) {
            $query->whereDate($dateColumn, $date);
        }
        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }
        if (!empty($paymentMethods) && Schema::hasColumn('orders', 'payment_method')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(payment_method, ""))'), array_map('strtolower', $paymentMethods));
        }

        return (float) $query->sum($amountColumn);
    }

    private function serializeStore(Store $store): array
    {
        return [
            'id' => (string) $store->id,
            'name' => (string) ($store->name ?? ''),
            'store_code' => $store->store_code ?? null,
            'status' => (string) ($store->status ?? ''),
            'approval_status' => (string) ($store->status ?? ''),
            'address' => $store->address ?? null,
            'phone' => $store->phone ?? null,
            'email' => $store->email ?? null,
            'logo_url' => $store->logo_url ?? $store->logo ?? null,
            'banner_url' => $store->banner_url ?? $store->cover_image ?? null,
            'is_open' => (bool) ($store->is_open ?? false),
            'credit' => $this->storeCreditSummary($store),
        ];
    }

    private function profileCompletion(Store $store): array
    {
        $fields = [
            'name' => (string) ($store->name ?? ''),
            'phone' => (string) ($store->phone ?? ''),
            'address' => (string) ($store->address ?? ''),
            'license_image' => (string) ($store->license_image ?? ''),
        ];
        $filled = count(array_filter($fields, fn ($value) => trim($value) !== ''));

        return [
            'percent' => (int) floor(($filled * 100) / max(1, count($fields))),
            'missing_fields' => array_keys(array_filter($fields, fn ($value) => trim($value) === '')),
        ];
    }

    private function serializeOrder(object $row): array
    {
        $amount = $this->firstProperty($row, ['final_amount', 'total_amount', 'total', 'amount']);
        $timeoutAt = $this->isoDate($row->timeout_at ?? null);
        $slaTimeoutMinutes = $this->firstProperty($row, ['sla_timeout_minutes']);
        $customerPhone = $this->firstProperty($row, [
            'delivery_phone',
            'customer_phone',
            'phone',
            'recipient_phone',
            'receiver_phone',
        ]);
        $customerName = $row->customer_name ?? $row->receiver_name ?? $row->name ?? null;

        return [
            'id' => (string) ($row->id ?? ''),
            'order_code' => $row->order_code ?? $row->code ?? null,
            'store_id' => isset($row->store_id) ? (string) $row->store_id : null,
            'user_id' => isset($row->user_id) ? (string) $row->user_id : null,
            'status' => (string) ($row->status ?? ''),
            'total_tickets_used' => isset($row->total_tickets_used) ? (int) $row->total_tickets_used : 0,
            'total_amount' => isset($row->total_amount) ? (float) $row->total_amount : null,
            'discount_amount' => isset($row->discount_amount) ? (float) $row->discount_amount : null,
            'final_amount' => isset($row->final_amount) ? (float) $row->final_amount : ($amount !== null ? (float) $amount : null),
            'payment_status' => $row->payment_status ?? null,
            'payment_method' => $row->payment_method ?? null,
            'customer_name' => $customerName,
            'delivery_phone' => $customerPhone,
            'delivery_address' => $row->delivery_address ?? $row->address ?? null,
            'shipping_method' => $row->shipping_method ?? null,
            'customer' => [
                'name' => $customerName,
                'phone' => $customerPhone,
            ],
            'items_brief' => [],
            'amount' => $amount !== null ? (float) $amount : null,
            'created_at' => $this->isoDate($row->created_at ?? null),
            'updated_at' => $this->isoDate($row->updated_at ?? null),
            'delivered_at' => $this->isoDate($row->delivered_at ?? null),
            'timeout_at' => $timeoutAt,
            'sla_timeout_minutes' => $slaTimeoutMinutes !== null ? (int) $slaTimeoutMinutes : null,
            'sla' => [
                'timeout_at' => $timeoutAt,
                'timeout_minutes' => $slaTimeoutMinutes !== null ? (int) $slaTimeoutMinutes : null,
                'warning_sent' => isset($row->sla_warning_sent) ? (bool) $row->sla_warning_sent : false,
                'is_pending' => strtolower((string) ($row->status ?? '')) === 'pending',
            ],
            'raw' => $this->serializeLooseRow($row),
        ];
    }

    private function serializeMenuItem(object $row): array
    {
        $stock = $this->firstProperty($row, ['stock', 'quantity']);
        $isAvailable = property_exists($row, 'is_available')
            ? (bool) $row->is_available
            : ($stock !== null ? (float) $stock > 0 : true);
        $isLucky = property_exists($row, 'is_lucky_item')
            ? (bool) $row->is_lucky_item
            : (property_exists($row, 'is_lucky') ? (bool) $row->is_lucky : false);

        return [
            'id' => (string) ($row->id ?? ''),
            'store_id' => isset($row->store_id) ? (string) $row->store_id : null,
            'product_id' => isset($row->product_id) ? (string) $row->product_id : null,
            'variant_id' => isset($row->variant_id) ? (string) $row->variant_id : null,
            'category_id' => isset($row->category_id) ? (string) $row->category_id : null,
            'item_code' => $row->item_code ?? $row->sku ?? $row->code ?? null,
            'item_name' => $row->item_name ?? $row->name ?? '',
            'name' => $row->item_name ?? $row->name ?? '',
            'description' => $row->description ?? $row->summary ?? $row->content ?? null,
            'category' => $row->category ?? null,
            'sub_category' => $row->sub_category ?? null,
            'price' => isset($row->price) ? (float) $row->price : null,
            'sale_price' => isset($row->sale_price) ? (float) $row->sale_price : null,
            'original_price' => isset($row->original_price) ? (float) $row->original_price : (isset($row->price) ? (float) $row->price : null),
            'stock' => $stock !== null ? (int) $stock : 0,
            'original_stock' => isset($row->original_stock) ? (int) $row->original_stock : 0,
            'variant_count' => isset($row->variant_count) ? (int) $row->variant_count : 0,
            'variants' => [],
            'image_url' => $row->image_url ?? $row->image ?? null,
            'is_available' => $isAvailable,
            'available' => $isAvailable,
            'in_stock' => $isAvailable,
            'is_lucky' => $isLucky,
            'is_lucky_item' => $isLucky,
            'status' => $row->status ?? null,
            'slug' => $row->slug ?? null,
            'sku' => $row->sku ?? null,
            'updated_at' => $this->isoDate($row->updated_at ?? null),
            'raw' => $this->serializeLooseRow($row),
        ];
    }

    private function serializeLooseRow(object $row): array
    {
        return collect((array) $row)->map(function ($value) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            return $value;
        })->all();
    }

    private function page(array $items, int $total, int $page, int $pageSize, array $extraMeta = []): array
    {
        $totalPages = (int) ceil($total / max(1, $pageSize));

        return [
            'items' => $items,
            'meta' => array_merge([
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
            ], $extraMeta),
        ];
    }

    private function pageSize(Request $request, int $fallback): int
    {
        return max(1, min(100, (int) $request->query('page_size', $request->query('per_page', $fallback))));
    }

    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        if (!Schema::hasTable($table)) {
            return null;
        }

        foreach ($candidates as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function firstProperty(object $row, array $candidates): mixed
    {
        foreach ($candidates as $property) {
            if (property_exists($row, $property) && $row->{$property} !== null) {
                return $row->{$property};
            }
        }

        return null;
    }

    private function completedOrderStatuses(): array
    {
        return ['delivered', 'completed', 'call_completed'];
    }

    private function isoDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
