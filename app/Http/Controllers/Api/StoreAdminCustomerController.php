<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class StoreAdminCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'user_id')) {
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
                        'sort' => '-last_order_at',
                    ],
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:last_order_at,-last_order_at,orders_count,-orders_count,total_spent,-total_spent'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $filters = $validator->validated();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $sort = (string) ($filters['sort'] ?? '-last_order_at');

        $amountColumn = 'final_amount';
        if (!Schema::hasColumn('orders', 'final_amount')) {
            if (Schema::hasColumn('orders', 'total_amount')) {
                $amountColumn = 'total_amount';
            } elseif (Schema::hasColumn('orders', 'total')) {
                $amountColumn = 'total';
            }
        }

        $baseQuery = DB::table('orders as o')
            ->where('o.store_id', $store->id)
            ->whereNotNull('o.user_id');

        if (Schema::hasColumn('orders', 'status')) {
            $baseQuery->whereRaw('LOWER(o.status) != ?', ['pending_payment']);
        }

        if (!empty($filters['from'])) {
            $baseQuery->whereDate('o.created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $baseQuery->whereDate('o.created_at', '<=', $filters['to']);
        }

        $canJoinUsers = Schema::hasTable('users') && Schema::hasColumn('users', 'id');

        // Prepare user column availability checks to avoid referencing missing columns
        $hasUserFullName = $canJoinUsers && Schema::hasColumn('users', 'full_name');
        $hasUserName = $canJoinUsers && Schema::hasColumn('users', 'name');
        $hasUserPhone = $canJoinUsers && Schema::hasColumn('users', 'phone');
        $hasUserEmail = $canJoinUsers && Schema::hasColumn('users', 'email');
        $hasUserAvatar = $canJoinUsers && Schema::hasColumn('users', 'avatar_url');

        // Only join users table when it exists to allow selecting user fields safely
        if ($canJoinUsers) {
            $baseQuery->leftJoin('users as u', 'u.id', '=', 'o.user_id');
        }

        if (!empty($filters['q']) && $canJoinUsers) {
            $baseQuery->where(function ($query) use ($filters, $hasUserFullName, $hasUserName, $hasUserPhone, $hasUserEmail) {
                $q = trim((string) $filters['q']);

                // Build name expression depending on available columns
                if ($hasUserFullName) {
                    $nameExpr = 'COALESCE(u.full_name, u.name, "")';
                } elseif ($hasUserName) {
                    $nameExpr = 'COALESCE(u.name, "")';
                } else {
                    $nameExpr = '""';
                }

                $query->whereRaw($nameExpr . ' like ?', ['%' . $q . '%']);

                if ($hasUserPhone) {
                    $query->orWhereRaw('COALESCE(u.phone, "") like ?', ['%' . $q . '%']);
                }

                if ($hasUserEmail) {
                    $query->orWhereRaw('COALESCE(u.email, "") like ?', ['%' . $q . '%']);
                }
            });
        }

        $total = (int) (clone $baseQuery)->distinct('o.user_id')->count('o.user_id');

        $query = (clone $baseQuery)
            ->selectRaw('o.user_id as user_id')
            ->selectRaw('COUNT(o.id) as orders_count')
            ->selectRaw('MAX(o.created_at) as last_order_at')
            ->selectRaw('COALESCE(SUM(o.' . $amountColumn . '), 0) as total_spent');

        if ($canJoinUsers) {
            // Select a single resolved name column to avoid missing column errors
            if ($hasUserFullName) {
                $query->addSelect(DB::raw('MAX(COALESCE(u.full_name, u.name)) as full_name'));
            } elseif ($hasUserName) {
                $query->addSelect(DB::raw('MAX(u.name) as full_name'));
            } else {
                $query->addSelect(DB::raw('NULL as full_name'));
            }

            // Optional user columns aggregated with MAX() to satisfy ONLY_FULL_GROUP_BY
            if ($hasUserPhone) {
                $query->addSelect(DB::raw('MAX(u.phone) as phone'));
            } else {
                $query->addSelect(DB::raw('NULL as phone'));
            }

            if ($hasUserEmail) {
                $query->addSelect(DB::raw('MAX(u.email) as email'));
            } else {
                $query->addSelect(DB::raw('NULL as email'));
            }

            if ($hasUserAvatar) {
                $query->addSelect(DB::raw('MAX(u.avatar_url) as avatar_url'));
            } else {
                $query->addSelect(DB::raw('NULL as avatar_url'));
            }
        }

        $query->groupBy('o.user_id');

        if ($sort === 'last_order_at') {
            $query->orderBy('last_order_at');
        } elseif ($sort === '-last_order_at') {
            $query->orderByDesc('last_order_at');
        } elseif ($sort === 'orders_count') {
            $query->orderBy('orders_count');
        } elseif ($sort === '-orders_count') {
            $query->orderByDesc('orders_count');
        } elseif ($sort === 'total_spent') {
            $query->orderBy('total_spent');
        } else {
            $query->orderByDesc('total_spent');
        }

        $rows = $query->forPage($page, $pageSize)->get();

        $userIds = $rows->pluck('user_id')->filter()->unique()->values()->all();

        $reviewsMap = [];
        if (!empty($userIds) && Schema::hasTable('store_reviews') && Schema::hasColumn('store_reviews', 'user_id')) {
            $reviews = DB::table('store_reviews')
                ->where('store_id', $store->id)
                ->whereIn('user_id', $userIds)
                ->selectRaw('user_id')
                ->selectRaw('COUNT(*) as reviews_count')
                ->selectRaw('AVG(rating) as rating_avg')
                ->selectRaw(Schema::hasColumn('store_reviews', 'created_at') ? 'MAX(created_at) as last_review_at' : 'NULL as last_review_at')
                ->groupBy('user_id')
                ->get();

            foreach ($reviews as $review) {
                $reviewsMap[(string) $review->user_id] = [
                    'count' => (int) ($review->reviews_count ?? 0),
                    'avg_rating' => isset($review->rating_avg) ? round((float) $review->rating_avg, 2) : null,
                    'last_review_at' => $this->toIso($review->last_review_at ?? null),
                ];
            }
        }

        $addressMap = [];
        if (!empty($userIds) && Schema::hasTable('addresses') && Schema::hasColumn('addresses', 'user_id')) {
            $addressRows = DB::table('addresses')
                ->whereIn('user_id', $userIds)
                ->orderByDesc('created_at')
                ->get();

            foreach ($addressRows as $address) {
                $userId = (string) $address->user_id;
                if (!array_key_exists($userId, $addressMap)) {
                    $addressMap[$userId] = [
                        'name' => $address->name ?? null,
                        'phone' => isset($address->phone) ? (string) $address->phone : null,
                        'phone_masked' => $this->maskPhone(isset($address->phone) ? (string) $address->phone : null),
                        'address' => $address->address ?? null,
                        'alias' => $address->alias ?? null,
                    ];
                }
            }
        }

        $data = $rows->map(function ($row) use ($reviewsMap, $addressMap) {
            $userId = (string) ($row->user_id ?? '');
            $name = $row->full_name ?? ($row->name ?? null);

            return [
                'user_id' => $row->user_id,
                'customer' => [
                    'name' => $name,
                    'avatar_url' => $row->avatar_url ?? null,
                    'phone' => isset($row->phone) ? (string) $row->phone : null,
                    'phone_masked' => $this->maskPhone(isset($row->phone) ? (string) $row->phone : null),
                    'email' => $row->email ?? null,
                ],
                'orders' => [
                    'count' => (int) ($row->orders_count ?? 0),
                    'total_spent' => round((float) ($row->total_spent ?? 0), 2),
                    'last_order_at' => $this->toIso($row->last_order_at ?? null),
                ],
                'reviews' => $reviewsMap[$userId] ?? [
                    'count' => 0,
                    'avg_rating' => null,
                    'last_review_at' => null,
                ],
                'address' => $addressMap[$userId] ?? null,
            ];
        })->values()->all();

        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
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

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $phone) ?: '';
        $len = strlen($normalized);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($normalized, 0, 3) . str_repeat('*', max($len - 5, 2)) . substr($normalized, -2);
    }

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
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(string $code, string $message, int $status, bool $hasStore, bool $authenticated, ?array $details = null): JsonResponse
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
