<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\appSetting as AppSetting;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminCmsAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SystemOrderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'store_id' => ['nullable', 'string', 'max:36'],
            'sale_id' => ['nullable', 'integer'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);

        $orderItemColumns = $this->existingColumns('order_items', [
            'id',
            'order_id',
            'item_name',
            'product_name',
            'quantity',
            'unit_price',
            'price',
            'total_price',
        ]);

        $orderTicketColumns = $this->existingColumns('order_tickets', [
            'id',
            'order_id',
            'ticket_id',
            'used_at',
        ]);

        $ordersQuery = Order::query()
            ->with([
                'user:id,name,full_name,phone',
                'store:id,store_code,name',
                'items' => function ($query) use ($orderItemColumns) {
                    if (!empty($orderItemColumns)) {
                        $query->select($orderItemColumns);
                    }
                },
                'tickets' => function ($query) use ($orderTicketColumns) {
                    if (!empty($orderTicketColumns)) {
                        $query->select($orderTicketColumns);
                    }

                    if (Schema::hasTable('tickets')) {
                        $query->with('ticket:id,ticket_code,status,value_amount');
                    }
                },
                'statusHistories' => function ($query) {
                    $query->with('changer:id,name,full_name,role')
                        ->orderByDesc('created_at');
                },
            ])
            ->orderByDesc('created_at');

        AdminCmsAccess::applyOrderScope($ordersQuery);

        $this->applyFilters($ordersQuery, $filters);

        $orders = $ordersQuery->paginate(15)->withQueryString();

        $orderIds = $orders->getCollection()
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        $refundsByOrder = collect();
        if (!empty($orderIds)) {
            $refundsByOrder = Refund::query()
                ->whereIn('source_id', $orderIds)
                ->orderByDesc('created_at')
                ->get()
                ->groupBy(static fn ($refund) => (string) $refund->source_id);
        }

        $storesQuery = Store::query()
            ->select(['id', 'store_code', 'name'])
            ->orderBy('name')
            ->limit(1000);

        AdminCmsAccess::applyStoreScope($storesQuery);

        $stores = $storesQuery->get();

        $saleUsersQuery = User::query()
            ->select(['id', 'name', 'full_name', 'phone', 'email'])
            ->where('role', 'sale')
            ->orderBy('full_name')
            ->orderBy('name')
            ->limit(1000);

        if (AdminCmsAccess::isSale()) {
            $saleUsersQuery->where('id', auth()->id());
        }

        $saleUsers = $saleUsersQuery->get();

        $summaryQuery = Order::query();
        AdminCmsAccess::applyOrderScope($summaryQuery);
        $this->applyFilters($summaryQuery, $filters);

        $summary = [
            'total_orders' => (clone $summaryQuery)->count(),
            'pending_orders' => (clone $summaryQuery)->where('status', 'pending')->count(),
            'confirmed_orders' => (clone $summaryQuery)->whereIn('status', ['confirmed', 'approved', 'preparing', 'ready'])->count(),
            'delivered_orders' => (clone $summaryQuery)->whereIn('status', ['delivered', 'completed'])->count(),
            'cancelled_orders' => (clone $summaryQuery)->where('status', 'cancelled')->count(),
        ];

        return view('admin.orders.system-index', [
            'orders' => $orders,
            'stores' => $stores,
            'saleUsers' => $saleUsers,
            'refundsByOrder' => $refundsByOrder,
            'summary' => $summary,
            'appSetting' => AppSetting::first() ?: new AppSetting(),
        ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['status']) && Schema::hasColumn('orders', 'status')) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['store_id']) && Schema::hasColumn('orders', 'store_id')) {
            $query->where('store_id', $filters['store_id']);
        }

        if (!empty($filters['sale_id']) && Schema::hasColumn('stores', 'sale_id')) {
            $query->whereHas('store', function (Builder $storeQuery) use ($filters) {
                $storeQuery->where('sale_id', (int) $filters['sale_id']);
            });
        }

        if (!empty($filters['from_date']) && Schema::hasColumn('orders', 'created_at')) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date']) && Schema::hasColumn('orders', 'created_at')) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function (Builder $subQuery) use ($keyword) {
                $hasCondition = false;

                if (Schema::hasColumn('orders', 'order_code')) {
                    $subQuery->where('order_code', 'like', '%' . $keyword . '%');
                    $hasCondition = true;
                }

                if (Schema::hasColumn('orders', 'phone')) {
                    $method = $hasCondition ? 'orWhere' : 'where';
                    $subQuery->{$method}('phone', 'like', '%' . $keyword . '%');
                    $hasCondition = true;
                }

                if (Schema::hasColumn('orders', 'delivery_phone')) {
                    $method = $hasCondition ? 'orWhere' : 'where';
                    $subQuery->{$method}('delivery_phone', 'like', '%' . $keyword . '%');
                    $hasCondition = true;
                }

                $userMethod = $hasCondition ? 'orWhereHas' : 'whereHas';
                $subQuery->{$userMethod}('user', function (Builder $userQuery) use ($keyword) {
                    $userQuery->where('phone', 'like', '%' . $keyword . '%')
                        ->orWhere('full_name', 'like', '%' . $keyword . '%')
                        ->orWhere('name', 'like', '%' . $keyword . '%');
                });
            });
        }
    }

    private function existingColumns(string $table, array $columns): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter($columns, static fn ($column) => Schema::hasColumn($table, $column)));
    }
}
