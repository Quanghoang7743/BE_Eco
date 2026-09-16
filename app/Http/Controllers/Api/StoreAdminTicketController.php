<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class StoreAdminTicketController extends Controller
{
    /**
     * GET /api/store-admin/tickets
     * Lấy danh sách vé đã được dùng để gọi món tại cửa hàng
     * (Hỗ trợ chức năng C14 - Báo cáo số lượng vé đã đổi)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        // Kiểm tra bảng tồn tại
        if (!Schema::hasTable('order_tickets')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'filters' => [
                        'q' => null,
                        'from' => null,
                        'to' => null,
                        'sort' => '-used_at',
                    ],
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        // Validate input
        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:used_at,-used_at,ticket_code,-ticket_code,value_amount,-value_amount'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Dữ liệu đầu vào không hợp lệ.',
                    'details' => $validator->errors()->toArray(),
                ]],
            ], 422);
        }

        $filters = $validator->validated();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $sort = (string) ($filters['sort'] ?? '-used_at');

        // Build query
        $query = DB::table('order_tickets as ot')
            ->join('orders as o', 'o.id', '=', 'ot.order_id')
            ->join('tickets as t', 't.id', '=', 'ot.ticket_id')
            ->where('o.store_id', $store->id);

        // Join users table to get customer name
        if (Schema::hasTable('users')) {
            $query->leftJoin('users as u', 'u.id', '=', 'ot.user_id');
        }

        // Apply search filter
        if (!empty($filters['q'])) {
            $searchTerm = trim((string) $filters['q']);
            $query->where(function (Builder $where) use ($searchTerm) {
                $where->where('t.ticket_code', 'like', '%' . $searchTerm . '%')
                    ->orWhere('o.order_code', 'like', '%' . $searchTerm . '%');
            });
        }

        // Apply date range filter (chỉ cho phép lọc theo used_at)
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $this->applyDateRange($query, $filters['from'] ?? null, $filters['to'] ?? null, ['ot.used_at']);
        }

        // Apply sort
        $this->applySort($query, $sort);

        // Count total before pagination
        $total = (int) (clone $query)->count('ot.id');

        // Build customer name expression
        $customerNameExpr = "''";
        if (Schema::hasTable('users')) {
            $hasFullName = Schema::hasColumn('users', 'full_name');
            $hasName = Schema::hasColumn('users', 'name');

            if ($hasFullName && $hasName) {
                $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
            } elseif ($hasFullName) {
                $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
            } elseif ($hasName) {
                $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
            }
        }

        // Execute query with pagination
        $rows = $query->forPage($page, $pageSize)->select([
            'ot.id',
            'ot.order_id',
            'ot.ticket_id',
            'ot.user_id',
            'ot.used_at',
            't.ticket_code',
            't.value_amount',
            'o.order_code',
            'o.store_id',
            DB::raw($customerNameExpr . ' as customer_name'),
        ])->get();

        // Format response data
        $data = $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'ticket_code' => $row->ticket_code,
                'ticket_id' => $row->ticket_id,
                'order_code' => $row->order_code,
                'order_id' => $row->order_id,
                'customer_name' => $row->customer_name ?: null,
                'customer_id' => $row->user_id,
                'value_amount' => isset($row->value_amount) ? (float) $row->value_amount : 0.0,
                'used_at' => $this->toIso($row->used_at ?? null),
            ];
        })->values()->all();

        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;
        $hasNext = $page < $totalPages;

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $hasNext,
                'filters' => [
                    'q' => $filters['q'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                    'sort' => $sort,
                ],
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * GET /api/store-admin/tickets/{id}
     * Lấy chi tiết một vé đã sử dụng
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function show(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('order_tickets') || !Schema::hasTable('orders') || !Schema::hasTable('tickets')) {
            return $this->errorResponse(
                'TABLE_NOT_FOUND',
                'Không tìm thấy bảng dữ liệu cần thiết.',
                404,
                true,
                true
            );
        }

        // Build query to get ticket detail
        $query = DB::table('order_tickets as ot')
            ->join('orders as o', 'o.id', '=', 'ot.order_id')
            ->join('tickets as t', 't.id', '=', 'ot.ticket_id')
            ->where('ot.id', $id)
            ->where('o.store_id', $store->id);

        // Join users table
        if (Schema::hasTable('users')) {
            $query->leftJoin('users as u', 'u.id', '=', 'ot.user_id');
        }

        // Build customer name expression
        $customerNameExpr = "''";
        if (Schema::hasTable('users')) {
            $hasFullName = Schema::hasColumn('users', 'full_name');
            $hasName = Schema::hasColumn('users', 'name');

            if ($hasFullName && $hasName) {
                $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
            } elseif ($hasFullName) {
                $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
            } elseif ($hasName) {
                $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
            }
        }

        $row = $query->select([
            'ot.id',
            'ot.order_id',
            'ot.ticket_id',
            'ot.user_id',
            'ot.used_at',
            't.ticket_code',
            't.value_amount',
            't.status as ticket_status',
            't.purchased_at as ticket_purchased_at',
            't.expired_at as ticket_expired_at',
            't.source_type',
            't.source_id',
            'o.order_code',
            'o.order_code as order_code',
            'o.store_id',
            DB::raw($customerNameExpr . ' as customer_name'),
        ])->first();

        if (!$row) {
            return $this->errorResponse(
                'TICKET_NOT_FOUND',
                'Không tìm thấy vé hoặc vé không thuộc về cửa hàng của bạn.',
                404,
                true,
                true
            );
        }

        $data = [
            'id' => $row->id,
            'ticket_code' => $row->ticket_code,
            'ticket_id' => $row->ticket_id,
            'ticket_status' => $row->ticket_status,
            'order_code' => $row->order_code,
            'order_id' => $row->order_id,
            'customer_name' => $row->customer_name ?: null,
            'customer_id' => $row->user_id,
            'value_amount' => isset($row->value_amount) ? (float) $row->value_amount : 0.0,
            'source_type' => $row->source_type,
            'source_id' => $row->source_id,
            'ticket_purchased_at' => $this->toIso($row->ticket_purchased_at ?? null),
            'ticket_expired_at' => $this->toIso($row->ticket_expired_at ?? null),
            'used_at' => $this->toIso($row->used_at ?? null),
        ];

        return response()->json([
            'data' => $data,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Helper: Resolve store context from authenticated user
     * Ensures store_id is derived from token, not frontend
     *
     * @param Request $request
     * @return array [Store|null, User|null, JsonResponse|null]
     */
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
                    'Không tìm thấy cửa hàng thuộc quyền quản lý của tài khoản này.',
                    404,
                    false,
                    true
                ),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    /**
     * Helper: Resolve authentication context
     *
     * @param Request $request
     * @return array
     */
    private function resolveContext(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [
                'user' => null,
                'store' => null,
                'error' => $this->errorResponse(
                    'UNAUTHENTICATED',
                    'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                    401,
                    false,
                    false
                ),
            ];
        }

        $accessContext = $this->resolveStoreAdminAccessContext($request);
        if (!$accessContext['error']) {
            return $accessContext;
        }

        $isStoreAdminRole = in_array((string) $user->role, ['store_admin', 'admin_store', 'store_owner', 'shop_admin', 'admin'], true);
        if (!$isStoreAdminRole) {
            return [
                'user' => $user,
                'store' => null,
                'error' => $this->errorResponse(
                    'FORBIDDEN_ROLE',
                    'Tài khoản hiện tại không có quyền admin cửa hàng.',
                    403,
                    false,
                    true
                ),
            ];
        }

        $storeQuery = Store::query()->where('owner_id', $user->id)->orderByDesc('created_at');
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        return [
            'user' => $user,
            'store' => $storeQuery->first(),
            'error' => null,
        ];
    }

    /**
     * Helper: Apply date range filter
     *
     * @param Builder $query
     * @param string|null $from Y-m-d format
     * @param string|null $to Y-m-d format
     * @param array $preferredColumns Columns to try for date filtering
     * @return void
     */
    private function applyDateRange(Builder $query, ?string $from, ?string $to, array $preferredColumns): void
    {
        $column = null;
        foreach ($preferredColumns as $candidate) {
            // Note: For order_tickets, we don't use Schema::hasColumn since table is explicitly queried
            $column = $candidate;
            break;
        }

        if (!$column) {
            return;
        }

        if ($from) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }

    /**
     * Helper: Apply sort to query
     *
     * @param Builder $query
     * @param string $sort Sort string like 'used_at' or '-used_at'
     * @return void
     */
    private function applySort(Builder $query, string $sort): void
    {
        $sortMap = [
            'used_at' => ['ot.used_at', 'asc'],
            '-used_at' => ['ot.used_at', 'desc'],
            'ticket_code' => ['t.ticket_code', 'asc'],
            '-ticket_code' => ['t.ticket_code', 'desc'],
            'value_amount' => ['t.value_amount', 'asc'],
            '-value_amount' => ['t.value_amount', 'desc'],
        ];

        $resolved = $sortMap[$sort] ?? $sortMap['-used_at'];
        $query->orderBy($resolved[0], $resolved[1]);
    }

    /**
     * Helper: Convert datetime to ISO 8601 format
     *
     * @param mixed $value
     * @return string|null
     */
    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper: Error response builder
     *
     * @param string $code
     * @param string $message
     * @param int $status
     * @param bool $hasStore
     * @param bool $authenticated
     * @param array|null $details
     * @return JsonResponse
     */
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
