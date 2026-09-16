<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreAdminNotification;
use App\Services\StoreCreditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class StoreAdminNotificationController extends Controller
{
    /**
     * GET /api/store-admin/notifications
     */
    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_admin_notifications')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'unread_count' => 0,
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        app(StoreCreditService::class)->syncWalletBalanceNotificationsForStore((string) $store->id);

        $validator = Validator::make($request->query(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'string', 'max:80'],
            'unread_only' => ['nullable', 'in:1,0,true,false,yes,no,on,off'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Du lieu dau vao khong hop le.',
                422,
                true,
                true,
                $validator->errors()->toArray()
            );
        }

        $filters = $validator->validated();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $typeFilter = trim((string) ($filters['type'] ?? ''));
        $unreadOnly = $this->parseBooleanQuery($filters['unread_only'] ?? null);

        $baseQuery = $this->scopedNotificationsQuery((string) $store->id);

        if ($typeFilter !== '') {
            $baseQuery->whereRaw('LOWER(type) = ?', [mb_strtolower($typeFilter)]);
        }

        $unreadCountQuery = $this->scopedNotificationsQuery((string) $store->id);
        $unreadCount = (int) $unreadCountQuery
            ->where(function (Builder $query) {
                $query->where('is_read', false)->orWhereNull('is_read');
            })
            ->count();

        $listQuery = clone $baseQuery;
        $listQuery->orderByDesc('created_at')->orderByDesc('id');

        if ($unreadOnly) {
            $listQuery->where(function (Builder $query) {
                $query->where('is_read', false)->orWhereNull('is_read');
            });
        }

        $total = (int) (clone $listQuery)->count();
        $rows = $listQuery
            ->forPage($page, $pageSize)
            ->get();

        $data = $rows
            ->map(fn (StoreAdminNotification $notification) => $this->serializeNotification($notification))
            ->values()
            ->all();

        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'unread_count' => $unreadCount,
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * PATCH /api/store-admin/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_admin_notifications')) {
            return $this->errorResponse(
                'TABLE_NOT_FOUND',
                'Khong tim thay bang thong bao cua hang.',
                404,
                true,
                true
            );
        }

        $notification = $this->scopedNotificationsQuery((string) $store->id)
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return $this->errorResponse(
                'NOTIFICATION_NOT_FOUND',
                'Khong tim thay thong bao hoac thong bao khong thuoc cua hang cua ban.',
                404,
                true,
                true
            );
        }

        $notification->is_read = true;
        $notification->read_at = now();
        $notification->save();
        $notification->refresh();

        return response()->json([
            'data' => $this->serializeNotification($notification),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * PATCH /api/store-admin/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_admin_notifications')) {
            return response()->json([
                'data' => [
                    'updated' => 0,
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $updated = $this->scopedNotificationsQuery((string) $store->id)
            ->where(function (Builder $query) {
                $query->where('is_read', false)->orWhereNull('is_read');
            })
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'data' => [
                'updated' => (int) $updated,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function scopedNotificationsQuery(string $storeId): Builder
    {
        return StoreAdminNotification::query()->where('store_id', $storeId);
    }

    private function serializeNotification(StoreAdminNotification $notification): array
    {
        $payloadData = $notification->data;
        $data = is_array($payloadData) ? $payloadData : [];
        $data = $this->appendOrderAmountData($notification, $data);

        $title = trim((string) ($notification->title ?? ''));
        if ($title === '') {
            $title = 'Thong bao don hang';
        }

        $message = trim((string) ($notification->message ?? ''));

        return [
            'id' => (string) $notification->id,
            'store_id' => trim((string) ($notification->store_id ?? '')) ?: null,
            'order_id' => trim((string) ($notification->order_id ?? '')) ?: null,
            'order_code' => trim((string) ($notification->order_code ?? '')) ?: null,
            'action_url' => trim((string) ($data['action_url'] ?? '')) ?: null,
            'type' => trim((string) ($notification->type ?? '')) ?: 'order_new',
            'title' => $title,
            'message' => $message,
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'data' => $data,
        ];
    }

    private function appendOrderAmountData(StoreAdminNotification $notification, array $data): array
    {
        if (!Schema::hasTable('orders')) {
            return $data;
        }

        $hasFinalAmount = array_key_exists('final_amount', $data) || array_key_exists('finalAmount', $data);
        $hasTotalAmount = array_key_exists('total_amount', $data) || array_key_exists('totalAmount', $data);
        $hasAmount = array_key_exists('amount', $data);

        if ($hasFinalAmount && $hasTotalAmount && $hasAmount) {
            return $data;
        }

        $orderId = trim((string) ($notification->order_id ?? $data['order_id'] ?? $data['orderId'] ?? ''));
        $orderCode = trim((string) ($notification->order_code ?? $data['order_code'] ?? $data['orderCode'] ?? ''));
        $hasOrderCodeColumn = Schema::hasColumn('orders', 'order_code');

        if ($orderId === '' && ($orderCode === '' || !$hasOrderCodeColumn)) {
            return $data;
        }

        $selectColumns = ['id'];
        foreach (['order_code', 'final_amount', 'total_amount', 'amount'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                $selectColumns[] = $column;
            }
        }

        $orderQuery = DB::table('orders')->select(array_values(array_unique($selectColumns)));
        $orderQuery->where(function ($query) use ($orderId, $orderCode, $hasOrderCodeColumn) {
            if ($orderId !== '') {
                $query->orWhere('id', $orderId);
            }

            if ($orderCode !== '' && $hasOrderCodeColumn) {
                $query->orWhere('order_code', $orderCode);
            }
        });

        $order = $orderQuery->first();
        if (!$order) {
            return $data;
        }

        $finalAmount = $this->normalizeAmount($order->final_amount ?? null);
        $totalAmount = $this->normalizeAmount($order->total_amount ?? null);
        $amount = $this->normalizeAmount($order->amount ?? null) ?? $finalAmount ?? $totalAmount;

        if (!$hasFinalAmount && $finalAmount !== null) {
            $data['final_amount'] = $finalAmount;
        }

        if (!$hasTotalAmount && $totalAmount !== null) {
            $data['total_amount'] = $totalAmount;
        }

        if (!$hasAmount && $amount !== null) {
            $data['amount'] = $amount;
        }

        return $data;
    }

    private function normalizeAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function parseBooleanQuery(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
        }

        if (!$context['store']) {
            return [
                null,
                $context['user'],
                $this->errorResponse(
                    'STORE_NOT_FOUND',
                    'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.',
                    404,
                    false,
                    true
                ),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        bool $hasStore,
        bool $authenticated,
        ?array $details = null
    ): JsonResponse {
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
