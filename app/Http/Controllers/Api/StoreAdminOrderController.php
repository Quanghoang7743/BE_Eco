<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderStatusUpdated;
use App\Events\Customer\OrderNotificationEvent;
use App\Jobs\UpgradeStoreCreditLevelJob;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\appSetting as AppSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\AffiliateCommissionService;
use App\Services\StoreTicketTransactionService;
use App\Services\NotificationService;
use App\Services\OrderStockRestorationService;
use App\Services\StoreAdmin\StorePermissionCatalog;
use App\Services\StoreCreditService;
use App\Services\TicketRewardService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreAdminOrderController extends Controller
{
    private const DEFAULT_SLA_TIMEOUT_MINUTES = 3;
    private const STORE_ADMIN_CANCELLABLE_STATUSES = ['pending', 'confirmed', 'preparing', 'accepted'];
    private const STORE_ADMIN_CREDIT_REVERSAL_STATUSES = ['confirmed', 'preparing', 'accepted'];

    private const DEFAULT_REJECTION_REASONS = [
        [
            'reason_code' => 'OUT_OF_STOCK',
            'reason_text' => 'Hết món',
        ],
        [
            'reason_code' => 'OVERLOADED',
            'reason_text' => 'Quá tải đơn hàng',
        ],
        [
            'reason_code' => 'OUT_OF_AREA',
            'reason_text' => 'Ngoài khu vực giao hàng',
        ],
        [
            'reason_code' => 'CUSTOMER_RISK',
            'reason_text' => 'Khách hàng có dấu hiệu rủi ro',
        ],
    ];

    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    private function resolveOrderAffiliateRecipientId(object $order): ?int
    {
        $buyerId = (int) ($order->user_id ?? 0);
        if ($buyerId > 0) {
            $buyer = User::query()->find($buyerId);
            $buyerReferrerId = (int) ($buyer?->referrer_id ?? 0);

            if ($buyerReferrerId > 0 && $buyerReferrerId !== $buyerId) {
                return $buyerReferrerId;
            }
        }

        $orderReferrerId = (int) ($order->referrer_id ?? 0);
        return $orderReferrerId > 0 ? $orderReferrerId : null;
    }

    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'filters' => [
                        'status' => null,
                        'q' => null,
                        'from' => null,
                        'to' => null,
                        'sort' => '-created_at',
                    ],
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at,-created_at,final_amount,-final_amount,total_amount,-total_amount,status,-status'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Du lieu dau vao khong hop le.',
                        'details' => $validator->errors()->toArray(),
                    ]
                ],
            ], 422);
        }

        $filters = $validator->validated();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $sort = (string) ($filters['sort'] ?? '-created_at');
        $hasCancelReason = Schema::hasColumn('orders', 'cancel_reason');
        $hasCancelledAt = Schema::hasColumn('orders', 'cancelled_at');
        $hasDeliveredAt = Schema::hasColumn('orders', 'delivered_at');

        $query = DB::table('orders as o')->where('o.store_id', $store->id);

        if (!empty($filters['status']) && Schema::hasColumn('orders', 'status')) {
            $statusInput = (string) $filters['status'];
            if (str_contains($statusInput, ',')) {
                $statuses = array_map(function($s) {
                    return mb_strtolower(trim($s));
                }, explode(',', $statusInput));
                $query->whereIn(DB::raw('LOWER(o.status)'), $statuses);
            } else {
                $query->whereRaw('LOWER(o.status) = ?', [mb_strtolower(trim($statusInput))]);
            }
        } else {
            // Default: exclude unpaid orders from store admin view
            $query->whereRaw('LOWER(o.status) != ?', ['pending_payment']);
        }

        if (!empty($filters['q']) && Schema::hasColumn('orders', 'order_code')) {
            $query->where('o.order_code', 'like', '%' . trim((string) $filters['q']) . '%');
        }

        if (!empty($filters['from']) || !empty($filters['to'])) {
            $this->applyDateRange($query, $filters['from'] ?? null, $filters['to'] ?? null, ['created_at']);
        }

        $customerNameExpr = "''";
        if (Schema::hasTable('users') && Schema::hasColumn('orders', 'user_id')) {
            $query->leftJoin('users as u', 'u.id', '=', 'o.user_id');

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

        $this->applySort($query, $sort);
        $total = (int) (clone $query)->count('o.id');

        $hasSlaTimeoutMinutes = Schema::hasColumn('orders', 'sla_timeout_minutes');
        $hasTimeoutAt = Schema::hasColumn('orders', 'timeout_at');
        $hasOrderPhone = Schema::hasColumn('orders', 'phone');
        $hasOrderReferrer = Schema::hasColumn('orders', 'referrer_id');

        $rows = $query->forPage($page, $pageSize)->select([
            'o.id',
            'o.order_code',
            'o.user_id',
            'o.store_id',
            DB::raw($hasOrderReferrer ? 'o.referrer_id as referrer_id' : 'NULL as referrer_id'),
            'o.status',
            'o.total_tickets_used',
            'o.total_amount',
            'o.discount_amount',
            'o.final_amount',
            'o.delivery_phone',
            DB::raw($hasOrderPhone ? 'o.phone as order_phone' : 'NULL as order_phone'),
            'o.delivery_address',
            DB::raw(Schema::hasColumn('orders', 'shipping_method') ? 'o.shipping_method' : 'NULL as shipping_method'),
            DB::raw(Schema::hasColumn('orders', 'payment_method') ? 'o.payment_method' : 'NULL as payment_method'),
            'o.created_at',
            DB::raw($hasDeliveredAt ? 'o.delivered_at' : 'NULL as delivered_at'),
            DB::raw($hasCancelReason ? 'o.cancel_reason' : 'NULL as cancel_reason'),
            DB::raw($hasCancelledAt ? 'o.cancelled_at' : 'NULL as cancelled_at'),
            DB::raw($hasSlaTimeoutMinutes ? 'o.sla_timeout_minutes' : 'NULL as sla_timeout_minutes'),
            DB::raw($hasTimeoutAt ? 'o.timeout_at' : 'NULL as timeout_at'),
            DB::raw($customerNameExpr . ' as customer_name'),
        ])->get();

        $orderIds = $rows->pluck('id')->filter()->values()->all();
        $itemBriefMap = $this->loadOrderItemBrief($orderIds);
        $affiliateSummaryMap = $this->loadOrderAffiliateSummaries($orderIds);

        // Batch ticket-used resolution and the SLA warning setting out of the
        // per-row loop to avoid 1-2 extra queries per order plus an app_settings
        // query per order (fixes N+1).
        $ticketFallbacks = [];
        foreach ($rows as $row) {
            $ticketFallbacks[(string) $row->id] = isset($row->total_tickets_used) ? (int) $row->total_tickets_used : null;
        }
        $ticketsUsedMap = $this->resolveOrderTicketsUsedMap(
            $rows->pluck('id')->filter()->values()->all(),
            $ticketFallbacks
        );
        $slaWarningSeconds = $this->resolveSlaWarningSeconds();

        $data = $rows->map(function ($row) use ($affiliateSummaryMap, $itemBriefMap, $ticketsUsedMap, $slaWarningSeconds) {
            $customerPhoneRaw = $row->delivery_phone ?? $row->order_phone ?? $row->phone ?? null;
            $totalTicketsUsed = $ticketsUsedMap[(string) $row->id] ?? 0;
            $affiliateSummary = $affiliateSummaryMap[(string) $row->id] ?? null;
            if (!$affiliateSummary && !empty($row->referrer_id)) {
                $affiliateSummary = [
                    'has_affiliate' => true,
                    'referrer_id' => (string) $row->referrer_id,
                    'referrer_name' => null,
                    'referrer_phone' => null,
                    'commission_amount' => 0.0,
                    'commission_status' => null,
                    'source_label' => 'Người giới thiệu của đơn',
                ];
            }

            return [
                'id' => $row->id,
                'order_code' => $row->order_code,
                'user_id' => $row->user_id,
                'store_id' => $row->store_id,
                'status' => $row->status,
                'total_tickets_used' => $totalTicketsUsed,
                'total_amount' => isset($row->total_amount) ? (float) $row->total_amount : null,
                'discount_amount' => isset($row->discount_amount) ? (float) $row->discount_amount : null,
                'final_amount' => isset($row->final_amount) ? (float) $row->final_amount : null,
                'delivery_phone' => $customerPhoneRaw,
                'delivery_phone_masked' => $this->maskPhone($customerPhoneRaw),
                'delivery_address' => $row->delivery_address ?? null,
                'shipping_method' => $row->shipping_method ?? null,
                'payment_method' => $row->payment_method ?? 'CASH',
                'customer' => [
                    'name' => $row->customer_name ?: null,
                    'phone' => $customerPhoneRaw,
                    'phone_masked' => $this->maskPhone($customerPhoneRaw),
                ],
                'affiliate' => $affiliateSummary,
                'items_brief' => $itemBriefMap[(string) $row->id] ?? [],
                'cancel_reason' => $row->cancel_reason ?? null,
                'cancelled_at' => $this->toIso($row->cancelled_at ?? null),
                'created_at' => $this->toIso($row->created_at ?? null),
                'delivered_at' => $this->toIso($row->delivered_at ?? null),
                'sla' => [
                    'timeout_at' => $this->toIso($row->timeout_at ?? null),
                    'timeout_minutes' => isset($row->sla_timeout_minutes) ? (int) $row->sla_timeout_minutes : null,
                    'warning_seconds' => $slaWarningSeconds,
                    'warning_sent' => false,
                    'is_pending' => $row->status === 'pending',
                ],
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
                    'status' => $filters['status'] ?? null,
                    'q' => $filters['q'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                    'sort' => $sort,
                ],
                'authenticated' => true,
                'has_store' => true,
                'server_time' => now()->toIso8601String(),
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function loadOrderAffiliateSummaries(array $orderIds): array
    {
        $orderIds = collect($orderIds)
            ->map(static fn($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($orderIds) || !Schema::hasTable('commission_users') || !Schema::hasTable('order_items')) {
            return [];
        }

        $hasUsers = Schema::hasTable('users');
        $query = DB::table('commission_users as cu')
            ->join('order_items as oi', 'oi.id', '=', 'cu.order_item_id')
            ->whereIn('oi.order_id', $orderIds);

        $nameExpression = "NULL";
        $phoneExpression = "NULL";
        if ($hasUsers) {
            $query->leftJoin('users as au', 'au.id', '=', 'cu.user_id');
            $hasFullName = Schema::hasColumn('users', 'full_name');
            $hasName = Schema::hasColumn('users', 'name');
            $hasPhone = Schema::hasColumn('users', 'phone');
            $hasPhoneNumber = Schema::hasColumn('users', 'phone_number');

            if ($hasFullName && $hasName) {
                $nameExpression = "COALESCE(NULLIF(au.full_name, ''), NULLIF(au.name, ''), '')";
            } elseif ($hasFullName) {
                $nameExpression = "COALESCE(NULLIF(au.full_name, ''), '')";
            } elseif ($hasName) {
                $nameExpression = "COALESCE(NULLIF(au.name, ''), '')";
            }

            if ($hasPhone && $hasPhoneNumber) {
                $phoneExpression = "COALESCE(NULLIF(au.phone, ''), NULLIF(au.phone_number, ''), '')";
            } elseif ($hasPhone) {
                $phoneExpression = "COALESCE(NULLIF(au.phone, ''), '')";
            } elseif ($hasPhoneNumber) {
                $phoneExpression = "COALESCE(NULLIF(au.phone_number, ''), '')";
            }
        }

        $rows = $query->select([
            DB::raw('oi.order_id as order_id'),
            DB::raw('cu.user_id as referrer_id'),
            DB::raw('cu.amount as commission_amount'),
            DB::raw(Schema::hasColumn('commission_users', 'status') ? 'cu.status as commission_status' : 'NULL as commission_status'),
            DB::raw($nameExpression . ' as referrer_name'),
            DB::raw($phoneExpression . ' as referrer_phone'),
        ])->get();

        $summaries = [];

        foreach ($rows as $row) {
            $orderId = (string) ($row->order_id ?? '');
            if ($orderId === '') {
                continue;
            }

            if (!isset($summaries[$orderId])) {
                $summaries[$orderId] = [
                    'has_affiliate' => true,
                    'referrer_id' => !empty($row->referrer_id) ? (string) $row->referrer_id : null,
                    'referrer_name' => !empty($row->referrer_name) ? (string) $row->referrer_name : null,
                    'referrer_phone' => !empty($row->referrer_phone) ? $this->maskPhone((string) $row->referrer_phone) : null,
                    'commission_amount' => 0.0,
                    'commission_status' => !empty($row->commission_status) ? (string) $row->commission_status : null,
                    'source_label' => 'Hoa hồng Affiliate',
                ];
            }

            $summaries[$orderId]['commission_amount'] += (float) ($row->commission_amount ?? 0);
        }

        return $summaries;
    }

    public function counts(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $statusKeys = ['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'returned', 'canceled', 'timeout', 'disputed'];
        $counts = array_fill_keys($statusKeys, 0);
        $total = 0;
        $slaWarning = 0;

        if (Schema::hasTable('orders')) {
            if (Schema::hasColumn('orders', 'status')) {
                $rows = DB::table('orders')
                    ->where('store_id', $store->id)
                    ->selectRaw('LOWER(status) as status_key, COUNT(*) as aggregate_count')
                    ->groupBy('status_key')
                    ->get();

                foreach ($rows as $row) {
                    $status = (string) ($row->status_key ?? '');
                    $count = (int) ($row->aggregate_count ?? 0);
                    if ($status === 'cancelled') {
                        $status = 'canceled';
                    }
                    if ($status === 'pending_payment') {
                        continue; // Skip unpaid orders in counts
                    }
                    if (array_key_exists($status, $counts)) {
                        $counts[$status] += $count;
                    }
                }
            }

            $total = (int) DB::table('orders')
                ->where('store_id', $store->id)
                ->whereRaw('LOWER(status) != ?', ['pending_payment'])
                ->count();

            if (Schema::hasColumn('orders', 'sla_warning_sent')) {
                $slaWarning = (int) DB::table('orders')
                    ->where('store_id', $store->id)
                    ->where('sla_warning_sent', true)
                    ->count();
            }
        }

        return response()->json([
            'data' => [
                'status_counts' => $counts,
                'total' => $total,
                'sla_warning' => $slaWarning,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function rejectionReasons(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        return response()->json([
            'data' => [
                'rejection_reasons' => self::DEFAULT_REJECTION_REASONS,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function cancellationDetails(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $hasCancelledBy = Schema::hasColumn('orders', 'cancelled_by');
        $hasUsersTable = Schema::hasTable('users');
        $canJoinCanceller = $hasCancelledBy && $hasUsersTable && Schema::hasColumn('users', 'id');

        $query = DB::table('orders as o')
            ->where('o.id', $id)
            ->where('o.store_id', $store->id);

        if ($canJoinCanceller) {
            $query->leftJoin('users as ca', 'ca.id', '=', 'o.cancelled_by');
        }

        $cancellerNameExpr = "''";
        if ($canJoinCanceller && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
            $cancellerNameExpr = "COALESCE(NULLIF(ca.full_name, ''), NULLIF(ca.name, ''), '')";
        } elseif ($canJoinCanceller && Schema::hasColumn('users', 'full_name')) {
            $cancellerNameExpr = "COALESCE(NULLIF(ca.full_name, ''), '')";
        } elseif ($canJoinCanceller && Schema::hasColumn('users', 'name')) {
            $cancellerNameExpr = "COALESCE(NULLIF(ca.name, ''), '')";
        }

        $order = $query->select([
            'o.id',
            'o.order_code',
            'o.status',
            DB::raw(Schema::hasColumn('orders', 'cancel_reason') ? 'o.cancel_reason' : 'NULL as cancel_reason'),
            DB::raw(Schema::hasColumn('orders', 'cancelled_at') ? 'o.cancelled_at' : 'NULL as cancelled_at'),
            DB::raw($hasCancelledBy ? 'o.cancelled_by' : 'NULL as cancelled_by'),
            DB::raw($canJoinCanceller && Schema::hasColumn('users', 'avatar_url') ? 'ca.avatar_url' : 'NULL as cancelled_by_avatar_url'),
            DB::raw($cancellerNameExpr . ' as cancelled_by_name'),
        ])->first();

        if (!$order) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $status = mb_strtolower(trim((string) ($order->status ?? '')));
        if (!in_array($status, ['canceled', 'cancelled'], true)) {
            return $this->errorResponse('ORDER_NOT_CANCELED', 'Don hang chua o trang thai huy.', 422, true, true, [
                'current_status' => $order->status,
                'required_status' => 'canceled',
            ]);
        }

        $parsedReason = $this->parseCancelReason($order->cancel_reason ?? null);
        $timelineReason = null;
        if (
            Schema::hasTable('order_status_history')
            && Schema::hasColumn('order_status_history', 'order_id')
            && Schema::hasColumn('order_status_history', 'new_status')
            && Schema::hasColumn('order_status_history', 'reason')
        ) {
            $historyQuery = DB::table('order_status_history')
                ->where('order_id', $id)
                ->whereRaw('LOWER(new_status) in (?, ?)', ['canceled', 'cancelled']);

            if (Schema::hasColumn('order_status_history', 'created_at')) {
                $historyQuery->orderByDesc('created_at');
            } else {
                $historyQuery->orderByDesc('id');
            }

            $timelineReason = $historyQuery->value('reason');
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_code' => $order->order_code,
                'status' => 'canceled',
                'cancel_reason' => $order->cancel_reason,
                'reason_code' => $parsedReason['reason_code'],
                'note' => $parsedReason['note'],
                'cancelled_at' => $this->toIso($order->cancelled_at ?? null),
                'cancelled_by' => [
                    'id' => $order->cancelled_by ?? null,
                    'name' => $order->cancelled_by_name ?: null,
                    'avatar_url' => $order->cancelled_by_avatar_url ?? null,
                ],
                'timeline_reason' => $timelineReason,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $query = DB::table('orders as o')
            ->where('o.id', $id)
            ->where('o.store_id', $store->id);

        $canJoinStore = Schema::hasTable('stores') && Schema::hasColumn('orders', 'store_id') && Schema::hasColumn('stores', 'id');
        if ($canJoinStore) {
            $query->leftJoin('stores as s', 's.id', '=', 'o.store_id');
        }

        $canJoinUser = Schema::hasTable('users') && Schema::hasColumn('orders', 'user_id') && Schema::hasColumn('users', 'id');
        if ($canJoinUser) {
            $query->leftJoin('users as u', 'u.id', '=', 'o.user_id');
        }

        $customerNameExpr = "''";
        if ($canJoinUser && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
        } elseif ($canJoinUser && Schema::hasColumn('users', 'full_name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
        } elseif ($canJoinUser && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
        }

        $hasLegacyTotal = Schema::hasColumn('orders', 'total');
        $hasOrderPhone = Schema::hasColumn('orders', 'phone');
        $hasStoreName = $canJoinStore && Schema::hasColumn('stores', 'name');
        $hasStoreAddress = $canJoinStore && Schema::hasColumn('stores', 'address');
        $hasStorePhone = $canJoinStore && Schema::hasColumn('stores', 'phone');
        $hasStoreLogo = $canJoinStore && Schema::hasColumn('stores', 'logo_url');
        $hasUserPhone = $canJoinUser && Schema::hasColumn('users', 'phone');

        $order = $query->select([
            'o.id',
            'o.order_code',
            'o.store_id',
            'o.user_id',
            'o.status',
            'o.total_tickets_used',
            'o.total_amount',
            'o.discount_amount',
            'o.final_amount',
            DB::raw($hasLegacyTotal ? 'o.total' : 'NULL as total'),
            'o.delivery_address',
            'o.delivery_phone',
            DB::raw($hasOrderPhone ? 'o.phone as order_phone' : 'NULL as order_phone'),
            'o.created_at',
            DB::raw(Schema::hasColumn('orders', 'confirmed_at') ? 'o.confirmed_at' : 'NULL as confirmed_at'),
            DB::raw($hasStoreName ? 's.name' : 'NULL as store_name'),
            DB::raw($hasStoreAddress ? 's.address' : 'NULL as store_address'),
            DB::raw($hasStorePhone ? 's.phone' : 'NULL as store_phone'),
            DB::raw($hasStoreLogo ? 's.logo_url' : 'NULL as store_logo_url'),
            DB::raw($hasUserPhone ? 'u.phone' : 'NULL as user_phone'),
            DB::raw($customerNameExpr . ' as customer_name'),
        ])->first();

        if (!$order) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $totalAmount = isset($order->total_amount) ? (float) $order->total_amount : null;
        $discountAmount = isset($order->discount_amount) ? (float) $order->discount_amount : null;
        $finalAmount = isset($order->final_amount) ? (float) $order->final_amount : null;

        $totalTicketsUsed = $this->resolveOrderTicketsUsed(
            (string) $order->id,
            isset($order->total_tickets_used) ? (int) $order->total_tickets_used : null
        );

        if ($totalAmount === null && isset($order->total)) {
            $totalAmount = (float) $order->total;
        }
        if ($finalAmount === null && $totalAmount !== null) {
            $finalAmount = $discountAmount !== null ? max($totalAmount - $discountAmount, 0) : $totalAmount;
        }

        $items = $this->loadOrderItems((string) $order->id);
        $issuedAt = $this->toIso($order->confirmed_at ?? null) ?? $this->toIso($order->created_at ?? null);
        $customerPhoneRaw = $order->delivery_phone ?? $order->order_phone ?? $order->user_phone ?? null;

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'invoice_number' => 'INV-' . ($order->order_code ?: strtoupper(substr(str_replace('-', '', (string) $order->id), 0, 12))),
                'status' => $order->status,
                'issued_at' => $issuedAt,
                'store' => [
                    'id' => $order->store_id,
                    'name' => $order->store_name ?? null,
                    'address' => $order->store_address ?? null,
                    'phone' => $order->store_phone ?? null,
                    'logo_url' => $order->store_logo_url ?? null,
                ],
                'customer' => [
                    'id' => $order->user_id,
                    'name' => $order->customer_name ?: null,
                    'phone' => $customerPhoneRaw ? (string) $customerPhoneRaw : null,
                    'phone_masked' => $this->maskPhone($customerPhoneRaw ? (string) $customerPhoneRaw : null),
                    'delivery_address' => $order->delivery_address ?? null,
                ],
                'items' => $items,
                'pricing' => [
                    'total_tickets_used' => isset($order->total_tickets_used) ? (int) $order->total_tickets_used : 0,
                    'total_amount' => $totalAmount,
                    'discount_amount' => $discountAmount,
                    'final_amount' => $finalAmount,
                ],
                'invoice_file_url' => null,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $user = $context['user'];

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $orderContext = DB::table('orders as o')->where('o.id', $id)->first();
        if (!$orderContext) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $store = $this->resolveAccessibleStoreById($user, (string) ($orderContext->store_id ?? ''));
        if (!$store) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $query = DB::table('orders as o')->where('o.id', $id)->where('o.store_id', $store->id);
        if (Schema::hasTable('users') && Schema::hasColumn('orders', 'user_id')) {
            $query->leftJoin('users as u', 'u.id', '=', 'o.user_id');
        }

        $hasFullName = Schema::hasTable('users') && Schema::hasColumn('users', 'full_name');
        $hasName = Schema::hasTable('users') && Schema::hasColumn('users', 'name');
        $hasAvatar = Schema::hasTable('users') && Schema::hasColumn('users', 'avatar_url');
        $hasUserPhone = Schema::hasTable('users') && Schema::hasColumn('users', 'phone');
        $hasInternalNote = Schema::hasColumn('orders', 'internal_note');
        $hasLegacyTotal = Schema::hasColumn('orders', 'total');
        $hasOrderPhone = Schema::hasColumn('orders', 'phone');
        $hasConfirmedAt = Schema::hasColumn('orders', 'confirmed_at');
        $hasPreparingAt = Schema::hasColumn('orders', 'preparing_at');
        $hasReadyAt = Schema::hasColumn('orders', 'ready_at');
        $hasDeliveredAt = Schema::hasColumn('orders', 'delivered_at');
        $hasReturnedAt = Schema::hasColumn('orders', 'returned_at');
        $hasCancelledAt = Schema::hasColumn('orders', 'cancelled_at');
        $hasCancelledBy = Schema::hasColumn('orders', 'cancelled_by');
        $hasTimeoutAt = Schema::hasColumn('orders', 'timeout_at');
        $hasCancelReason = Schema::hasColumn('orders', 'cancel_reason');
        $hasSlaWaringSent = Schema::hasColumn('orders', 'sla_warning_sent');
        $hasSlaTimeoutMinutes = Schema::hasColumn('orders', 'sla_timeout_minutes');

        $customerNameExpr = "''";
        if ($hasFullName && $hasName) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
        } elseif ($hasFullName) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
        } elseif ($hasName) {
            $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
        }

        $order = $query->select([
            'o.id',
            'o.order_code',
            'o.user_id',
            'o.store_id',
            'o.status',
            'o.delivery_address',
            'o.delivery_phone',
            DB::raw($hasOrderPhone ? 'o.phone as order_phone' : 'NULL as order_phone'),
            'o.delivery_note',
            'o.total_tickets_used',
            'o.total_amount',
            'o.discount_amount',
            'o.final_amount',
            DB::raw(Schema::hasColumn('orders', 'shipping_method') ? 'o.shipping_method' : 'NULL as shipping_method'),
            DB::raw(Schema::hasColumn('orders', 'payment_method') ? 'o.payment_method' : 'NULL as payment_method'),
            DB::raw($hasLegacyTotal ? 'o.total' : 'NULL as total'),
            DB::raw($hasConfirmedAt ? 'o.confirmed_at' : 'NULL as confirmed_at'),
            DB::raw($hasPreparingAt ? 'o.preparing_at' : 'NULL as preparing_at'),
            DB::raw($hasReadyAt ? 'o.ready_at' : 'NULL as ready_at'),
            DB::raw($hasDeliveredAt ? 'o.delivered_at' : 'NULL as delivered_at'),
            DB::raw($hasReturnedAt ? 'o.returned_at' : 'NULL as returned_at'),
            DB::raw($hasCancelledAt ? 'o.cancelled_at' : 'NULL as cancelled_at'),
            DB::raw($hasCancelledBy ? 'o.cancelled_by' : 'NULL as cancelled_by'),
            DB::raw($hasTimeoutAt ? 'o.timeout_at' : 'NULL as timeout_at'),
            DB::raw($hasSlaWaringSent ? 'o.sla_warning_sent' : '0 as sla_warning_sent'),
            DB::raw($hasSlaTimeoutMinutes ? 'o.sla_timeout_minutes' : self::DEFAULT_SLA_TIMEOUT_MINUTES . ' as sla_timeout_minutes'),
            DB::raw($hasCancelReason ? 'o.cancel_reason' : 'NULL as cancel_reason'),
            'o.created_at',
            'o.updated_at',
            DB::raw($customerNameExpr . ' as customer_name'),
            DB::raw($hasAvatar ? 'u.avatar_url' : 'NULL as customer_avatar_url'),
            DB::raw($hasUserPhone ? 'u.phone' : 'NULL as user_phone'),
            DB::raw($hasInternalNote ? 'o.internal_note' : 'NULL as internal_note'),
        ])->first();

        if (!$order) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $totalTicketsUsed = isset($order->total_tickets_used) ? (int) $order->total_tickets_used : 0;
        $totalAmount = isset($order->total_amount) ? (float) $order->total_amount : null;
        $discountAmount = isset($order->discount_amount) ? (float) $order->discount_amount : null;
        $finalAmount = isset($order->final_amount) ? (float) $order->final_amount : null;

        if ($totalAmount === null && isset($order->total)) {
            $totalAmount = (float) $order->total;
        }
        if ($finalAmount === null && $totalAmount !== null) {
            $finalAmount = $discountAmount !== null ? max($totalAmount - $discountAmount, 0) : $totalAmount;
        }

        $timeline = $this->loadOrderTimeline((string) $order->id, $order);
        $internalNote = $order->internal_note ?? null;
        if (($internalNote === null || trim((string) $internalNote) === '') && !empty($order->cancel_reason)) {
            $internalNote = (string) $order->cancel_reason;
        }
        $parsedCancellation = $this->parseCancelReason($order->cancel_reason ?? null);
        $customerRiskProfile = $this->buildCustomerRiskProfile($order->user_id ?? null, (string) $store->id);
        $customerPhoneRaw = $order->delivery_phone ?? $order->order_phone ?? $order->user_phone ?? null;

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_code' => $order->order_code,
                'status' => $order->status,
                'created_at' => $this->toIso($order->created_at ?? null),
                'updated_at' => $this->toIso($order->updated_at ?? null),
                'delivered_at' => $this->toIso($order->delivered_at ?? null),
                'customer' => [
                    'id' => $order->user_id,
                    'name' => $order->customer_name ?: null,
                    'avatar_url' => $order->customer_avatar_url ?? null,
                    'phone' => $customerPhoneRaw ? (string) $customerPhoneRaw : null,
                    'phone_masked' => $this->maskPhone($customerPhoneRaw ? (string) $customerPhoneRaw : null),
                ],
                'customer_risk_profile' => $customerRiskProfile,
                'customer_stats' => $customerRiskProfile,
                'delivery' => [
                    'address' => $order->delivery_address ?? null,
                    'phone' => $customerPhoneRaw ? (string) $customerPhoneRaw : null,
                    'note' => $order->delivery_note ?? null,
                    'shipping_method' => $order->shipping_method ?? null,
                    'payment_method' => $order->payment_method ?? 'CASH',
                ],
                'ticket_codes' => $this->loadOrderTicketCodes((string) $order->id),
                'items' => $this->loadOrderItems((string) $order->id),
                'pricing' => [
                    'total_tickets_used' => $totalTicketsUsed,
                    'total_amount' => $totalAmount,
                    'discount_amount' => $discountAmount,
                    'final_amount' => $finalAmount,
                ],
                'dispute' => $this->loadLatestDispute((string) $order->id),
                'timeline' => $timeline,
                'internal_note' => $internalNote,
                'cancel_reason' => $order->cancel_reason ?? null,
                'cancelled_at' => $this->toIso($order->cancelled_at ?? null),
                'returned_at' => $this->toIso($order->returned_at ?? null),
                'cancelled_by' => $order->cancelled_by ?? null,
                'cancellation' => [
                    'reason_code' => $parsedCancellation['reason_code'],
                    'note' => $parsedCancellation['note'],
                ],
                'sla' => [
                    'timeout_at' => $order->timeout_at ? $this->toIso($order->timeout_at) : null,
                    'timeout_minutes' => (int) ($order->sla_timeout_minutes ?? self::DEFAULT_SLA_TIMEOUT_MINUTES),
                    'warning_seconds' => $this->resolveSlaWarningSeconds(),
                    'warning_sent' => (bool) ($order->sla_warning_sent ?? false),
                    'is_pending' => $order->status === 'pending',
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'server_time' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $user = $context['user'];

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $orderProbe = DB::table('orders')->where('id', $id)->first();
        if (!$orderProbe) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $store = $this->resolveAccessibleStoreById($user, (string) ($orderProbe->store_id ?? ''));
        if (!$store) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        try {
            DB::beginTransaction();

            $order = Order::query()
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $lockedStore = Store::query()->whereKey((string) $store->id)->lockForUpdate()->first();
            if (!$lockedStore) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $creditService = app(StoreCreditService::class);
            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            $oldStatus = (string) ($order->status ?? '');
            $confirmedOrLaterStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'delivered', 'completed'];

            if (in_array($currentStatus, $confirmedOrLaterStatuses, true)) {
                if ($creditService->acceptedLedgerExists((string) $order->id)) {
                    DB::rollBack();

                    return response()->json([
                        'data' => [
                            'id' => $id,
                            'status' => 'confirmed',
                            'old_status' => $order->status,
                            'confirmed_at' => $this->toIso($order->confirmed_at ?? null),
                            'idempotent' => true,
                        ],
                        'meta' => [
                            'authenticated' => true,
                            'has_store' => true,
                            'request_at' => now()->toIso8601String(),
                        ],
                        'errors' => [],
                    ]);
                }

                DB::rollBack();
                return $this->errorResponse('CREDIT_LEDGER_INCONSISTENT', 'Don hang da duoc xac nhan nhung thieu ledger cong no.', 409, true, true, [
                    'order_id' => (string) $order->id,
                    'store_id' => (string) $lockedStore->id,
                    'current_status' => $order->status,
                ]);
            }

            if ($currentStatus !== 'pending') {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi co the xac nhan don hang dang o trang thai pending.', 422, true, true, [
                    'current_status' => $order->status,
                    'required_status' => 'pending',
                ]);
            }

            $snapshot = $creditService->isSnapshotFresh($order, $lockedStore)
                ? $creditService->buildCapacitySnapshot($lockedStore, (float) ($order->ticket_fee_vnd ?? 0), $order)
                : $creditService->refreshOrderCreditSnapshot($order, $lockedStore);

            $isDebtLocked = (bool) ($snapshot['is_debt_locked'] ?? $lockedStore->is_debt_locked ?? false);
            if ($isDebtLocked || (float) ($snapshot['required_topup'] ?? 0) > 0) {
                DB::commit();
                return $this->errorResponse(
                    'INSUFFICIENT_CREDIT_CAPACITY',
                    $isDebtLocked
                        ? 'Cửa hàng đang bị khóa công nợ, cần xử lý công nợ trước khi xác nhận đơn hàng mới.'
                        : 'Cửa hàng cần nạp thêm tiền trước khi xác nhận đơn hàng này.',
                    409,
                    true,
                    true,
                    $creditService->insufficientCreditDetails($order, $lockedStore, $snapshot)
                );
            }

            $now = now();
            $updatePayload = ['status' => 'confirmed'];
            if (Schema::hasColumn('orders', 'confirmed_at')) {
                $updatePayload['confirmed_at'] = $now;
            }
            // Clear SLA timeout and warning when order is confirmed so frontend stops countdown
            if (Schema::hasColumn('orders', 'timeout_at')) {
                $updatePayload['timeout_at'] = null;
            }
            if (Schema::hasColumn('orders', 'sla_warning_sent')) {
                $updatePayload['sla_warning_sent'] = false;
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                $updatePayload['updated_at'] = $now;
            }

            $order->forceFill($updatePayload)->save();
            $creditResult = $creditService->acceptOrder($order->fresh() ?? $order, $lockedStore);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $oldStatus;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'confirmed';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = 'store_admin_confirmed';
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }
                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            $orderModel = $order->fresh(['items.product', 'items.variant']);
            if ($orderModel instanceof Order) {
                $commissionService = app(\App\Services\AffiliateCommissionService::class);
                $commissionService->syncOrderCommissions($orderModel);
                $commissionService->syncOrderLifecycle($orderModel->fresh());
            }

            DB::commit();

            OrderStatusUpdated::dispatch(
                $id,
                $oldStatus,
                'confirmed',
                $store->id,
                [
                    'order_code' => $order->order_code ?? null,
                    'confirmed_at' => $now->toIso8601String(),
                    'ticket_fee_vnd' => $creditResult['ticket_fee_vnd'] ?? 0,
                ]
            );

            Event::dispatch('order.updated', [
                'order_id' => $id,
                'store_id' => $store->id,
                'old_status' => $oldStatus,
                'new_status' => 'confirmed',
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'confirmed',
                    'order_confirmed'
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (confirm).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'status' => 'confirmed',
                    'old_status' => $oldStatus,
                    'confirmed_at' => $now->toIso8601String(),
                    'credit' => [
                        'ticket_fee_vnd' => $creditResult['ticket_fee_vnd'] ?? 0,
                        'wallet_used' => $creditResult['wallet_used'] ?? 0,
                        'debt_increase' => $creditResult['debt_increase'] ?? 0,
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Store admin confirm order failed.', [
                'order_id' => $id,
                'exception' => $e,
            ]);
            return $this->errorResponse('CONFIRM_ORDER_FAILED', 'Khong the xac nhan don hang vao luc nay.', 500, true, true);
        }
    }

    public function markReady(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));

            if ($currentStatus === 'ready') {
                DB::rollBack();
                return response()->json([
                    'data' => [
                        'id' => $id,
                        'status' => 'ready',
                        'old_status' => $order->status,
                        'ready_at' => $this->toIso($order->ready_at ?? null),
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            if (!in_array($currentStatus, ['confirmed', 'preparing'], true)) {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi co the chuyen don hang sang ready khi dang o trang thai confirmed hoac preparing.', 422, true, true, [
                    'current_status' => $order->status,
                    'required_status' => 'confirmed|preparing',
                ]);
            }

            $now = now();
            $updatePayload = ['status' => 'ready'];
            if ($currentStatus === 'confirmed' && Schema::hasColumn('orders', 'preparing_at')) {
                $updatePayload['preparing_at'] = $now;
            }
            if (Schema::hasColumn('orders', 'ready_at')) {
                $updatePayload['ready_at'] = $now;
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                $updatePayload['updated_at'] = $now;
            }

            DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($updatePayload);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'ready';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = 'store_admin_mark_ready';
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }
                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            DB::commit();

            OrderStatusUpdated::dispatch(
                $id,
                $order->status,
                'ready',
                $store->id,
                [
                    'order_code' => $order->order_code ?? null,
                    'ready_at' => $now->toIso8601String(),
                ]
            );

            Event::dispatch('order.updated', [
                'order_id' => $id,
                'store_id' => $store->id,
                'old_status' => $order->status,
                'new_status' => 'ready',
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'ready',
                    'order_ready'
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (ready).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'status' => 'ready',
                    'old_status' => $order->status,
                    'ready_at' => $now->toIso8601String(),
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('MARK_READY_FAILED', 'Khong the chuyen trang thai don hang sang ready vao luc nay.', 500, true, true);
        }
    }

    public function refundTicket(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'reason_code' => ['required', 'string', 'max:50'],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Du lieu dau vao khong hop le.',
                        'details' => $validator->errors()->toArray(),
                    ]
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $reasonCode = strtoupper(trim((string) ($validated['reason_code'] ?? '')));
        $reasonText = trim((string) ($validated['reason_text'] ?? 'Hoàn vé do cửa hàng từ chối đơn hoặc lỗi vận hành.'));

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $existingRefund = null;
            if (
                Schema::hasTable('refunds')
                && Schema::hasColumn('refunds', 'source_id')
                && Schema::hasColumn('refunds', 'source_type')
            ) {
                $refundQuery = DB::table('refunds')
                    ->where('source_id', $id)
                    ->whereRaw('LOWER(source_type) = ?', ['order']);

                if (Schema::hasColumn('refunds', 'processed_at')) {
                    $refundQuery->orderByDesc('processed_at');
                } elseif (Schema::hasColumn('refunds', 'created_at')) {
                    $refundQuery->orderByDesc('created_at');
                } else {
                    $refundQuery->orderByDesc('id');
                }

                $existingRefund = $refundQuery->first();
            }

            if ($existingRefund) {
                DB::rollBack();
                return response()->json([
                    'data' => [
                        'order_id' => $id,
                        'order_code' => $order->order_code ?? null,
                        'refunded_tickets' => 0,
                        'status' => 'already_refunded',
                        'refund_id' => $existingRefund->id ?? null,
                        'refund_code' => $existingRefund->refund_code ?? null,
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            $now = now();

            $orderTicketRows = collect();
            if (Schema::hasTable('order_tickets') && Schema::hasColumn('order_tickets', 'order_id')) {
                $ticketSelect = [];
                if (Schema::hasColumn('order_tickets', 'ticket_id')) {
                    $ticketSelect[] = 'ticket_id';
                }
                if (Schema::hasColumn('order_tickets', 'user_id')) {
                    $ticketSelect[] = 'user_id';
                }

                if (!empty($ticketSelect)) {
                    $orderTicketRows = DB::table('order_tickets')
                        ->where('order_id', $id)
                        ->select($ticketSelect)
                        ->get();
                }
            }

            $ticketIds = $orderTicketRows->pluck('ticket_id')
                ->filter()
                ->map(static fn($ticketId) => (string) $ticketId)
                ->unique()
                ->values()
                ->all();

            $userId = $order->user_id ?? null;
            if ($userId === null && !empty($orderTicketRows)) {
                $userId = $orderTicketRows->pluck('user_id')->filter()->first();
            }

            if (!empty($ticketIds) && Schema::hasTable('tickets')) {
                $ticketUpdatePayload = [];
                if (Schema::hasColumn('tickets', 'status')) {
                    $ticketUpdatePayload['status'] = 'AVAILABLE';
                }
                if (Schema::hasColumn('tickets', 'used_in_order_id')) {
                    $ticketUpdatePayload['used_in_order_id'] = null;
                }
                if (Schema::hasColumn('tickets', 'used_at')) {
                    $ticketUpdatePayload['used_at'] = null;
                }
                if (Schema::hasColumn('tickets', 'source_type')) {
                    $ticketUpdatePayload['source_type'] = 'ORDER_REFUND';
                }
                if (Schema::hasColumn('tickets', 'source_id')) {
                    $ticketUpdatePayload['source_id'] = $id;
                }
                if (Schema::hasColumn('tickets', 'updated_at')) {
                    $ticketUpdatePayload['updated_at'] = $now;
                }

                if (!empty($ticketUpdatePayload)) {
                    DB::table('tickets')->whereIn('id', $ticketIds)->update($ticketUpdatePayload);
                }
            }

            $refundedCount = count($ticketIds);
            $balanceBefore = null;
            $balanceAfter = null;

            if ($refundedCount > 0 && $userId !== null && Schema::hasTable('user_balances')) {
                $hasAvailableBalance = Schema::hasColumn('user_balances', 'available_balance');
                $hasLockedBalance = Schema::hasColumn('user_balances', 'locked_balance');
                $hasUsedBalance = Schema::hasColumn('user_balances', 'used_balance');
                $hasExpiredBalance = Schema::hasColumn('user_balances', 'expired_balance');
                $hasTotalLifetimePurchased = Schema::hasColumn('user_balances', 'total_lifetime_purchased');
                $hasTotalLifetimeUsed = Schema::hasColumn('user_balances', 'total_lifetime_used');
                $hasLastUpdated = Schema::hasColumn('user_balances', 'last_updated');
                $hasVersion = Schema::hasColumn('user_balances', 'version');

                $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();

                if (!$balance) {
                    $insertPayload = ['user_id' => $userId];
                    if ($hasAvailableBalance) {
                        $insertPayload['available_balance'] = 0;
                    }
                    if ($hasLockedBalance) {
                        $insertPayload['locked_balance'] = 0;
                    }
                    if ($hasUsedBalance) {
                        $insertPayload['used_balance'] = 0;
                    }
                    if ($hasExpiredBalance) {
                        $insertPayload['expired_balance'] = 0;
                    }
                    if ($hasTotalLifetimePurchased) {
                        $insertPayload['total_lifetime_purchased'] = 0;
                    }
                    if ($hasTotalLifetimeUsed) {
                        $insertPayload['total_lifetime_used'] = 0;
                    }
                    if ($hasLastUpdated) {
                        $insertPayload['last_updated'] = $now;
                    }
                    if ($hasVersion) {
                        $insertPayload['version'] = 0;
                    }

                    DB::table('user_balances')->insert($insertPayload);
                    $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
                }

                $balanceBefore = (int) ($balance->available_balance ?? 0);
                $balanceAfter = $balanceBefore + $refundedCount;

                $balanceUpdatePayload = [];
                if ($hasAvailableBalance) {
                    $balanceUpdatePayload['available_balance'] = $balanceAfter;
                }
                if ($hasUsedBalance) {
                    $balanceUpdatePayload['used_balance'] = max(0, (int) ($balance->used_balance ?? 0) - $refundedCount);
                }
                if ($hasLastUpdated) {
                    $balanceUpdatePayload['last_updated'] = $now;
                }
                if ($hasVersion) {
                    $balanceUpdatePayload['version'] = (int) ($balance->version ?? 0) + 1;
                }

                if (!empty($balanceUpdatePayload)) {
                    DB::table('user_balances')->where('user_id', $userId)->update($balanceUpdatePayload);
                }
            }

            $hasLedgerId = Schema::hasColumn('ticket_ledger', 'id');
            $hasLedgerTicketId = Schema::hasColumn('ticket_ledger', 'ticket_id');
            $hasLedgerUserId = Schema::hasColumn('ticket_ledger', 'user_id');
            $hasLedgerActionType = Schema::hasColumn('ticket_ledger', 'action_type');
            $hasLedgerAmountChange = Schema::hasColumn('ticket_ledger', 'amount_change');
            $hasLedgerBalanceBefore = Schema::hasColumn('ticket_ledger', 'balance_before');
            $hasLedgerBalanceAfter = Schema::hasColumn('ticket_ledger', 'balance_after');
            $hasLedgerReferenceId = Schema::hasColumn('ticket_ledger', 'reference_id');
            $hasLedgerReferenceType = Schema::hasColumn('ticket_ledger', 'reference_type');
            $hasLedgerMetadata = Schema::hasColumn('ticket_ledger', 'metadata');
            $hasLedgerCreatedAt = Schema::hasColumn('ticket_ledger', 'created_at');

            $runningBalance = $balanceBefore;

            foreach ($ticketIds as $ticketId) {
                $entryBalanceBefore = $runningBalance;
                $entryBalanceAfter = $entryBalanceBefore === null ? null : ((int) $entryBalanceBefore + 1);

                $ledgerPayload = [];
                if ($hasLedgerId) {
                    $ledgerPayload['id'] = (string) Str::uuid();
                }
                if ($hasLedgerTicketId) {
                    $ledgerPayload['ticket_id'] = $ticketId;
                }
                if ($hasLedgerUserId) {
                    $ledgerPayload['user_id'] = $userId;
                }
                if ($hasLedgerActionType) {
                    $ledgerPayload['action_type'] = 'REFUND';
                }
                if ($hasLedgerAmountChange) {
                    $ledgerPayload['amount_change'] = 1;
                }
                if ($hasLedgerBalanceBefore) {
                    $ledgerPayload['balance_before'] = $entryBalanceBefore;
                }
                if ($hasLedgerBalanceAfter) {
                    $ledgerPayload['balance_after'] = $entryBalanceAfter;
                }
                if ($hasLedgerReferenceId) {
                    $ledgerPayload['reference_id'] = $id;
                }
                if ($hasLedgerReferenceType) {
                    $ledgerPayload['reference_type'] = 'ORDER';
                }
                if ($hasLedgerMetadata) {
                    $ledgerPayload['metadata'] = json_encode([
                        'order_code' => $order->order_code ?? null,
                        'reason_code' => $reasonCode,
                        'reason_text' => $reasonText,
                        'message' => 'Hoàn vé từ đơn hàng ' . ($order->order_code ?? $id) . ' do cửa hàng từ chối/lỗi vận hành',
                        'ticket_id' => $ticketId,
                        'ticket_count' => 1,
                    ], JSON_UNESCAPED_UNICODE);
                }
                if ($hasLedgerCreatedAt) {
                    $ledgerPayload['created_at'] = $now;
                }

                if (!empty($ledgerPayload)) {
                    app(\App\Services\TicketLedgerService::class)->record($ledgerPayload);
                }

                $runningBalance = $entryBalanceAfter;
            }

            $refundId = null;
            $refundCode = null;

            if (Schema::hasTable('refunds')) {
                $refundId = Schema::hasColumn('refunds', 'id') ? (string) Str::uuid() : null;
                $refundCode = 'RF-' . now()->format('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                $refundPayload = [];
                if ($refundId !== null) {
                    $refundPayload['id'] = $refundId;
                }
                if (Schema::hasColumn('refunds', 'refund_code')) {
                    $refundPayload['refund_code'] = $refundCode;
                }
                if (Schema::hasColumn('refunds', 'user_id')) {
                    $refundPayload['user_id'] = $userId;
                }
                if (Schema::hasColumn('refunds', 'ticket_id')) {
                    $refundPayload['ticket_id'] = $ticketIds[0] ?? null;
                }
                if (Schema::hasColumn('refunds', 'refund_type')) {
                    $refundPayload['refund_type'] = 'ticket';
                }
                if (Schema::hasColumn('refunds', 'reason')) {
                    $refundPayload['reason'] = $reasonText;
                }
                if (Schema::hasColumn('refunds', 'reason_code')) {
                    $refundPayload['reason_code'] = $reasonCode;
                }
                if (Schema::hasColumn('refunds', 'old_status')) {
                    $refundPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('refunds', 'new_status')) {
                    $refundPayload['new_status'] = 'REFUNDED';
                }
                if (Schema::hasColumn('refunds', 'source_type')) {
                    $refundPayload['source_type'] = 'ORDER';
                }
                if (Schema::hasColumn('refunds', 'source_id')) {
                    $refundPayload['source_id'] = $id;
                }
                if (Schema::hasColumn('refunds', 'processed_by')) {
                    $refundPayload['processed_by'] = $user->id;
                }
                if (Schema::hasColumn('refunds', 'processed_at')) {
                    $refundPayload['processed_at'] = $now;
                }
                if (Schema::hasColumn('refunds', 'metadata')) {
                    $refundPayload['metadata'] = json_encode([
                        'ticket_ids' => $ticketIds,
                        'ticket_count' => $refundedCount,
                        'store_id' => $store->id,
                        'order_code' => $order->order_code ?? null,
                    ], JSON_UNESCAPED_UNICODE);
                }
                if (Schema::hasColumn('refunds', 'created_at')) {
                    $refundPayload['created_at'] = $now;
                }

                if (!empty($refundPayload)) {
                    DB::table('refunds')->insert($refundPayload);
                }
            }

            if (Schema::hasTable('refund_logs')) {
                $refundLogPayload = [];
                if (Schema::hasColumn('refund_logs', 'id')) {
                    $refundLogPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('refund_logs', 'refund_id')) {
                    $refundLogPayload['refund_id'] = $refundId;
                }
                if (Schema::hasColumn('refund_logs', 'action')) {
                    $refundLogPayload['action'] = 'store_admin_refund_ticket';
                }
                if (Schema::hasColumn('refund_logs', 'details')) {
                    $refundLogPayload['details'] = 'Hoan ' . $refundedCount . ' ve cho don ' . ($order->order_code ?? $id);
                }
                if (Schema::hasColumn('refund_logs', 'metadata')) {
                    $refundLogPayload['metadata'] = json_encode([
                        'order_id' => $id,
                        'order_code' => $order->order_code ?? null,
                        'reason_code' => $reasonCode,
                        'reason_text' => $reasonText,
                        'processed_by' => $user->id,
                    ], JSON_UNESCAPED_UNICODE);
                }
                if (Schema::hasColumn('refund_logs', 'created_at')) {
                    $refundLogPayload['created_at'] = $now;
                }

                if (!empty($refundLogPayload)) {
                    DB::table('refund_logs')->insert($refundLogPayload);
                }
            }

            $orderModel = Order::query()->find($id);
            if ($orderModel instanceof Order) {
                app(StoreTicketTransactionService::class)->recordRefundReversal($orderModel, 'store_admin_refund_ticket');
            }

            DB::commit();

            Event::dispatch('ticket.refunded', [
                'order_id' => $id,
                'order_code' => $order->order_code ?? null,
                'store_id' => $store->id,
                'user_id' => $userId,
                'refunded_tickets' => $refundedCount,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
                'refunded_at' => $now->toIso8601String(),
            ]);

            return response()->json([
                'data' => [
                    'order_id' => $id,
                    'order_code' => $order->order_code ?? null,
                    'status' => 'refunded',
                    'refunded_tickets' => $refundedCount,
                    'reason_code' => $reasonCode,
                    'reason_text' => $reasonText,
                    'refund_id' => $refundId,
                    'refund_code' => $refundCode,
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('REFUND_TICKET_FAILED', 'Khong the hoan ve cho don hang vao luc nay.', 500, true, true);
        }
    }

    public function markPickedUp(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            if ($currentStatus === 'picked_up') {
                DB::rollBack();
                return response()->json([
                    'data' => [
                        'id' => $id,
                        'order_code' => $order->order_code ?? null,
                        'status' => 'picked_up',
                        'delivered_at' => $this->toIso($order->delivered_at ?? null),
                        'message' => 'Cap nhat trang thai don hang thanh cong.',
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            if ($currentStatus !== 'ready') {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi cho phep chuyen don hang tu ready sang picked_up.', 400, true, true, [
                    'current_status' => $order->status,
                    'required_status' => 'ready',
                ]);
            }

            $now = now();
            $updatePayload = ['status' => 'picked_up'];
            if (Schema::hasColumn('orders', 'updated_at')) {
                $updatePayload['updated_at'] = $now;
            }

            DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($updatePayload);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'picked_up';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = 'store_admin_mark_picked_up';
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }
                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            DB::commit();

            OrderStatusUpdated::dispatch(
                $id,
                $order->status,
                'picked_up',
                $store->id,
                [
                    'order_code' => $order->order_code ?? null,
                    'picked_up_at' => $now->toIso8601String(),
                ]
            );

            Event::dispatch('order.updated', [
                'order_id' => $id,
                'order_code' => $order->order_code ?? null,
                'store_id' => $store->id,
                'old_status' => $order->status,
                'new_status' => 'picked_up',
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
                'notify_channels' => ['mini_app'],
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'picked_up',
                    'order_picked_up'
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (picked_up).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'order_code' => $order->order_code ?? null,
                    'status' => 'picked_up',
                    'delivered_at' => null,
                    'message' => 'Cap nhat trang thai don hang thanh cong.',
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (
            \Throwable $e
        ) {
            DB::rollBack();
            return $this->errorResponse('MARK_PICKED_UP_FAILED', 'Khong the cap nhat trang thai picked_up vao luc nay.', 500, true, true);
        }
    }

    public function markDelivered(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            if ($currentStatus === 'delivered') {
                DB::rollBack();
                return response()->json([
                    'data' => [
                        'id' => $id,
                        'order_code' => $order->order_code ?? null,
                        'status' => 'delivered',
                        'delivered_at' => $this->toIso($order->delivered_at ?? null),
                        'message' => 'Cap nhat trang thai don hang thanh cong.',
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            if ($currentStatus !== 'picked_up') {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi cho phep chuyen don hang tu picked_up sang delivered.', 400, true, true, [
                    'current_status' => $order->status,
                    'required_status' => 'picked_up',
                ]);
            }

            $now = now();
            $updatePayload = ['status' => 'delivered'];
            if (Schema::hasColumn('orders', 'delivered_at')) {
                $updatePayload['delivered_at'] = $now;
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                $updatePayload['updated_at'] = $now;
            }

            // Mark cash orders as paid upon delivery
            if (Schema::hasColumn('orders', 'status_payment')) {
                $paymentMethod = strtoupper(trim((string) ($order->payment_method ?? '')));
                if (in_array($paymentMethod, ['CASH', 'COD'], true)) {
                    $updatePayload['status_payment'] = 'paid';
                }
            }

            DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($updatePayload);

            // Create commission for CASH/COD orders when payment is received (upon delivery)
            $paymentMethod = strtoupper(trim((string) ($order->payment_method ?? '')));
            if (in_array($paymentMethod, ['CASH', 'COD'], true)) {
                $orderItems = DB::table('order_items')
                    ->where('order_id', $id)
                    ->get();
                $commissionRecipientId = $this->resolveOrderAffiliateRecipientId($order);

                if ($commissionRecipientId && Schema::hasColumn('orders', 'referrer_id') && (int) ($order->referrer_id ?? 0) !== $commissionRecipientId) {
                    DB::table('orders')
                        ->where('id', $id)
                        ->update(['referrer_id' => $commissionRecipientId]);
                    $order->referrer_id = $commissionRecipientId;
                }

                if ($orderItems && !empty($commissionRecipientId)) {
                    $orderItemIds = $orderItems->pluck('id')
                        ->map(static fn($id): string => (string) $id)
                        ->filter()
                        ->values()
                        ->all();
                    $existingCommissionsByItemId = empty($orderItemIds)
                        ? collect()
                        : DB::table('commission_users')
                            ->whereIn('order_item_id', $orderItemIds)
                            ->get()
                            ->keyBy(static fn($commission): string => (string) $commission->order_item_id);
                    $variantCommissionRates = $orderItems->pluck('variant_id')
                        ->filter()
                        ->map(static fn($id): int => (int) $id)
                        ->unique()
                        ->values();
                    $variantCommissionRates = $variantCommissionRates->isEmpty()
                        ? collect()
                        : DB::table('product_variants')
                            ->whereIn('id', $variantCommissionRates->all())
                            ->pluck('commission_rate', 'id');
                    $productCommissionRates = $orderItems->pluck('product_id')
                        ->filter()
                        ->map(static fn($id): int => (int) $id)
                        ->unique()
                        ->values();
                    $productCommissionRates = $productCommissionRates->isEmpty()
                        ? collect()
                        : DB::table('products')
                            ->whereIn('id', $productCommissionRates->all())
                            ->pluck('commission_rate', 'id');

                    foreach ($orderItems as $item) {
                        $existingCommission = $existingCommissionsByItemId->get((string) $item->id);

                        $quantity = max(1, (int) ($item->quantity ?? 1));
                        $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);
                        $commissionRate = 0.0;
                        $commissionRateMode = $item->commission_rate_mode ?? 'percent';

                        if (!empty($item->variant_id)) {
                            $commissionRate = (float) ($variantCommissionRates->get((int) $item->variant_id) ?? 0);
                        }

                        if ($commissionRate <= 0 && !empty($item->product_id)) {
                            $commissionRate = (float) ($productCommissionRates->get((int) $item->product_id) ?? 0);
                        }

                        // Calculate commission based on mode
                        if ($commissionRateMode === 'fixed') {
                            $commissionAmount = $commissionRate; // Fixed amount
                        } else {
                            $commissionAmount = $commissionRate > 0 && $unitPrice > 0
                                ? $unitPrice * $quantity * ($commissionRate / 100)
                                : 0;
                        }

                        if ($commissionAmount <= 0 && Schema::hasColumn('order_items', 'commission_amount') && !empty($item->commission_amount)) {
                            $commissionAmount = (float) $item->commission_amount;
                        }

                        if ($existingCommission) {
                            if ((int) ($existingCommission->user_id ?? 0) !== $commissionRecipientId) {
                                DB::table('commission_users')
                                    ->where('id', $existingCommission->id)
                                    ->update([
                                        'user_id' => $commissionRecipientId,
                                        'updated_at' => $now,
                                    ]);
                            }
                            continue;
                        }

                        if ($commissionAmount > 0) {
                            DB::table('commission_users')->insert([
                                'user_id' => $commissionRecipientId,
                                'order_item_id' => $item->id,
                                'level' => 1,
                                'amount' => $commissionAmount,
                                'status' => 'pending',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                }
            }

            $orderModel = Order::query()->find($id);
            if ($orderModel instanceof Order) {
                if (Schema::hasColumn('orders', 'user_id') && !empty($order->user_id)) {
                    $orderUser = User::query()->find($order->user_id);

                    app(TicketRewardService::class)->awardForCompletedOrder(
                        $orderUser,
                        $orderModel,
                        (float) ($orderModel->final_amount ?? $orderModel->total_amount ?? $orderModel->total ?? 0)
                    );
                }

                app(StoreTicketTransactionService::class)->recordOrderConsumption($orderModel);
                app(StoreCreditService::class)->settlePrepaidDeliveredOrder($orderModel->fresh() ?? $orderModel);

                $commissionService = app(AffiliateCommissionService::class);
                $commissionOrder = $orderModel->fresh(['items.product', 'items.variant']) ?? $orderModel->load(['items.product', 'items.variant']);
                $commissionService->syncOrderCommissions($commissionOrder);
                $commissionService->syncOrderLifecycle($commissionOrder->fresh(['items.product', 'items.variant']) ?? $commissionOrder);
                $commissionService->settleLegacyPendingCommissions($commissionOrder);
            }

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'delivered';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = (in_array(strtoupper(trim($order->payment_method ?? '')), ['CASH', 'COD']))
                        ? 'cash_payment_received'
                        : 'store_admin_mark_delivered';
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }
                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            DB::commit();

            UpgradeStoreCreditLevelJob::dispatch((string) $store->id);

            // Broadcast order status update for real-time reconciliation updates
            OrderStatusUpdated::dispatch(
                $id,
                $order->status,
                'delivered',
                $store->id,
                [
                    'user_id' => $order->user_id ?? null,
                    'order_code' => $order->order_code ?? null,
                    'final_amount' => $orderModel?->final_amount ?? $order->final_amount ?? 0,
                    'payment_method' => $order->payment_method ?? null,
                    'delivered_at' => $now->toIso8601String(),
                ]
            );

            // Local event dispatch for backward compatibility
            Event::dispatch('order.updated', [
                'order_id' => $id,
                'order_code' => $order->order_code ?? null,
                'store_id' => $store->id,
                'old_status' => $order->status,
                'new_status' => 'delivered',
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
                'notify_channels' => ['mini_app', 'zalo_oa'],
                'allow_review' => true,
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'delivered',
                    'order_delivered'
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (delivered).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'order_code' => $order->order_code ?? null,
                    'status' => 'delivered',
                    'delivered_at' => $now->toIso8601String(),
                    'message' => 'Cap nhat trang thai don hang thanh cong.',
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Mark Delivered Failed: ' . $e->getMessage(), [
                'order_id' => $id,
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('MARK_DELIVERED_FAILED', 'Khong the cap nhat trang thai delivered vao luc nay.', 500, true, true);
        }
    }

    public function markReturned(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            if ($currentStatus === 'returned') {
                DB::rollBack();
                return response()->json([
                    'data' => [
                        'id' => $id,
                        'order_code' => $order->order_code ?? null,
                        'status' => 'returned',
                        'returned_at' => $this->toIso($order->returned_at ?? null),
                        'message' => 'Cap nhat trang thai don hang thanh cong.',
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            if ($currentStatus !== 'delivered') {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi co the chuyen don hang tu delivered sang returned.', 422, true, true, [
                    'current_status' => $order->status,
                    'required_status' => 'delivered',
                ]);
            }

            $now = now();
            $updatePayload = ['status' => 'returned'];
            if (Schema::hasColumn('orders', 'returned_at')) {
                $updatePayload['returned_at'] = $now;
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                $updatePayload['updated_at'] = $now;
            }

            DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($updatePayload);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'returned';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = 'store_admin_mark_returned';
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }
                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            $commissionOrder = \App\Models\Order::query()->with(['items.product', 'items.variant'])->find($id);
            if ($commissionOrder instanceof \App\Models\Order) {
                $commissionService = app(\App\Services\AffiliateCommissionService::class);
                $commissionService->reverseLegacyPaidCommissions($commissionOrder, 'Đơn hàng được đánh dấu hoàn trả (returned).');
                $commissionService->syncOrderLifecycle($commissionOrder, 'Đơn hàng được đánh dấu hoàn trả (returned).');
            }

            DB::commit();

            Event::dispatch('order.updated', [
                'order_id' => $id,
                'order_code' => $order->order_code ?? null,
                'store_id' => $store->id,
                'old_status' => $order->status,
                'new_status' => 'returned',
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
                'notify_channels' => ['mini_app', 'zalo_oa'],
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'returned',
                    'order_returned'
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (returned).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'order_code' => $order->order_code ?? null,
                    'status' => 'returned',
                    'returned_at' => $now->toIso8601String(),
                    'message' => 'Cap nhat trang thai don hang thanh cong.',
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('MARK_RETURNED_FAILED', 'Khong the cap nhat trang thai returned vao luc nay.', 500, true, true);
        }
    }

    public function resend(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $now = now();
            $newStatus = 'paid_pending_call';
            $updatePayload = ['status' => $newStatus];

            $columns = Schema::getColumnListing('orders');
            $fieldsToClear = ['confirmed_at', 'preparing_at', 'ready_at', 'picked_up_at', 'delivered_at', 'returned_at', 'cancelled_at'];
            foreach ($fieldsToClear as $field) {
                if (in_array($field, $columns)) {
                    $updatePayload[$field] = null;
                }
            }

            if (in_array('updated_at', $columns)) {
                $updatePayload['updated_at'] = $now;
            }

            DB::table('orders')->where('id', $id)->where('store_id', $store->id)->update($updatePayload);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = $newStatus;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = 'store_admin_resend';
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }

                DB::table('order_status_history')->insert($historyPayload);
            }

            DB::commit();

            return response()->json([
                'data' => [
                    'id' => $id,
                    'status' => $newStatus,
                    'updated_at' => $now->toIso8601String(),
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Order Resend Failed: ' . $e->getMessage(), [
                'order_id' => $id,
                'exception' => $e
            ]);
            return $this->errorResponse('ORDER_RESEND_FAILED', 'Khong the gui lai don hang vao luc nay.', 500, true, true);
        }
    }

    public function cancelOrder(Request $request, string $id): JsonResponse
    {
        return $this->handleOrderCancellation($request, $id);
    }

    public function rejectionDetails(Request $request, string $id): JsonResponse
    {
        return $this->handleOrderCancellation($request, $id);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->handleOrderCancellation($request, $id, 'reject');
    }

    public function cancelWithRefund(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $payload = $this->resolveCancellationPayload($request);

        $validator = Validator::make($payload, [
            'reason_code' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500', 'required_without:reason_text'],
            'reason_text' => ['nullable', 'string', 'max:500', 'required_without:note'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Du lieu dau vao khong hop le.',
                        'details' => $validator->errors()->toArray(),
                    ]
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $reasonCode = strtoupper(trim((string) ($validated['reason_code'] ?? '')));
        $note = trim((string) ($validated['note'] ?? $validated['reason_text'] ?? ''));
        $combinedReason = '[' . $reasonCode . '] - ' . $note;

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            if (in_array($currentStatus, ['canceled', 'cancelled'], true)) {
                DB::rollBack();

                return response()->json([
                    'data' => [
                        'id' => $id,
                        'order_code' => $order->order_code ?? null,
                        'status' => 'canceled',
                        'cancel_reason' => $order->cancel_reason ?? null,
                        'cancelled_at' => $this->toIso($order->cancelled_at ?? null),
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            if (!in_array($currentStatus, self::STORE_ADMIN_CANCELLABLE_STATUSES, true)) {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi co the huy don khi don dang o trang thai pending, confirmed hoac preparing.', 422, true, true, [
                    'current_status' => $order->status,
                    'required_status' => implode('|', self::STORE_ADMIN_CANCELLABLE_STATUSES),
                ]);
            }

            app(OrderStockRestorationService::class)->restoreForCancellation((string) $id, $currentStatus);

            $creditReversal = null;
            if ($this->shouldReverseStoreCreditForCancellation($currentStatus)) {
                $creditReversal = $this->reverseStoreCreditForCancellation($id, 'store_admin_cancelled');
            }

            $now = now();
            $orderUpdatePayload = ['status' => 'canceled'];
            if (Schema::hasColumn('orders', 'cancel_reason')) {
                $orderUpdatePayload['cancel_reason'] = $combinedReason;
            }
            if (Schema::hasColumn('orders', 'cancelled_at')) {
                $orderUpdatePayload['cancelled_at'] = $now;
            }
            if (Schema::hasColumn('orders', 'cancelled_by')) {
                $orderUpdatePayload['cancelled_by'] = $user->id;
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                $orderUpdatePayload['updated_at'] = $now;
            }

            DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($orderUpdatePayload);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'canceled';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $user->role ?? 'store_admin';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = 'store_admin_cancel_only: ' . $combinedReason;
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }
                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            $refundResult = $this->refundOrderTickets($id, $order->user_id ?? null, $reasonCode, $note, $now);

            if (!empty($order->parent_order_id)) {
                $this->restoreParentOrderQuantities($id, (string) $order->parent_order_id);
            }

            $commissionOrder = Order::query()->with(['items.product', 'items.variant'])->find($id);
            if ($commissionOrder instanceof Order) {
                $commissionService = app(AffiliateCommissionService::class);
                $commissionService->reverseLegacyPaidCommissions($commissionOrder, $combinedReason);
                $commissionService->syncOrderLifecycle($commissionOrder, $combinedReason);
            }

            DB::commit();

            OrderStatusUpdated::dispatch(
                $id,
                $order->status,
                'canceled',
                $store->id,
                [
                    'order_code' => $order->order_code ?? null,
                    'cancel_reason' => $combinedReason,
                    'cancelled_at' => $now->toIso8601String(),
                    'rejected_by_store' => false,
                ]
            );

            Event::dispatch('order.updated', [
                'order_id' => $id,
                'store_id' => $store->id,
                'old_status' => $order->status,
                'new_status' => 'canceled',
                'reason_code' => $reasonCode,
                'note' => $note,
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
            ]);

            Event::dispatch('ticket.refunded', [
                'order_id' => $id,
                'store_id' => $store->id,
                'user_id' => $refundResult['user_id'] ?? ($order->user_id ?? null),
                'refunded_tickets' => (int) ($refundResult['refunded_tickets'] ?? 0),
                'reason_code' => $reasonCode,
                'note' => $note,
                'refunded_at' => $now->toIso8601String(),
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'canceled',
                    'order_rejected',
                    $combinedReason
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (cancelWithRefund).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'order_code' => $order->order_code ?? null,
                    'status' => 'canceled',
                    'cancel_reason' => $combinedReason,
                    'cancelled_at' => $now->toIso8601String(),
                    'cancelled_by' => $user->id,
                    'refund' => $refundResult,
                    'credit_reversal' => $creditReversal,
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\DomainException $e) {
            DB::rollBack();
            Log::error('Store admin cancel-with-refund wallet sync failed.', [
                'order_id' => $id,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);
            return $this->errorResponse('STORE_CREDIT_REVERSAL_FAILED', 'Khong the dong bo vi cua hang khi huy don. Don hang chua duoc huy de tranh lech so du.', 409, true, true, [
                'order_id' => $id,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Store admin cancel-with-refund failed.', [
                'order_id' => $id,
                'exception' => $e,
            ]);
            return $this->errorResponse('CANCEL_ORDER_FAILED', 'Khong the huy don vao luc nay.', 500, true, true);
        }
    }

    public function refundDetails(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $order = DB::table('orders as o')
            ->where('o.id', $id)
            ->where('o.store_id', $store->id)
            ->first();

        if (!$order) {
            $order = DB::table('orders as o')
                ->where('o.id', $id)
                ->first();

            if ($order) {
                $resolvedStore = $this->resolveAccessibleStoreById($user, (string) ($order->store_id ?? ''));
                if ($resolvedStore) {
                    $store = $resolvedStore;
                } else {
                    $order = null;
                }
            }
        }

        if (!$order) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $refundInfo = $this->buildOrderRefundInfo($id, $order);

        return response()->json([
            'data' => [
                'id' => $id,
                'order_code' => $order->order_code ?? null,
                'refund_info' => $refundInfo,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function buildOrderRefundInfo(string $orderId, object $order, ?array $refundResult = null): array
    {
        $latestRefund = null;
        $refundBankInfo = null;
        if (
            Schema::hasTable('order_returns')
            && Schema::hasColumn('order_returns', 'order_id')
            && Schema::hasColumn('order_returns', 'refund_bank_info')
        ) {
            $returnRow = DB::table('order_returns')
                ->where('order_id', $orderId)
                ->whereNotNull('refund_bank_info')
                ->orderByDesc(Schema::hasColumn('order_returns', 'created_at') ? 'created_at' : 'id')
                ->first(['refund_bank_info']);

            $refundBankInfo = $this->decodeJsonObject($returnRow->refund_bank_info ?? null);
        }

        if (Schema::hasTable('refunds') && Schema::hasColumn('refunds', 'source_id') && Schema::hasColumn('refunds', 'source_type')) {
            $refundQuery = DB::table('refunds')
                ->where('source_id', $orderId)
                ->whereRaw('LOWER(source_type) = ?', ['order']);

            if (Schema::hasColumn('refunds', 'processed_at')) {
                $refundQuery->orderByDesc('processed_at');
            } elseif (Schema::hasColumn('refunds', 'created_at')) {
                $refundQuery->orderByDesc('created_at');
            } else {
                $refundQuery->orderByDesc('id');
            }

            $latestRefund = $refundQuery->first();
        }

        $refundedTickets = (int) ($refundResult['refunded_tickets'] ?? 0);
        if ($refundedTickets <= 0 && $latestRefund && isset($latestRefund->metadata) && is_string($latestRefund->metadata)) {
            $meta = json_decode($latestRefund->metadata, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($meta)) {
                $refundedTickets = (int) ($meta['ticket_count'] ?? 0);
            }
        }

        if (
            $refundedTickets <= 0
            && Schema::hasColumn('ticket_ledger', 'reference_id')
            && Schema::hasColumn('ticket_ledger', 'action_type')
            && Schema::hasColumn('ticket_ledger', 'amount_change')
        ) {
            $refundedTickets = (int) DB::table('ticket_ledger')
                ->where('reference_id', $orderId)
                ->whereIn('action_type', ['REFUND', 'ORDER_REFUND'])
                ->sum('amount_change');
        }

        if ($refundedTickets <= 0 && Schema::hasTable('order_tickets') && Schema::hasColumn('order_tickets', 'order_id')) {
            $refundedTickets = (int) DB::table('order_tickets')->where('order_id', $orderId)->count();
        }

        $refundStatus = $latestRefund->new_status ?? null;
        if ($refundStatus === null) {
            $refundStatus = $refundedTickets > 0 ? 'REFUNDED' : 'NOT_REFUNDED';
        }

        return [
            'is_refunded' => $refundedTickets > 0,
            'status' => $refundStatus,
            'refunded_tickets' => $refundedTickets,
            'refund_code' => $latestRefund->refund_code ?? null,
            'refund_id' => $latestRefund->id ?? null,
            'refund_bank_info' => $refundBankInfo,
            'processed_at' => $this->toIso($latestRefund->processed_at ?? null),
        ];
    }

    private function handleOrderCancellation(Request $request, string $id, string $operation = 'cancel'): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('orders')) {
            return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
        }

        $payload = $this->resolveCancellationPayload($request);

        $validator = Validator::make($payload, [
            'reason_code' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500', 'required_without:reason_text'],
            'reason_text' => ['nullable', 'string', 'max:500', 'required_without:note'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Du lieu dau vao khong hop le.',
                        'details' => $validator->errors()->toArray(),
                    ]
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $reasonCode = strtoupper(trim((string) ($validated['reason_code'] ?? '')));
        $note = trim((string) ($validated['note'] ?? $validated['reason_text'] ?? ''));
        $combinedReason = '[' . $reasonCode . '] - ' . $note;

        try {
            DB::beginTransaction();

            $order = DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang hoac don hang khong thuoc cua hang hien tai.', 404, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($order->status ?? '')));
            if (in_array($currentStatus, ['canceled', 'cancelled'], true)) {
                DB::rollBack();
                $parsedReason = $this->parseCancelReason($order->cancel_reason ?? null);

                return response()->json([
                    'data' => [
                        'id' => $id,
                        'status' => 'canceled',
                        'old_status' => $order->status,
                        'cancel_reason' => $order->cancel_reason ?? null,
                        'cancelled_at' => $this->toIso($order->cancelled_at ?? null),
                        'cancelled_by' => $order->cancelled_by ?? null,
                        'reason_code' => $parsedReason['reason_code'],
                        'note' => $parsedReason['note'],
                        'idempotent' => true,
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            if (!in_array($currentStatus, self::STORE_ADMIN_CANCELLABLE_STATUSES, true)) {
                DB::rollBack();
                return $this->errorResponse('INVALID_ORDER_STATUS', 'Chi co the huy don hang dang o trang thai pending, confirmed hoac preparing.', 422, true, true, [
                    'current_status' => $order->status,
                    'required_status' => implode('|', self::STORE_ADMIN_CANCELLABLE_STATUSES),
                ]);
            }

            app(OrderStockRestorationService::class)->restoreForCancellation((string) $id, $currentStatus);

            $creditReversal = null;
            if ($this->shouldReverseStoreCreditForCancellation($currentStatus)) {
                $creditReversal = $this->reverseStoreCreditForCancellation($id, 'store_admin_cancelled');
            }

            $now = now();
            $orderUpdatePayload = ['status' => 'canceled'];
            if (Schema::hasColumn('orders', 'cancel_reason')) {
                $orderUpdatePayload['cancel_reason'] = $combinedReason;
            }
            if (Schema::hasColumn('orders', 'cancelled_at')) {
                $orderUpdatePayload['cancelled_at'] = $now;
            }
            if (Schema::hasColumn('orders', 'cancelled_by')) {
                $orderUpdatePayload['cancelled_by'] = $user->id;
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                $orderUpdatePayload['updated_at'] = $now;
            }

            DB::table('orders')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($orderUpdatePayload);

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];
                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = $id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $order->status;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'canceled';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $user->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = $operation === 'reject' ? 'admin_store' : ($user->role ?? 'store_admin');
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = ($operation === 'reject' ? 'store_admin_reject: ' : '') . $combinedReason;
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $now;
                }

                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            $refundResult = $this->refundOrderTickets($id, $order->user_id ?? null, $reasonCode, $note, $now);

            // Restore parent order quantities if this is a child order
            if (!empty($order->parent_order_id)) {
                $this->restoreParentOrderQuantities($id, (string) $order->parent_order_id);
            }

            $commissionOrder = \App\Models\Order::query()->with(['items.product', 'items.variant'])->find($id);
            if ($commissionOrder instanceof \App\Models\Order) {
                $commissionService = app(\App\Services\AffiliateCommissionService::class);
                $commissionService->reverseLegacyPaidCommissions($commissionOrder, $combinedReason);
                $commissionService->syncOrderLifecycle($commissionOrder, $combinedReason);
            }

            DB::commit();

            OrderStatusUpdated::dispatch(
                $id,
                $order->status,
                'canceled',
                $store->id,
                [
                    'order_code' => $order->order_code ?? null,
                    'cancel_reason' => $combinedReason,
                    'cancelled_at' => $now->toIso8601String(),
                    'rejected_by_store' => $operation === 'reject',
                ]
            );

            Event::dispatch('order.updated', [
                'order_id' => $id,
                'store_id' => $store->id,
                'old_status' => $order->status,
                'new_status' => 'canceled',
                'reason_code' => $reasonCode,
                'note' => $note,
                'changed_by' => $user->id,
                'changed_at' => $now->toIso8601String(),
            ]);

            Event::dispatch('ticket.refunded', [
                'order_id' => $id,
                'store_id' => $store->id,
                'user_id' => $refundResult['user_id'] ?? ($order->user_id ?? null),
                'refunded_tickets' => (int) ($refundResult['refunded_tickets'] ?? 0),
                'reason_code' => $reasonCode,
                'note' => $note,
                'refunded_at' => $now->toIso8601String(),
            ]);

            try {
                $this->pushCustomerOrderRealtimeNotification(
                    $store,
                    $order,
                    'canceled',
                    'order_rejected',
                    $combinedReason
                );
            } catch (\Throwable $e) {
                Log::error('Customer order realtime notification failed (handleOrderCancellation).', [
                    'order_id' => $id,
                    'user_id' => (string) ($order->user_id ?? ''),
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $id,
                    'status' => 'canceled',
                    'old_status' => $order->status,
                    'cancel_reason' => $combinedReason,
                    'cancelled_at' => $now->toIso8601String(),
                    'cancelled_by' => $user->id,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'rejected_by_store' => $operation === 'reject',
                    'refund' => $refundResult,
                    'credit_reversal' => $creditReversal,
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\DomainException $e) {
            DB::rollBack();
            Log::error('Store admin order cancellation wallet sync failed.', [
                'order_id' => $id,
                'operation' => $operation,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);
            return $this->errorResponse('STORE_CREDIT_REVERSAL_FAILED', 'Khong the dong bo vi cua hang khi huy don. Don hang chua duoc huy de tranh lech so du.', 409, true, true, [
                'order_id' => $id,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Store admin order cancellation failed.', [
                'order_id' => $id,
                'operation' => $operation,
                'exception' => $e,
            ]);
            return $this->errorResponse('CANCEL_ORDER_FAILED', 'Khong the huy don hang vao luc nay.', 500, true, true);
        }
    }

    private function shouldReverseStoreCreditForCancellation(string $status): bool
    {
        return in_array(mb_strtolower(trim($status)), self::STORE_ADMIN_CREDIT_REVERSAL_STATUSES, true);
    }

    private function reverseStoreCreditForCancellation(string $orderId, string $reason): array
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();

        if (!$order instanceof Order) {
            throw new \DomainException('order_missing_for_credit_reversal');
        }

        try {
            $result = app(StoreCreditService::class)->reverseAcceptedOrder($order, $reason);
        } catch (\Throwable $e) {
            throw new \DomainException('wallet_reverse_exception', 0, $e);
        }

        if (($result['reversed'] ?? false) === true || ($result['idempotent'] ?? false) === true) {
            return $result;
        }

        throw new \DomainException((string) ($result['reason'] ?? 'credit_reversal_not_applied'));
    }

    private function resolveCancellationPayload(Request $request): array
    {
        $payload = $request->all();

        if (empty($payload)) {
            $decoded = json_decode((string) $request->getContent(), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        $reasonText = trim((string) ($payload['reason_text'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $reasonCode = trim((string) ($payload['reason_code'] ?? ''));

        if ($reasonText === '' && $reason !== '') {
            $payload['reason_text'] = $reason;
            $reasonText = $reason;
        }

        if ($reasonText === '' && $note === '') {
            $fallbackReasonText = $this->resolveCancellationReasonText($reasonCode);
            $payload['reason_text'] = $fallbackReasonText;
            $payload['note'] = $fallbackReasonText;
            $reasonText = $fallbackReasonText;
            $note = $fallbackReasonText;
        }

        if ($note === '' && $reasonText !== '') {
            $payload['note'] = $reasonText;
        }

        return $payload;
    }

    private function resolveCancellationReasonText(?string $reasonCode): string
    {
        switch (mb_strtoupper(trim((string) $reasonCode))) {
            case 'OUT_OF_STOCK':
                return 'Het mon';
            case 'STORE_BUSY':
            case 'OVERLOADED':
                return 'Qua tai don hang';
            case 'OUTSIDE_SERVICE_AREA':
            case 'OUT_OF_AREA':
                return 'Ngoai khu vuc giao hang';
            case 'CUSTOMER_RISK':
                return 'Khach hang co lich su huy/hoan don nhieu lan';
            default:
                return 'Don hang duoc huy boi cua hang.';
        }
    }

    private function refundOrderTickets(string $orderId, mixed $fallbackUserId, string $reasonCode, string $note, mixed $now): array
    {
        $result = [
            'refunded_tickets' => 0,
            'already_refunded' => false,
            'user_id' => $fallbackUserId,
            'user_balance_before' => null,
            'user_balance_after' => null,
        ];

        if (!Schema::hasTable('order_tickets') || !Schema::hasColumn('order_tickets', 'order_id')) {
            return $result;
        }

        $orderTicketSelect = [];
        if (Schema::hasColumn('order_tickets', 'ticket_id')) {
            $orderTicketSelect[] = 'ticket_id';
        }
        if (Schema::hasColumn('order_tickets', 'user_id')) {
            $orderTicketSelect[] = 'user_id';
        }

        if (empty($orderTicketSelect)) {
            return $result;
        }

        $orderTicketRows = DB::table('order_tickets')
            ->where('order_id', $orderId)
            ->select($orderTicketSelect)
            ->get();

        $ticketIds = collect($orderTicketRows)
            ->pluck('ticket_id')
            ->filter()
            ->map(static fn($ticketId) => (string) $ticketId)
            ->unique()
            ->values()
            ->all();

        if ($result['user_id'] === null && Schema::hasColumn('order_tickets', 'user_id')) {
            $result['user_id'] = $orderTicketRows->pluck('user_id')->filter()->first();
        }

        if (empty($ticketIds) || !Schema::hasTable('tickets')) {
            return $result;
        }

        if (
            Schema::hasColumn('ticket_ledger', 'reference_id')
            && Schema::hasColumn('ticket_ledger', 'action_type')
        ) {
            $result['already_refunded'] = DB::table('ticket_ledger')
                ->where('reference_id', $orderId)
                ->whereIn('action_type', ['REFUND', 'ORDER_REFUND'])
                ->exists();
        }

        $ticketUpdatePayload = [];
        if (Schema::hasColumn('tickets', 'status')) {
            $ticketUpdatePayload['status'] = 'AVAILABLE';
        }
        if (Schema::hasColumn('tickets', 'used_in_order_id')) {
            $ticketUpdatePayload['used_in_order_id'] = null;
        }
        if (Schema::hasColumn('tickets', 'used_at')) {
            $ticketUpdatePayload['used_at'] = null;
        }
        if (Schema::hasColumn('tickets', 'source_type')) {
            $ticketUpdatePayload['source_type'] = 'ORDER_REFUND';
        }
        if (Schema::hasColumn('tickets', 'source_id')) {
            $ticketUpdatePayload['source_id'] = $orderId;
        }
        if (Schema::hasColumn('tickets', 'updated_at')) {
            $ticketUpdatePayload['updated_at'] = $now;
        }

        if (!empty($ticketUpdatePayload)) {
            DB::table('tickets')->whereIn('id', $ticketIds)->update($ticketUpdatePayload);
        }

        if ($result['already_refunded']) {
            return $result;
        }

        $refundedCount = count($ticketIds);
        $result['refunded_tickets'] = $refundedCount;

        if ($refundedCount <= 0 || $result['user_id'] === null || !Schema::hasTable('user_balances')) {
            return $result;
        }

        $hasAvailableBalance = Schema::hasColumn('user_balances', 'available_balance');
        $hasLockedBalance = Schema::hasColumn('user_balances', 'locked_balance');
        $hasUsedBalance = Schema::hasColumn('user_balances', 'used_balance');
        $hasExpiredBalance = Schema::hasColumn('user_balances', 'expired_balance');
        $hasTotalLifetimePurchased = Schema::hasColumn('user_balances', 'total_lifetime_purchased');
        $hasTotalLifetimeUsed = Schema::hasColumn('user_balances', 'total_lifetime_used');
        $hasLastUpdated = Schema::hasColumn('user_balances', 'last_updated');
        $hasVersion = Schema::hasColumn('user_balances', 'version');

        $balance = DB::table('user_balances')->where('user_id', $result['user_id'])->lockForUpdate()->first();

        if (!$balance) {
            $insertPayload = ['user_id' => $result['user_id']];
            if ($hasAvailableBalance) {
                $insertPayload['available_balance'] = 0;
            }
            if ($hasLockedBalance) {
                $insertPayload['locked_balance'] = 0;
            }
            if ($hasUsedBalance) {
                $insertPayload['used_balance'] = 0;
            }
            if ($hasExpiredBalance) {
                $insertPayload['expired_balance'] = 0;
            }
            if ($hasTotalLifetimePurchased) {
                $insertPayload['total_lifetime_purchased'] = 0;
            }
            if ($hasTotalLifetimeUsed) {
                $insertPayload['total_lifetime_used'] = 0;
            }
            if ($hasLastUpdated) {
                $insertPayload['last_updated'] = $now;
            }
            if ($hasVersion) {
                $insertPayload['version'] = 0;
            }

            DB::table('user_balances')->insert($insertPayload);
            $balance = DB::table('user_balances')->where('user_id', $result['user_id'])->lockForUpdate()->first();
        }

        $balanceBefore = (int) ($balance->available_balance ?? 0);
        $balanceAfter = $balanceBefore + $refundedCount;
        $result['user_balance_before'] = $balanceBefore;
        $result['user_balance_after'] = $balanceAfter;

        $balanceUpdatePayload = [];
        if ($hasAvailableBalance) {
            $balanceUpdatePayload['available_balance'] = $balanceAfter;
        }
        if ($hasUsedBalance) {
            $balanceUpdatePayload['used_balance'] = max(0, (int) ($balance->used_balance ?? 0) - $refundedCount);
        }
        if ($hasLastUpdated) {
            $balanceUpdatePayload['last_updated'] = $now;
        }
        if ($hasVersion) {
            $balanceUpdatePayload['version'] = (int) ($balance->version ?? 0) + 1;
        }

        if (!empty($balanceUpdatePayload)) {
            DB::table('user_balances')->where('user_id', $result['user_id'])->update($balanceUpdatePayload);
        }

        $hasLedgerId = Schema::hasColumn('ticket_ledger', 'id');
        $hasLedgerTicketId = Schema::hasColumn('ticket_ledger', 'ticket_id');
        $hasLedgerUserId = Schema::hasColumn('ticket_ledger', 'user_id');
        $hasLedgerActionType = Schema::hasColumn('ticket_ledger', 'action_type');
        $hasLedgerAmountChange = Schema::hasColumn('ticket_ledger', 'amount_change');
        $hasLedgerBalanceBefore = Schema::hasColumn('ticket_ledger', 'balance_before');
        $hasLedgerBalanceAfter = Schema::hasColumn('ticket_ledger', 'balance_after');
        $hasLedgerReferenceId = Schema::hasColumn('ticket_ledger', 'reference_id');
        $hasLedgerReferenceType = Schema::hasColumn('ticket_ledger', 'reference_type');
        $hasLedgerMetadata = Schema::hasColumn('ticket_ledger', 'metadata');
        $hasLedgerCreatedAt = Schema::hasColumn('ticket_ledger', 'created_at');

        $runningBalance = $balanceBefore;

        foreach ($ticketIds as $ticketId) {
            $entryBalanceBefore = $runningBalance;
            $entryBalanceAfter = $entryBalanceBefore === null ? null : ((int) $entryBalanceBefore + 1);

            $ledgerPayload = [];
            if ($hasLedgerId) {
                $ledgerPayload['id'] = (string) Str::uuid();
            }
            if ($hasLedgerTicketId) {
                $ledgerPayload['ticket_id'] = $ticketId;
            }
            if ($hasLedgerUserId) {
                $ledgerPayload['user_id'] = $result['user_id'];
            }
            if ($hasLedgerActionType) {
                $ledgerPayload['action_type'] = 'REFUND';
            }
            if ($hasLedgerAmountChange) {
                $ledgerPayload['amount_change'] = 1;
            }
            if ($hasLedgerBalanceBefore) {
                $ledgerPayload['balance_before'] = $entryBalanceBefore;
            }
            if ($hasLedgerBalanceAfter) {
                $ledgerPayload['balance_after'] = $entryBalanceAfter;
            }
            if ($hasLedgerReferenceId) {
                $ledgerPayload['reference_id'] = $orderId;
            }
            if ($hasLedgerReferenceType) {
                $ledgerPayload['reference_type'] = 'ORDER';
            }
            if ($hasLedgerMetadata) {
                $ledgerPayload['metadata'] = json_encode([
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'ticket_id' => $ticketId,
                    'ticket_count' => 1,
                ], JSON_UNESCAPED_UNICODE);
            }
            if ($hasLedgerCreatedAt) {
                $ledgerPayload['created_at'] = $now;
            }

            if (!empty($ledgerPayload)) {
                app(\App\Services\TicketLedgerService::class)->record($ledgerPayload);
            }

            $runningBalance = $entryBalanceAfter;
        }

        return $result;
    }

    private function loadOrderItemBrief(array $orderIds): array
    {
        if (empty($orderIds) || !Schema::hasTable('order_items')) {
            return [];
        }

        $hasItemName = Schema::hasColumn('order_items', 'item_name');
        $hasProductName = Schema::hasColumn('order_items', 'product_name');
        $hasQuantity = Schema::hasColumn('order_items', 'quantity');
        $hasUnitPrice = Schema::hasColumn('order_items', 'unit_price');
        $hasPrice = Schema::hasColumn('order_items', 'price');

        $rows = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->select([
                'order_id',
                DB::raw($hasItemName ? 'item_name' : 'NULL as item_name'),
                DB::raw($hasProductName ? 'product_name' : 'NULL as product_name'),
                DB::raw($hasQuantity ? 'quantity' : '1 as quantity'),
                DB::raw($hasUnitPrice ? 'unit_price' : 'NULL as unit_price'),
                DB::raw($hasPrice ? 'price' : 'NULL as price'),
            ])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $orderId = (string) ($row->order_id ?? '');
            if ($orderId === '') {
                continue;
            }

            $price = isset($row->unit_price) ? (float) $row->unit_price : null;
            if ($price === null && isset($row->price)) {
                $price = (float) $row->price;
            }

            $map[$orderId][] = [
                'name' => $row->item_name ?? $row->product_name ?? null,
                'quantity' => isset($row->quantity) ? (int) $row->quantity : 1,
                'price' => $price,
            ];
        }

        return $map;
    }

    private function loadOrderItems(string $orderId): array
    {
        if (!Schema::hasTable('order_items')) {
            return [];
        }

        $hasMenuItemId = Schema::hasColumn('order_items', 'menu_item_id');
        $hasProductId = Schema::hasColumn('order_items', 'product_id');
        $hasVariantId = Schema::hasColumn('order_items', 'variant_id');
        $hasItemName = Schema::hasColumn('order_items', 'item_name');
        $hasProductName = Schema::hasColumn('order_items', 'product_name');
        $hasQuantity = Schema::hasColumn('order_items', 'quantity');
        $hasUnitPrice = Schema::hasColumn('order_items', 'unit_price');
        $hasLegacyPrice = Schema::hasColumn('order_items', 'price');
        $hasTotalPrice = Schema::hasColumn('order_items', 'total_price');
        $hasSpecialInstructions = Schema::hasColumn('order_items', 'special_instructions');

        $query = DB::table('order_items as oi')->where('oi.order_id', $orderId);

        $hasProductsTable = Schema::hasTable('products');
        $hasThumbnailColumn = $hasProductsTable && Schema::hasColumn('products', 'thumbnail');

        if ($hasProductsTable && $hasProductId) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }

        $rows = $query->select([
            'oi.id',
            DB::raw($hasMenuItemId ? 'oi.menu_item_id' : 'NULL as menu_item_id'),
            DB::raw($hasProductId ? 'oi.product_id' : 'NULL as product_id'),
            DB::raw($hasVariantId ? 'oi.variant_id' : 'NULL as variant_id'),
            DB::raw($hasItemName ? 'oi.item_name' : 'NULL as item_name'),
            DB::raw($hasProductName ? 'oi.product_name' : 'NULL as product_name'),
            DB::raw($hasQuantity ? 'oi.quantity' : '1 as quantity'),
            DB::raw($hasUnitPrice ? 'oi.unit_price' : 'NULL as unit_price'),
            DB::raw($hasLegacyPrice ? 'oi.price' : 'NULL as price'),
            DB::raw($hasTotalPrice ? 'oi.total_price' : 'NULL as total_price'),
            DB::raw($hasSpecialInstructions ? 'oi.special_instructions' : 'NULL as special_instructions'),
            DB::raw($hasThumbnailColumn ? 'p.thumbnail as product_thumbnail' : 'NULL as product_thumbnail'),
        ])->get();

        $items = [];

        foreach ($rows as $row) {
            $unitPrice = isset($row->unit_price) ? (float) $row->unit_price : null;
            if ($unitPrice === null && isset($row->price)) {
                $unitPrice = (float) $row->price;
            }

            $quantity = isset($row->quantity) ? (int) $row->quantity : 1;
            $totalPrice = isset($row->total_price) ? (float) $row->total_price : null;
            if ($totalPrice === null && $unitPrice !== null) {
                $totalPrice = $unitPrice * max($quantity, 1);
            }

            $items[] = [
                'id' => $row->id,
                'menu_item_id' => $row->menu_item_id ?? null,
                'product_id' => isset($row->product_id) && $row->product_id !== null ? (int) $row->product_id : null,
                'variant_id' => isset($row->variant_id) && $row->variant_id !== null ? (int) $row->variant_id : null,
                'name' => $row->item_name ?? $row->product_name ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'special_instructions' => $row->special_instructions ?? null,
                'product_image_url' => $row->product_thumbnail ?? null,
            ];
        }

        return $items;
    }

    /**
     * Batched equivalent of resolveOrderTicketsUsed() for a set of order ids.
     * Returns a map keyed by order id => tickets used, computed in at most two
     * grouped queries instead of 1-2 queries per order (fixes N+1).
     *
     * @param  array<int|string>  $orderIds
     * @param  array<string, int|null>  $fallbacks  order id => total_tickets_used fallback
     * @return array<string, int>
     */
    private function resolveOrderTicketsUsedMap(array $orderIds, array $fallbacks = []): array
    {
        $orderIds = array_values(array_filter(array_map('strval', $orderIds), fn($id) => $id !== ''));
        if (empty($orderIds)) {
            return [];
        }

        $transactionSums = $this->sumOrderTicketTransactionsForOrders($orderIds);
        $itemSums = null; // computed lazily only if needed

        $map = [];
        foreach ($orderIds as $orderId) {
            $fromTransactions = (int) ($transactionSums[$orderId] ?? 0);
            if ($fromTransactions > 0) {
                $map[$orderId] = $fromTransactions;
                continue;
            }

            $fallback = $fallbacks[$orderId] ?? null;
            if ($fallback !== null && (int) $fallback > 0) {
                $map[$orderId] = (int) $fallback;
                continue;
            }

            if ($itemSums === null) {
                $itemSums = $this->sumOrderTicketsFromItemsForOrders($orderIds);
            }

            $map[$orderId] = (int) ($itemSums[$orderId] ?? 0);
        }

        return $map;
    }

    /**
     * @param  array<string>  $orderIds
     * @return array<string, int>
     */
    private function sumOrderTicketTransactionsForOrders(array $orderIds): array
    {
        if (empty($orderIds) || !Schema::hasTable('store_ticket_transactions')) {
            return [];
        }

        if (!Schema::hasColumn('store_ticket_transactions', 'order_id') || !Schema::hasColumn('store_ticket_transactions', 'tickets_consumed')) {
            return [];
        }

        return DB::table('store_ticket_transactions')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(tickets_consumed) as aggregate_total')
            ->pluck('aggregate_total', 'order_id')
            ->map(fn($value) => (int) $value)
            ->all();
    }

    /**
     * @param  array<string>  $orderIds
     * @return array<string, int>
     */
    private function sumOrderTicketsFromItemsForOrders(array $orderIds): array
    {
        if (empty($orderIds) || !Schema::hasTable('order_items') || !Schema::hasColumn('order_items', 'quantity')) {
            return [];
        }

        $query = DB::table('order_items as oi')->whereIn('oi.order_id', $orderIds);

        $hasVariantReward = Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'ticket_reward_quantity');
        $hasProductReward = Schema::hasTable('products') && Schema::hasColumn('products', 'ticket_reward_quantity');

        if ($hasVariantReward) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        if ($hasProductReward) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }

        if ($hasVariantReward && $hasProductReward) {
            $rewardExpr = 'COALESCE(pv.ticket_reward_quantity, p.ticket_reward_quantity, 0)';
        } elseif ($hasVariantReward) {
            $rewardExpr = 'COALESCE(pv.ticket_reward_quantity, 0)';
        } elseif ($hasProductReward) {
            $rewardExpr = 'COALESCE(p.ticket_reward_quantity, 0)';
        } else {
            $rewardExpr = '0';
        }

        return $query
            ->groupBy('oi.order_id')
            ->selectRaw("oi.order_id, SUM(COALESCE(oi.quantity, 0) * {$rewardExpr}) as aggregate_total")
            ->pluck('aggregate_total', 'oi.order_id')
            ->map(fn($value) => (int) $value)
            ->all();
    }

    private function resolveOrderTicketsUsed(string $orderId, ?int $fallback = null): int
    {
        $ticketsFromTransactions = $this->sumOrderTicketTransactions($orderId);
        if ($ticketsFromTransactions > 0) {
            return $ticketsFromTransactions;
        }

        if ($fallback !== null && $fallback > 0) {
            return $fallback;
        }

        return $this->sumOrderTicketsFromItems($orderId);
    }

    private function sumOrderTicketTransactions(string $orderId): int
    {
        if (!Schema::hasTable('store_ticket_transactions')) {
            return 0;
        }

        if (!Schema::hasColumn('store_ticket_transactions', 'order_id') || !Schema::hasColumn('store_ticket_transactions', 'tickets_consumed')) {
            return 0;
        }

        return (int) DB::table('store_ticket_transactions')
            ->where('order_id', $orderId)
            ->sum('tickets_consumed');
    }

    private function sumOrderTicketsFromItems(string $orderId): int
    {
        if (!Schema::hasTable('order_items') || !Schema::hasColumn('order_items', 'quantity')) {
            return 0;
        }

        $query = DB::table('order_items as oi')->where('oi.order_id', $orderId);

        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'ticket_reward_quantity')) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'ticket_reward_quantity')) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }

        $rows = $query->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw(
                Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'ticket_reward_quantity') && Schema::hasTable('products') && Schema::hasColumn('products', 'ticket_reward_quantity')
                ? 'COALESCE(pv.ticket_reward_quantity, p.ticket_reward_quantity, 0) as ticket_reward_quantity'
                : (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'ticket_reward_quantity')
                    ? 'COALESCE(pv.ticket_reward_quantity, 0) as ticket_reward_quantity'
                    : (Schema::hasTable('products') && Schema::hasColumn('products', 'ticket_reward_quantity')
                        ? 'COALESCE(p.ticket_reward_quantity, 0) as ticket_reward_quantity'
                        : '0 as ticket_reward_quantity'))
            )
            ->get();

        $totalTickets = 0;
        foreach ($rows as $row) {
            $quantity = max(0, (int) ($row->quantity ?? 0));
            $rewardPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));
            $totalTickets += $quantity * $rewardPerUnit;
        }

        return $totalTickets;
    }

    private function loadOrderTicketCodes(string $orderId): array
    {
        if (!Schema::hasTable('order_tickets') || !Schema::hasTable('tickets') || !Schema::hasColumn('tickets', 'ticket_code')) {
            return [];
        }

        return DB::table('order_tickets as ot')
            ->join('tickets as t', 't.id', '=', 'ot.ticket_id')
            ->where('ot.order_id', $orderId)
            ->orderByDesc('ot.used_at')
            ->pluck('t.ticket_code')
            ->filter()
            ->values()
            ->all();
    }

    private function loadOrderTimeline(string $orderId, object $order): array
    {
        $historyEvents = [];

        if (Schema::hasTable('order_status_history')) {
            $query = DB::table('order_status_history as osh')->where('osh.order_id', $orderId);
            $canJoinUser = Schema::hasTable('users') && Schema::hasColumn('order_status_history', 'changed_by') && Schema::hasColumn('users', 'id');
            if ($canJoinUser) {
                $query->leftJoin('users as u', 'u.id', '=', 'osh.changed_by');
            }

            $actorNameExpr = "''";
            if ($canJoinUser && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
                $actorNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
            } elseif ($canJoinUser && Schema::hasColumn('users', 'full_name')) {
                $actorNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
            } elseif ($canJoinUser && Schema::hasColumn('users', 'name')) {
                $actorNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
            }

            $rows = $query
                ->orderBy('osh.created_at')
                ->select([
                    'osh.id',
                    'osh.old_status',
                    'osh.new_status',
                    'osh.changed_by',
                    'osh.changed_by_role',
                    'osh.reason',
                    'osh.created_at',
                    DB::raw($actorNameExpr . ' as actor_name'),
                ])
                ->get();

            $historyEvents = $rows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'old_status' => $row->old_status,
                    'new_status' => $row->new_status,
                    'reason' => $row->reason,
                    'changed_by' => [
                        'id' => $row->changed_by,
                        'name' => $row->actor_name ?: null,
                        'role' => $row->changed_by_role ?? null,
                    ],
                    'created_at' => $this->toIso($row->created_at ?? null),
                    'source' => 'history',
                ];
            })->values()->all();
        }

        $historyByStatus = [];
        foreach ($historyEvents as $event) {
            $statusKey = mb_strtolower(trim((string) ($event['new_status'] ?? '')));
            if ($statusKey === 'cancelled') {
                $statusKey = 'canceled';
            }
            if ($statusKey === '' || isset($historyByStatus[$statusKey])) {
                continue;
            }

            $historyByStatus[$statusKey] = $event;
        }

        $milestones = [
            ['status' => 'pending', 'field' => 'created_at', 'fallback_reason' => 'order_created'],
            ['status' => 'confirmed', 'field' => 'confirmed_at', 'fallback_reason' => 'order_confirmed'],
            ['status' => 'preparing', 'field' => 'preparing_at', 'fallback_reason' => 'preparation_started'],
            ['status' => 'ready', 'field' => 'ready_at', 'fallback_reason' => 'order_ready'],
            ['status' => 'delivered', 'field' => 'delivered_at', 'fallback_reason' => (in_array(strtoupper(trim($order->payment_method ?? '')), ['CASH', 'COD'])) ? 'cash_payment_received' : 'order_delivered'],
            ['status' => 'returned', 'field' => 'returned_at', 'fallback_reason' => 'order_returned'],
            ['status' => 'timeout', 'field' => 'timeout_at', 'fallback_reason' => 'order_timeout'],
            ['status' => 'canceled', 'field' => 'cancelled_at', 'fallback_reason' => 'order_cancelled'],
        ];

        $milestoneEvents = [];
        foreach ($milestones as $milestone) {
            $rawAt = $order->{$milestone['field']} ?? null;
            $createdAt = $this->toIso($rawAt);
            if ($createdAt === null) {
                continue;
            }

            $statusKey = $milestone['status'];
            $historyEvent = $historyByStatus[$statusKey] ?? null;

            $reason = $historyEvent['reason'] ?? null;
            if (($reason === null || trim((string) $reason) === '') && $statusKey === 'canceled') {
                $reason = $order->cancel_reason ?? null;
            }
            if ($reason === null || trim((string) $reason) === '') {
                $reason = $milestone['fallback_reason'];
            }

            $milestoneEvents[] = [
                'id' => 'ms_' . $statusKey,
                'old_status' => null,
                'new_status' => $statusKey,
                'reason' => $reason,
                'changed_by' => $historyEvent['changed_by'] ?? [
                    'id' => null,
                    'name' => null,
                    'role' => null,
                ],
                'created_at' => $createdAt,
                'source' => $historyEvent ? 'history+milestone' : 'milestone',
            ];
        }

        $events = [];
        $seenMilestoneStatus = [];
        foreach ($milestoneEvents as $event) {
            $statusKey = mb_strtolower(trim((string) ($event['new_status'] ?? '')));
            if ($statusKey !== '') {
                $seenMilestoneStatus[$statusKey] = true;
            }
            $events[] = $event;
        }

        foreach ($historyEvents as $event) {
            $statusKey = mb_strtolower(trim((string) ($event['new_status'] ?? '')));
            if ($statusKey !== '' && isset($seenMilestoneStatus[$statusKey])) {
                continue;
            }
            $events[] = $event;
        }

        usort($events, function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['created_at'] ?? ''));
            $bTime = strtotime((string) ($b['created_at'] ?? ''));
            return $aTime <=> $bTime;
        });

        return array_values($events);
    }

    private function loadLatestDispute(string $orderId): ?array
    {
        if (!Schema::hasTable('complaints') || !Schema::hasColumn('complaints', 'order_id')) {
            return null;
        }

        $query = DB::table('complaints')->where('order_id', $orderId);
        if (Schema::hasColumn('complaints', 'created_at')) {
            $query->orderByDesc('created_at');
        } elseif (Schema::hasColumn('complaints', 'updated_at')) {
            $query->orderByDesc('updated_at');
        } else {
            $query->orderByDesc('id');
        }

        $row = $query->first();
        if (!$row) {
            return null;
        }

        return [
            'id' => $row->id ?? null,
            'complaint_code' => $row->complaint_code ?? null,
            'status' => $row->status ?? null,
            'title' => $row->title ?? null,
            'description' => $row->description ?? null,
            'severity' => $row->severity ?? null,
            'resolution_note' => $row->resolution_note ?? null,
            'resolved_at' => $this->toIso($row->resolved_at ?? null),
            'created_at' => $this->toIso($row->created_at ?? null),
        ];
    }

    private function applySort(Builder $query, string $sort): void
    {
        $sortMap = [
            'created_at' => ['o.created_at', 'asc'],
            '-created_at' => ['o.created_at', 'desc'],
            'final_amount' => ['o.final_amount', 'asc'],
            '-final_amount' => ['o.final_amount', 'desc'],
            'total_amount' => ['o.total_amount', 'asc'],
            '-total_amount' => ['o.total_amount', 'desc'],
            'status' => ['o.status', 'asc'],
            '-status' => ['o.status', 'desc'],
        ];

        $resolved = $sortMap[$sort] ?? $sortMap['-created_at'];
        $query->orderBy($resolved[0], $resolved[1]);
    }

    private function applyDateRange(Builder $query, ?string $from, ?string $to, array $preferredColumns): void
    {
        $column = null;
        foreach ($preferredColumns as $candidate) {
            if (Schema::hasColumn('orders', $candidate)) {
                $column = $candidate;
                break;
            }
        }

        if (!$column) {
            return;
        }

        if ($from) {
            $query->whereDate('o.' . $column, '>=', $from);
        }

        if ($to) {
            $query->whereDate('o.' . $column, '<=', $to);
        }
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

    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    private function resolveSlaWarningSeconds(): int
    {
        try {
            $dbWarningSeconds = (int) optional(AppSetting::first())->order_sla_warning_seconds;
            if ($dbWarningSeconds > 0) {
                return $dbWarningSeconds;
            }
        } catch (\Throwable $e) {
            // Fall back to config when app_settings is unavailable.
        }

        return max(0, (int) config('ecoluck.order_sla_warning_seconds', 150));
    }

    private function parseCancelReason(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [
                'reason_code' => null,
                'note' => null,
            ];
        }

        $trimmed = trim($value);
        if (preg_match('/^\[(.+?)\]\s*-\s*(.+)$/', $trimmed, $matches) === 1) {
            return [
                'reason_code' => trim((string) ($matches[1] ?? '')) ?: null,
                'note' => trim((string) ($matches[2] ?? '')) ?: null,
            ];
        }

        return [
            'reason_code' => null,
            'note' => $trimmed,
        ];
    }

    private function buildCustomerRiskProfile(mixed $userId, string $currentStoreId): array
    {
        $empty = [
            'total_orders' => 0,
            'completed_orders' => 0,
            'canceled_orders_by_user' => 0,
            'returned_orders' => 0,
            'rejected_orders_by_stores' => 0,
            'complaints_count' => 0,
            'high_severity_complaints_count' => 0,
            'cancel_rate' => 0.0,
            'cancel_rate_percent' => 0.0,
            'risk_level' => 'low',
            'risk_flags' => [],
        ];

        $userId = trim((string) $userId);
        if ($userId === '' || !Schema::hasTable('orders') || !Schema::hasColumn('orders', 'user_id')) {
            return $empty;
        }

        $statusColumnExists = Schema::hasColumn('orders', 'status');
        $totalOrders = (int) DB::table('orders')->where('user_id', $userId)->count();

        $completedOrders = 0;
        $returnedOrders = 0;
        if ($statusColumnExists) {
            $completedOrders = (int) DB::table('orders')
                ->where('user_id', $userId)
                ->whereRaw('LOWER(status) in (?, ?, ?)', ['delivered', 'completed', 'complete'])
                ->count();

            $returnedOrders = (int) DB::table('orders')
                ->where('user_id', $userId)
                ->whereRaw('LOWER(status) = ?', ['returned'])
                ->count();
        }

        $canceledByUser = $this->countCustomerCanceledOrders($userId);
        $rejectedByStores = $this->countStoreRejectedOrders($userId, $currentStoreId);

        $complaintsCount = 0;
        $highSeverityComplaintsCount = 0;
        if (Schema::hasTable('complaints') && Schema::hasColumn('complaints', 'user_id')) {
            $complaintsCount = (int) DB::table('complaints')->where('user_id', $userId)->count();

            if (Schema::hasColumn('complaints', 'severity')) {
                $highSeverityComplaintsCount = (int) DB::table('complaints')
                    ->where('user_id', $userId)
                    ->whereRaw('LOWER(severity) in (?, ?, ?)', ['high', 'critical', 'severe'])
                    ->count();
            }
        }

        $cancelRate = $totalOrders > 0 ? round(($canceledByUser / $totalOrders) * 100, 2) : 0.0;
        $riskFlags = [];

        if ($totalOrders >= 3 && $cancelRate >= 30) {
            $riskFlags[] = 'HIGH_CANCEL_RATE';
        } elseif ($totalOrders >= 3 && $cancelRate >= 15) {
            $riskFlags[] = 'ELEVATED_CANCEL_RATE';
        }

        if ($rejectedByStores >= 2) {
            $riskFlags[] = 'MULTIPLE_STORE_REJECTIONS';
        } elseif ($rejectedByStores === 1) {
            $riskFlags[] = 'HAS_STORE_REJECTION';
        }

        if ($returnedOrders >= 2) {
            $riskFlags[] = 'MULTIPLE_RETURNS';
        } elseif ($returnedOrders === 1) {
            $riskFlags[] = 'HAS_RETURN';
        }

        if ($highSeverityComplaintsCount > 0) {
            $riskFlags[] = 'HIGH_SEVERITY_COMPLAINT';
        } elseif ($complaintsCount >= 3) {
            $riskFlags[] = 'MULTIPLE_COMPLAINTS';
        }

        $riskLevel = 'low';
        if (
            in_array('HIGH_CANCEL_RATE', $riskFlags, true)
            || in_array('MULTIPLE_STORE_REJECTIONS', $riskFlags, true)
            || in_array('MULTIPLE_RETURNS', $riskFlags, true)
            || in_array('HIGH_SEVERITY_COMPLAINT', $riskFlags, true)
        ) {
            $riskLevel = 'red';
        } elseif (!empty($riskFlags)) {
            $riskLevel = 'yellow';
        }

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'canceled_orders_by_user' => $canceledByUser,
            'returned_orders' => $returnedOrders,
            'rejected_orders_by_stores' => $rejectedByStores,
            'complaints_count' => $complaintsCount,
            'high_severity_complaints_count' => $highSeverityComplaintsCount,
            'cancel_rate' => $cancelRate,
            'cancel_rate_percent' => $cancelRate,
            'risk_level' => $riskLevel,
            'risk_flags' => $riskFlags,
        ];
    }

    private function countCustomerCanceledOrders(string $userId): int
    {
        if (
            Schema::hasTable('order_status_history')
            && Schema::hasColumn('order_status_history', 'order_id')
            && Schema::hasColumn('order_status_history', 'new_status')
            && Schema::hasColumn('order_status_history', 'changed_by_role')
        ) {
            $historyCount = (int) DB::table('orders as o')
                ->join('order_status_history as osh', 'osh.order_id', '=', 'o.id')
                ->where('o.user_id', $userId)
                ->whereRaw('LOWER(osh.new_status) in (?, ?)', ['canceled', 'cancelled'])
                ->whereIn(DB::raw('LOWER(osh.changed_by_role)'), ['user', 'customer', 'client', 'account_user'])
                ->distinct('o.id')
                ->count('o.id');

            if ($historyCount > 0) {
                return $historyCount;
            }
        }

        if (!Schema::hasColumn('orders', 'status')) {
            return 0;
        }

        $query = DB::table('orders')
            ->where('user_id', $userId)
            ->whereRaw('LOWER(status) in (?, ?)', ['canceled', 'cancelled']);

        if (Schema::hasColumn('orders', 'cancelled_by')) {
            $query->where('cancelled_by', $userId);
        }

        return (int) $query->count();
    }

    private function countStoreRejectedOrders(string $userId, string $currentStoreId): int
    {
        $storeAdminRoles = ['admin_store', 'store_admin', 'store_owner', 'shop_admin', 'store_staff', 'store_manager'];

        if (
            Schema::hasTable('order_status_history')
            && Schema::hasColumn('order_status_history', 'order_id')
            && Schema::hasColumn('order_status_history', 'new_status')
            && Schema::hasColumn('order_status_history', 'changed_by_role')
        ) {
            $historyQuery = DB::table('orders as o')
                ->join('order_status_history as osh', 'osh.order_id', '=', 'o.id')
                ->where('o.user_id', $userId)
                ->whereRaw('LOWER(osh.new_status) in (?, ?)', ['canceled', 'cancelled'])
                ->whereIn(DB::raw('LOWER(osh.changed_by_role)'), $storeAdminRoles);

            if ($currentStoreId !== '' && Schema::hasColumn('orders', 'store_id')) {
                $historyQuery->where('o.store_id', '!=', $currentStoreId);
            }

            $historyCount = (int) $historyQuery->distinct('o.id')->count('o.id');
            if ($historyCount > 0) {
                return $historyCount;
            }
        }

        if (!Schema::hasColumn('orders', 'status') || !Schema::hasColumn('orders', 'cancelled_by')) {
            return 0;
        }

        $fallbackQuery = DB::table('orders')
            ->where('user_id', $userId)
            ->whereRaw('LOWER(status) in (?, ?)', ['canceled', 'cancelled'])
            ->whereNotNull('cancelled_by')
            ->where('cancelled_by', '!=', $userId);

        if ($currentStoreId !== '' && Schema::hasColumn('orders', 'store_id')) {
            $fallbackQuery->where('store_id', '!=', $currentStoreId);
        }

        return (int) $fallbackQuery->count();
    }

    private function pushCustomerOrderRealtimeNotification(
        Store $store,
        object $orderRow,
        string $newStatus,
        string $notificationType,
        ?string $reason = null
    ): void {
        $userId = trim((string) ($orderRow->user_id ?? ''));
        if ($userId === '') {
            return;
        }

        // Respect customer preference before inserting/broadcasting realtime.
        $notifOrderEnabled = true;
        if (Schema::hasTable('user_settings') && Schema::hasColumn('user_settings', 'notif_order')) {
            $setting = UserSetting::query()
                ->where('user_id', $userId)
                ->first();

            if ($setting) {
                $notifOrderEnabled = (bool) ($setting->notif_order ?? true);
            }
        }

        if (!$notifOrderEnabled) {
            return;
        }

        $storeId = trim((string) ($store->id ?? ''));
        $storeName = trim((string) ($store->name ?? 'Cửa hàng'));
        $orderId = trim((string) ($orderRow->id ?? ''));
        $orderCode = trim((string) ($orderRow->order_code ?? ''));

        if ($orderId === '') {
            return;
        }

        $deepLink = '/pages/order-detail?id=' . urlencode($orderId);

        $title = 'Thông báo đơn hàng';
        $body = 'Cập nhật trạng thái đơn hàng.';

        if ($notificationType === 'order_confirmed') {
            $title = 'Đơn hàng được xác nhận';
            $body = sprintf('%s đã xác nhận đơn hàng #%s của bạn.', $storeName, $orderCode);
        } elseif ($notificationType === 'order_rejected') {
            $title = 'Đơn hàng bị từ chối';
            $body = sprintf('%s đã từ chối đơn hàng #%s của bạn.', $storeName, $orderCode);
            if ($reason !== null && trim($reason) !== '') {
                $body .= ' Lý do: ' . trim($reason);
            }
        } elseif ($notificationType === 'order_ready') {
            $title = 'Đơn hàng đang chuẩn bị';
            $body = sprintf('%s đang chuẩn bị đơn hàng #%s của bạn.', $storeName, $orderCode);
        } elseif ($notificationType === 'order_picked_up') {
            $title = 'Đơn hàng đã được lấy';
            $body = sprintf('Đơn hàng #%s của bạn đã được người giao hàng lấy đi.', $orderCode);
        } elseif ($notificationType === 'order_delivered') {
            $title = 'Đơn hàng đã giao';
            $body = sprintf('Đơn hàng #%s của bạn đã được giao thành công.', $orderCode);
        } elseif ($notificationType === 'order_returned') {
            $title = 'Đơn hàng trả lại';
            $body = sprintf('Đơn hàng #%s của bạn đã được trả lại.', $orderCode);
        }

        $notification = $this->notificationService->create([
            'audience_scope' => 'user_private',
            'user_id' => $userId,
            'store_id' => $storeId ?: null,
            'order_id' => $orderId, // may be null in DB if schema is legacy; realtime payload still carries order id.
            'type' => $notificationType,
            'title' => $title,
            'message' => $body,
            'body' => $body,
            'action_url' => $deepLink,
            'is_read' => false,
        ]);

        $payload = [
            'notification_id' => (string) ($notification->id ?? ''),
            'store' => [
                'id' => $storeId,
                'name' => $storeName,
                'logo_url' => $store->logo_url ?? null,
            ],
            'order' => [
                'id' => $orderId,
                'code' => $orderCode,
                'new_status' => $newStatus,
                'reason' => $reason,
            ],
            'display' => [
                'title' => $title,
                'body' => $body,
            ],
            'deep_link' => $deepLink,
            'action_url' => $deepLink, // alias để FE xử lý tương thích
        ];

        event(new OrderNotificationEvent($userId, $payload));
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

    private function resolveAccessibleStoreById(User $user, string $storeId): ?Store
    {
        $storeId = trim($storeId);
        if ($storeId === '') {
            return null;
        }

        if (in_array((string) $user->role, ['admin', 'super_admin'], true)) {
            $storeQuery = Store::query()->where('id', $storeId);
            if (Schema::hasColumn('stores', 'deleted_at')) {
                $storeQuery->whereNull('deleted_at');
            }

            return $storeQuery->first();
        }

        // A user who owns the store should always be allowed to access it, regardless of their role string in users table.
        // For example, their role might be 'affiliate' but they are the owner of the store.
        $storeQuery = Store::query()->where('id', $storeId)->where('owner_id', $user->id);
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        $store = $storeQuery->first();
        if ($store) {
            return $store;
        }

        if (Schema::hasTable('user_role_bindings') && Schema::hasTable('roles')) {
            $hasStoreBinding = DB::table('user_role_bindings as urb')
                ->join('roles as r', 'r.id', '=', 'urb.role_id')
                ->where('urb.user_id', $user->id)
                ->where('urb.entity_type', 'store')
                ->where('urb.entity_id', $storeId)
                ->whereIn('r.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']))
                ->when(Schema::hasColumn('user_role_bindings', 'status'), fn($query) => $query->where('urb.status', 'active'))
                ->exists();

            if ($hasStoreBinding) {
                $storeQuery = Store::query()->where('id', $storeId);
                if (Schema::hasColumn('stores', 'deleted_at')) {
                    $storeQuery->whereNull('deleted_at');
                }

                return $storeQuery->first();
            }
        }

        return null;
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

    private function restoreParentOrderQuantities(string $childOrderId, string $parentOrderId): void
    {
        $childItems = DB::table('order_items')
            ->where('order_id', $childOrderId)
            ->get();
        $parentItems = DB::table('order_items')
            ->where('order_id', $parentOrderId)
            ->lockForUpdate()
            ->get();
        $parentItemsByMenuId = [];
        $parentItemsByProductKey = [];

        foreach ($parentItems as $parentItem) {
            if (!empty($parentItem->menu_item_id)) {
                $parentItemsByMenuId[(string) $parentItem->menu_item_id] = $parentItem;
                continue;
            }

            if (!empty($parentItem->product_id)) {
                $variantId = isset($parentItem->variant_id) ? (string) $parentItem->variant_id : '';
                $parentItemsByProductKey[(string) $parentItem->product_id . ':' . $variantId] = $parentItem;
            }
        }

        foreach ($childItems as $childItem) {
            $parentItem = null;
            if (!empty($childItem->menu_item_id)) {
                $parentItem = $parentItemsByMenuId[(string) $childItem->menu_item_id] ?? null;
            } elseif (!empty($childItem->product_id)) {
                $variantId = isset($childItem->variant_id) ? (string) $childItem->variant_id : '';
                $parentItem = $parentItemsByProductKey[(string) $childItem->product_id . ':' . $variantId] ?? null;
            }

            if ($parentItem) {
                $restoreQuantity = (int) ($childItem->quantity ?? 0);
                $currentCalled = (int) ($parentItem->called_quantity ?? 0);
                $newCalledQuantity = max(0, $currentCalled - $restoreQuantity);

                DB::table('order_items')
                    ->where('id', $parentItem->id)
                    ->update(['called_quantity' => $newCalledQuantity]);
            }
        }

        // Update parent order status based on remaining quantities
        $hasRemaining = DB::table('order_items')
            ->where('order_id', $parentOrderId)
            ->whereRaw('COALESCE(quantity, 0) > COALESCE(called_quantity, 0)')
            ->exists();

        $anyCalled = DB::table('order_items')
            ->where('order_id', $parentOrderId)
            ->where('called_quantity', '>', 0)
            ->exists();

        $newStatus = 'paid_pending_call';
        if ($anyCalled) {
            $newStatus = $hasRemaining ? 'partially_called' : 'call_completed';
        } elseif (!$hasRemaining) {
            $newStatus = 'call_completed'; // Should not happen if quantities are restored correctly
        }

        DB::table('orders')
            ->where('id', $parentOrderId)
            ->update(['status' => $newStatus, 'updated_at' => now()]);
    }
}
