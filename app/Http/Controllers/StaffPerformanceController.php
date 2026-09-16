<?php

namespace App\Http\Controllers;

use App\Exports\StaffPerformanceStoresExport;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\TicketPricingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class StaffPerformanceController extends Controller
{
    private const SUCCESS_STATUSES = ['delivered'];

    public function show(Request $request, int $staff)
    {
        $this->ensureSuperAdmin();

        $filters = $this->validateFilters($request);
        $staffUser = $this->resolveSaleStaff($staff);
        $dateRange = $this->resolveDateRange($filters);

        $storesQuery = $this->buildStoresPerformanceQuery($staffUser->id, $dateRange['from'], $dateRange['to']);
        $stores = $storesQuery->paginate(20);
        $stores->appends($request->query());
        $this->appendTicketBasedCommissionMetrics(collect($stores->items()), $dateRange['from'], $dateRange['to']);

        $summary = $this->buildSummary($staffUser->id, $dateRange['from'], $dateRange['to']);

        return view('admin.sales.performance.show', [
            'staff' => $staffUser,
            'stores' => $stores,
            'summary' => $summary,
            'filters' => $filters,
            'fromDate' => $dateRange['from']->toDateString(),
            'toDate' => $dateRange['to']->toDateString(),
        ]);
    }

    public function updateCommission(Request $request, int $staff)
    {
        $this->ensureSuperAdmin();

        $staffUser = $this->resolveSaleStaff($staff);

        $validated = $request->validate([
            'commission_rates' => ['required', 'array'],
            'commission_rates.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $rates = collect($validated['commission_rates'])
            ->mapWithKeys(function ($rate, $storeId) {
                return [(string) $storeId => round((float) ($rate ?? 0), 2)];
            });

        if ($rates->isEmpty()) {
            return back()->with('warning', 'Không có cấu hình hoa hồng nào để cập nhật.');
        }

        $storeIds = $rates->keys()->values()->all();

        $stores = Store::query()
            ->where('sale_id', $staffUser->id)
            ->whereIn('id', $storeIds)
            ->get(['id']);

        DB::transaction(function () use ($stores, $rates) {
            foreach ($stores as $store) {
                $store->update([
                    'sale_commission_rate' => (float) ($rates->get((string) $store->id, 0)),
                ]);
            }
        });

        return back()->with('success', 'Đã cập nhật cấu hình hoa hồng cho các cửa hàng đã chọn.');
    }

    public function export(Request $request, int $staff)
    {
        $this->ensureSuperAdmin();

        $filters = $this->validateFilters($request);
        $staffUser = $this->resolveSaleStaff($staff);
        $dateRange = $this->resolveDateRange($filters);

        $stores = $this->buildStoresPerformanceQuery($staffUser->id, $dateRange['from'], $dateRange['to'])->get();
        $this->appendTicketBasedCommissionMetrics($stores, $dateRange['from'], $dateRange['to']);

        $period = $dateRange['from']->format('d/m/Y') . ' - ' . $dateRange['to']->format('d/m/Y');
        $filename = 'sale-performance-' . $staffUser->id . '-' . now()->format('YmdHis') . '.xlsx';

        return Excel::download(new StaffPerformanceStoresExport($staffUser, $stores, $period), $filename);
    }

    public function calculateSaleMonthlyIncome(int $saleId, ?string $month = null): array
    {
        $targetMonth = $month ? Carbon::createFromFormat('Y-m', $month) : now();
        $from = $targetMonth->copy()->startOfMonth()->startOfDay();
        $to = $targetMonth->copy()->endOfMonth()->endOfDay();

        $stores = $this->buildStoresPerformanceQuery($saleId, $from, $to)->get();
        $this->appendTicketBasedCommissionMetrics($stores, $from, $to);

        $totalRevenue = (float) $stores->sum(function ($store) {
            return (float) ($store->delivered_revenue_sum ?? 0);
        });

        $totalTicketValue = (float) $stores->sum(function ($store) {
            return (float) ($store->delivered_ticket_value_sum ?? 0);
        });

        $totalCommission = (float) $stores->sum(function ($store) {
            return (float) ($store->estimated_commission ?? 0);
        });

        return [
            'month' => $targetMonth->format('Y-m'),
            'total_revenue' => $totalRevenue,
            'total_ticket_value' => $totalTicketValue,
            'total_commission' => $totalCommission,
            'stores' => $stores,
        ];
    }

    private function buildStoresPerformanceQuery(int $staffId, Carbon $from, Carbon $to): Builder
    {
        return Store::query()
            ->select(['id', 'store_code', 'name', 'logo_url', 'is_open', 'sale_id', 'sale_commission_rate'])
            ->where('sale_id', $staffId)
            ->withCount([
                'orders as delivered_orders_count' => function (Builder $query) use ($from, $to) {
                    $query->whereIn('status', self::SUCCESS_STATUSES)
                        ->whereBetween('created_at', [$from, $to]);
                },
            ])
            ->withSum([
                'orders as delivered_revenue_sum' => function (Builder $query) use ($from, $to) {
                    $query->whereIn('status', self::SUCCESS_STATUSES)
                        ->whereBetween('created_at', [$from, $to]);
                },
            ], 'final_amount')
            ->orderByDesc('delivered_revenue_sum')
            ->orderBy('name');
    }

    private function buildSummary(int $staffId, Carbon $from, Carbon $to): array
    {
        $totalStores = Store::query()
            ->where('sale_id', $staffId)
            ->count();

        $ordersQuery = Order::query()
            ->whereIn('status', self::SUCCESS_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('store', function (Builder $query) use ($staffId) {
                $query->where('sale_id', $staffId);
            });

        $staffStores = Store::query()
            ->where('sale_id', $staffId)
            ->get(['id', 'sale_commission_rate']);

        $ticketValueByStore = $this->resolveTicketValueSumsByStore(
            $staffStores->pluck('id')->all(),
            $from,
            $to
        );

        $totalTicketValue = (float) array_sum($ticketValueByStore);

        $totalEstimatedCommission = (float) $staffStores->sum(function (Store $store) use ($ticketValueByStore) {
            $storeId = (string) $store->id;
            $ticketValue = (float) ($ticketValueByStore[$storeId] ?? 0);
            $rate = (float) ($store->sale_commission_rate ?? 0);

            return $ticketValue * ($rate / 100);
        });

        return [
            'total_stores' => $totalStores,
            'total_orders' => (clone $ordersQuery)->count(),
            'total_revenue' => (float) ((clone $ordersQuery)->sum('final_amount') ?? 0),
            'total_ticket_value' => $totalTicketValue,
            'total_estimated_commission' => $totalEstimatedCommission,
        ];
    }

    private function appendTicketBasedCommissionMetrics(Collection $stores, Carbon $from, Carbon $to): void
    {
        if ($stores->isEmpty()) {
            return;
        }

        $ticketValueByStore = $this->resolveTicketValueSumsByStore($stores->pluck('id')->all(), $from, $to);

        $stores->each(function (Store $store) use ($ticketValueByStore) {
            $storeId = (string) $store->id;
            $ticketValue = (float) ($ticketValueByStore[$storeId] ?? 0);
            $rate = (float) ($store->sale_commission_rate ?? 0);

            $store->setAttribute('delivered_ticket_value_sum', round($ticketValue, 2));
            $store->setAttribute('estimated_commission', round($ticketValue * ($rate / 100), 2));
        });
    }

    private function resolveTicketValueSumsByStore(array $storeIds, Carbon $from, Carbon $to): array
    {
        $normalizedStoreIds = collect($storeIds)
            ->filter(static fn ($id) => $id !== null && $id !== '')
            ->map(static fn ($id) => (string) $id)
            ->values()
            ->all();

        if (empty($normalizedStoreIds)) {
            return [];
        }

        if (
            !Schema::hasTable('orders')
            || !Schema::hasTable('order_items')
            || !Schema::hasTable('products')
            || !Schema::hasColumn('orders', 'id')
            || !Schema::hasColumn('orders', 'store_id')
            || !Schema::hasColumn('orders', 'status')
            || !Schema::hasColumn('orders', 'created_at')
            || !Schema::hasColumn('order_items', 'order_id')
            || !Schema::hasColumn('order_items', 'product_id')
            || !Schema::hasColumn('order_items', 'quantity')
            || !Schema::hasColumn('order_items', 'unit_price')
            || !Schema::hasColumn('products', 'id')
            || !Schema::hasColumn('products', 'ticket_reward_quantity')
        ) {
            return [];
        }

        $hasTicketTypeCode = Schema::hasColumn('products', 'ticket_type_code');

        $ticketPricingService = app(TicketPricingService::class);
        $ticketTypePrices = collect($ticketPricingService->getTicketTypes())
            ->mapWithKeys(function (array $type): array {
                $code = strtoupper(trim((string) ($type['code'] ?? '')));
                if ($code === '') {
                    return [];
                }

                return [$code => (float) ($type['price'] ?? 0)];
            })
            ->all();

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereIn('o.store_id', $normalizedStoreIds)
            ->whereIn('o.status', self::SUCCESS_STATUSES)
            ->whereBetween('o.created_at', [$from, $to])
            ->selectRaw('o.store_id as store_id')
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw('COALESCE(oi.unit_price, 0) as unit_price')
            ->selectRaw('COALESCE(p.ticket_reward_quantity, 0) as ticket_reward_quantity')
            ->selectRaw($hasTicketTypeCode ? 'COALESCE(p.ticket_type_code, "") as ticket_type_code' : '"" as ticket_type_code')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $storeId = (string) ($row->store_id ?? '');
            if ($storeId === '') {
                continue;
            }

            $quantity = max(0, (int) ($row->quantity ?? 0));
            $ticketPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));
            $lineTickets = $quantity * $ticketPerUnit;
            if ($lineTickets < 1) {
                continue;
            }

            $ticketTypeCode = strtoupper(trim((string) ($row->ticket_type_code ?? '')));
            $unitPrice = (float) ($row->unit_price ?? 0);

            if ($ticketTypeCode !== '' && array_key_exists($ticketTypeCode, $ticketTypePrices)) {
                $ticketPrice = (float) $ticketTypePrices[$ticketTypeCode];
            } else {
                $ticketPrice = (float) $ticketPricingService->calculatePerTicketValue($unitPrice);
            }

            $totals[$storeId] = (float) (($totals[$storeId] ?? 0) + ($lineTickets * max(0, $ticketPrice)));
        }

        return $totals;
    }

    private function resolveSaleStaff(int $staffId): User
    {
        $query = User::query()
            ->select(['id', 'name', 'full_name', 'email', 'phone', 'role'])
            ->where('id', $staffId)
            ->where('role', 'sale');

        if (Schema::hasColumn('users', 'avatar_url')) {
            $query->addSelect('avatar_url as avatar_path');
        } elseif (Schema::hasColumn('users', 'avatar')) {
            $query->addSelect('avatar as avatar_path');
        }

        $staff = $query->first();

        if (!$staff) {
            abort(404, 'Không tìm thấy chuyên viên cửa hàng.');
        }

        return $staff;
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'range' => ['nullable', 'in:this_month,last_month,custom'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);
    }

    private function resolveDateRange(array $filters): array
    {
        $range = $filters['range'] ?? 'this_month';

        if ($range === 'last_month') {
            $from = now()->subMonthNoOverflow()->startOfMonth();
            $to = now()->subMonthNoOverflow()->endOfMonth();

            return ['from' => $from, 'to' => $to, 'range' => $range];
        }

        if ($range === 'custom') {
            $from = !empty($filters['from_date'])
                ? Carbon::parse((string) $filters['from_date'])->startOfDay()
                : now()->startOfMonth();

            $to = !empty($filters['to_date'])
                ? Carbon::parse((string) $filters['to_date'])->endOfDay()
                : now()->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return ['from' => $from, 'to' => $to, 'range' => $range];
        }

        $from = now()->startOfMonth();
        $to = now()->endOfDay();

        return ['from' => $from, 'to' => $to, 'range' => 'this_month'];
    }

    private function ensureSuperAdmin(): void
    {
        $role = (string) optional(auth()->user())->role;
        abort_unless(in_array($role, ['super_admin', 'admin'], true), 403, 'Chỉ super admin được phép thực hiện thao tác này.');
    }
}
