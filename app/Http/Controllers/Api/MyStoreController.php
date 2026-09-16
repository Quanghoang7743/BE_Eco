<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreLicense;
use App\Models\StoreHour;
use App\Models\StoreOperatingHistory;
use App\Services\NotificationService;
use App\Services\StoreApprovalHistoryService;
use App\Services\StoreBusinessTypeService;
use App\Support\SafeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MyStoreController extends Controller
{
    private const ACTION_KEY_ADMIN_STORE_APPROVALS_PENDING = 'key:admin.store_approvals.pending';
    private const ACTION_KEY_CLIENT_STORE_PARTNER_REGISTRATION = 'key:client.profile.store_registration';

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly StoreApprovalHistoryService $approvalHistoryService,
        private readonly StoreBusinessTypeService $businessTypeService
    ) {
    }

    /**
     * Get list of stores managed by current user (Multi-store support)
     * 
     * Endpoint: GET /api/my-stores
     * 
     * Response includes:
     * - Store info (id, name, status, logo_url, cover_image, address, phone, email)
     * - User role at store (admin_store, store_manager, etc.)
     * - Array of permissions user has at this store
     * - can_access: boolean (user_role_bindings.status == 'active')
     * - can_manage: boolean (role in manager roles)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'authenticated' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                    ],
                ],
            ], 401);
        }

        // Check if required tables exist
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('stores') || !Schema::hasTable('roles')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        try {
            $ownerStores = Store::query()
                ->where('owner_id', $user->id)
                ->select([
                    'id as store_id',
                    'name as store_name',
                    'status as approval_status',
                    'rejection_reason',
                    'logo_url',
                    'cover_image',
                    'address',
                    'phone',
                    'email',
                    'is_open',
                ])
                ->orderByDesc('created_at')
                ->when(Schema::hasColumn('stores', 'deleted_at'), function ($query) {
                    $query->whereNull('deleted_at');
                })
                ->get()
                ->map(function ($store) {
                    $status = strtolower((string) $store->approval_status);
                    $canAccess = in_array($status, ['draft', 'pending', 'approved', 'active'], true);
                    $canManage = in_array($status, ['approved', 'active'], true);
                    $canView = $canAccess || $status === 'rejected';

                    return [
                        'store_id' => (string) $store->store_id,
                        'store_name' => (string) $store->store_name,
                        'approval_status' => (string) $store->approval_status,
                        'rejection_reason' => $store->rejection_reason,
                        'logo_url' => $store->logo_url,
                        'cover_image' => $store->cover_image,
                        'address' => $store->address,
                        'phone' => $store->phone,
                        'email' => $store->email,
                        'role_name' => $canManage ? 'admin_store' : ($canAccess ? 'admin_store_pending' : 'account_user'),
                        'binding_status' => $canAccess ? 'active' : 'inactive',
                        'owner_can_manage' => $canManage,
                        'owner_can_view' => $canView,
                        'source' => 'owner',
                        'is_open' => (bool) ($store->is_open ?? true),
                    ];
                });

            $assignedStores = collect();
            if (Schema::hasTable('user_role_bindings') && Schema::hasTable('roles')) {
                $assignedStores = DB::table('user_role_bindings as urb')
                    ->join('stores as s', 's.id', '=', 'urb.entity_id')
                    ->leftJoin('roles as r', 'r.id', '=', 'urb.role_id')
                    ->where('urb.user_id', $user->id)
                    ->where('urb.entity_type', 'store')
                    ->whereIn('r.name', ['store_staff', 'store_manager', 'store_custom'])
                    ->when(Schema::hasColumn('user_role_bindings', 'status'), function ($query) {
                        $query->where('urb.status', 'active');
                    })
                    ->select(
                        's.id as store_id',
                        's.name as store_name',
                        's.status as approval_status',
                        's.rejection_reason',
                        's.logo_url',
                        's.cover_image',
                        's.address',
                        's.phone',
                        's.email',
                        'r.name as role_name',
                        'urb.status as binding_status',
                        's.is_open'
                    )
                    ->when(Schema::hasColumn('stores', 'deleted_at'), function ($query) {
                        $query->whereNull('s.deleted_at');
                    })
                    ->get()
                    ->map(function ($binding) {
                        return [
                            'store_id' => (string) $binding->store_id,
                            'store_name' => (string) $binding->store_name,
                            'approval_status' => (string) $binding->approval_status,
                            'rejection_reason' => $binding->rejection_reason,
                            'logo_url' => $binding->logo_url,
                            'cover_image' => $binding->cover_image,
                            'address' => $binding->address,
                            'phone' => $binding->phone,
                            'email' => $binding->email,
                            'role_name' => (string) ($binding->role_name ?? 'store_staff'),
                            'binding_status' => (string) ($binding->binding_status ?? 'active'),
                            'source' => 'assignment',
                            'is_open' => (bool) ($binding->is_open ?? true),
                        ];
                    });
            }

            $mergedStores = $ownerStores
                ->concat($assignedStores)
                ->unique('store_id')
                ->values();

            if ($mergedStores->isEmpty()) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'total' => 0,
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            $statisticsByStoreId = $this->buildStoreStatistics(
                $mergedStores->pluck('store_id')->map(fn ($id) => (string) $id)->all()
            );

            // Build response data
            $data = [];
            foreach ($mergedStores as $binding) {
                $storeId = (string) $binding['store_id'];
                $statistics = $statisticsByStoreId[$storeId] ?? [
                    'orders_count' => 0,
                    'employees_count' => 0,
                    'revenue' => 0,
                ];

                // Get permissions for this user at this store
                $permissions = $this->getPermissionsForUserStore($user->id, $storeId);

                // Determine if user can access this store
                $canAccess = (string) ($binding['binding_status'] ?? '') === 'active';

                // Determine if user can manage this store
                $managerRoles = ['admin_store', 'store_manager', 'store_owner', 'shop_admin'];
                $roleName = (string) ($binding['role_name'] ?? 'store_staff');
                $canManage = ($binding['source'] ?? '') === 'owner'
                    ? (bool) ($binding['owner_can_manage'] ?? false)
                    : in_array($roleName, $managerRoles, true);

                $canView = $canAccess
                    || $canManage
                    || !empty($permissions)
                    || (($binding['source'] ?? '') === 'owner' && (bool) ($binding['owner_can_view'] ?? false));

                if (!$canView) {
                    continue;
                }

                $data[] = [
                    'store_id' => $binding['store_id'],
                    'store_name' => $binding['store_name'],
                    'approval_status' => $binding['approval_status'],
                    'rejection_reason' => $binding['rejection_reason'] ?? null,
                    'logo_url' => $binding['logo_url'],
                    'cover_image' => $binding['cover_image'],
                    'address' => $binding['address'],
                    'phone' => $binding['phone'],
                    'email' => $binding['email'],
                    'role' => $roleName,
                    'permissions' => $permissions,
                    'can_access' => $canAccess,
                    'can_manage' => $canManage,
                    'can_view' => $canView,
                    'orders_count' => $statistics['orders_count'],
                    'employees_count' => $statistics['employees_count'],
                    'revenue' => $statistics['revenue'],
                    'is_open' => (bool) ($binding['is_open'] ?? true),
                ];
            }

            return response()->json([
                'data' => $data,
                'meta' => [
                    'total' => count($data),
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);

        } catch (\Throwable $e) {
            Log::error('MyStoreController@index error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'SERVER_ERROR',
                        'message' => 'Có lỗi xảy ra khi lấy danh sách cửa hàng. Vui lòng thử lại sau.',
                    ],
                ],
            ], 500);
        }
    }

    private function buildStoreStatistics(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter($storeIds, fn ($id) => $id !== '')));
        $statistics = [];

        foreach ($storeIds as $storeId) {
            $statistics[$storeId] = [
                'orders_count' => 0,
                'employees_count' => 0,
                'revenue' => 0,
            ];
        }

        if ($storeIds === []) {
            return $statistics;
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'store_id')) {
            $orderCounts = DB::table('orders')
                ->whereIn('store_id', $storeIds)
                ->selectRaw('store_id, COUNT(*) as orders_count')
                ->groupBy('store_id')
                ->get();

            foreach ($orderCounts as $row) {
                $storeId = (string) $row->store_id;
                if (isset($statistics[$storeId])) {
                    $statistics[$storeId]['orders_count'] = max(0, (int) $row->orders_count);
                }
            }

            $amountColumn = Schema::hasColumn('orders', 'final_amount')
                ? 'final_amount'
                : (Schema::hasColumn('orders', 'total_amount') ? 'total_amount' : 'total');
            $revenueQuery = DB::table('orders')
                ->whereIn('store_id', $storeIds);

            if (Schema::hasColumn('orders', 'status')) {
                $revenueQuery->whereIn(
                    DB::raw('LOWER(COALESCE(status, ""))'),
                    ['delivered', 'completed', 'call_completed']
                );
            }

            $revenueRows = $revenueQuery
                ->selectRaw('store_id, COALESCE(SUM(`' . $amountColumn . '`), 0) as revenue')
                ->groupBy('store_id')
                ->get();

            foreach ($revenueRows as $row) {
                $storeId = (string) $row->store_id;
                if (isset($statistics[$storeId])) {
                    $statistics[$storeId]['revenue'] = max(0, (int) round((float) $row->revenue));
                }
            }
        }

        if (Schema::hasTable('user_role_bindings') && Schema::hasTable('roles')) {
            $employeeRows = DB::table('user_role_bindings as urb')
                ->join('roles as r', 'r.id', '=', 'urb.role_id')
                ->where('urb.entity_type', 'store')
                ->whereIn('urb.entity_id', $storeIds)
                ->whereIn('r.name', ['store_staff', 'store_manager', 'store_custom'])
                ->when(Schema::hasColumn('user_role_bindings', 'status'), function ($query) {
                    $query->where('urb.status', 'active');
                })
                ->selectRaw('urb.entity_id as store_id, COUNT(DISTINCT urb.user_id) as employees_count')
                ->groupBy('urb.entity_id')
                ->get();

            foreach ($employeeRows as $row) {
                $storeId = (string) $row->store_id;
                if (isset($statistics[$storeId])) {
                    $statistics[$storeId]['employees_count'] = max(0, (int) $row->employees_count);
                }
            }
        }

        return $statistics;
    }

    /**
     * Get detail of one store for the current user.
     *
     * Endpoint: GET /api/my-stores/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                ]],
            ], 401);
        }

        if (!Schema::hasTable('stores')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'TABLE_NOT_FOUND',
                    'message' => 'Không tìm thấy bảng stores.',
                ]],
            ], 500);
        }

        try {
            $storeRow = DB::table('stores')->where('id', $id)->first();

            if (!$storeRow) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [[
                        'code' => 'STORE_NOT_FOUND',
                        'message' => 'Không tìm thấy cửa hàng.',
                    ]],
                ], 404);
            }

            $isOwner = (string) ($storeRow->owner_id ?? '') === (string) $user->id;
            $binding = null;

            if (!$isOwner && Schema::hasTable('user_role_bindings')) {
                $binding = DB::table('user_role_bindings as urb')
                    ->leftJoin('roles as r', 'r.id', '=', 'urb.role_id')
                    ->where('urb.user_id', $user->id)
                    ->where('urb.entity_type', 'store')
                    ->where('urb.entity_id', $id)
                    ->where('urb.status', 'active')
                    ->select('urb.status as binding_status', 'r.name as role_name', 'urb.role_id')
                    ->first();
            }

            if (!$isOwner && !$binding) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [[
                        'code' => 'FORBIDDEN',
                        'message' => 'Bạn không có quyền truy cập cửa hàng này.',
                    ]],
                ], 403);
            }

            $store = $this->normalizeStoreDetail($storeRow);

            $hours = [];
            if (Schema::hasTable('store_hours')) {
                $hours = StoreHour::query()
                    ->where('store_id', $id)
                    ->orderBy('day_of_week')
                    ->get()
                    ->map(function (StoreHour $hour) {
                        return [
                            'id' => $hour->id,
                            'store_id' => $hour->store_id,
                            'day_of_week' => (int) $hour->day_of_week,
                            'open_time' => $hour->open_time,
                            'close_time' => $hour->close_time,
                            'is_closed' => (bool) $hour->is_closed,
                            'created_at' => $this->toIso($hour->created_at ?? null),
                            'updated_at' => $this->toIso($hour->updated_at ?? null),
                        ];
                    })
                    ->values()
                    ->all();
            }

            $roleName = $binding->role_name ?? ($isOwner ? 'owner' : null);
            $managerRoles = ['admin_store', 'store_manager', 'store_owner', 'shop_admin'];

            return response()->json([
                'data' => [
                    'store' => $store,
                    'store_hours' => $hours,
                    'access' => [
                        'role' => $roleName,
                        'can_access' => $isOwner || ($binding !== null),
                        'can_manage' => in_array((string) $roleName, $managerRoles, true) || $isOwner,
                        'binding_status' => $binding->binding_status ?? null,
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            Log::error('MyStoreController@show error', [
                'user_id' => $user->id,
                'store_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'SERVER_ERROR',
                    'message' => 'Có lỗi xảy ra khi lấy chi tiết cửa hàng. Vui lòng thử lại sau.',
                ]],
            ], 500);
        }
    }

    /**
     * Update a store owned or managed by the current user.
     *
     * Endpoint: PUT /api/my-stores/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                ]],
            ], 401);
        }

        if (!Schema::hasTable('stores')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'TABLE_NOT_FOUND',
                    'message' => 'Không tìm thấy bảng stores.',
                ]],
            ], 500);
        }

        $store = Store::query()->where('id', $id)->first();
        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'STORE_NOT_FOUND',
                    'message' => 'Không tìm thấy cửa hàng.',
                ]],
            ], 404);
        }

        [$canUpdate, $bindingRole] = $this->canUpdateStore($user->id, $store);
        if (!$canUpdate) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'FORBIDDEN',
                    'message' => 'Bạn không có quyền cập nhật cửa hàng này.',
                ]],
            ], 403);
        }

        $input = $request->all();
        if (array_key_exists('email', $input)) {
            $input['email'] = SafeEmail::normalizeForValidation($input['email']);
        }

        $validator = Validator::make($input, [
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule()],
            'logo_url' => ['nullable', 'url', 'max:2000'],
            'cover_image' => ['nullable', 'url', 'max:2000'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'license_image' => ['nullable', 'url', 'max:2000'],
            'representative' => ['nullable', 'string', 'max:255'],
            'business_type_id' => ['nullable', 'integer', Rule::exists('store_business_types', 'id')],
            'business_type' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'cccd_front_url' => ['nullable', 'url', 'max:2000'],
            'cccd_back_url' => ['nullable', 'url', 'max:2000'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'license_images' => ['nullable', 'array'],
            'license_images.*' => ['nullable', 'url', 'max:2000'],
            'license_gallery' => ['nullable', 'array'],
            'license_gallery.*' => ['nullable', 'url', 'max:2000'],
            'license_urls' => ['nullable', 'array'],
            'license_urls.*' => ['nullable', 'url', 'max:2000'],
            'licenses' => ['nullable', 'array'],
            'licenses.*.license_type' => ['nullable', 'string', 'max:255'],
            'licenses.*.license_number' => ['nullable', 'string', 'max:100'],
            'licenses.*.image_url' => ['nullable', 'url', 'max:2000'],
            'licenses.*.expiry_date' => ['nullable', 'date'],
            'sla_timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Dữ liệu nhập vào không hợp lệ.',
                    'details' => $validator->errors()->toArray(),
                ]],
            ], 422);
        }

        $validated = $validator->validated();
        $validated = array_merge($validated, $this->businessTypeService->storePayloadFromInput($validated));
        $storeRowBefore = DB::table('stores')->where('id', $store->id)->first();
        $statusBefore = strtolower((string) ($storeRowBefore?->status ?? $store->status ?? ''));
        $wasRejected = $statusBefore === 'rejected';

        $allowedFields = [
            'name',
            'description',
            'address',
            'phone',
            'email',
            'logo_url',
            'cover_image',
            'license_number',
            'license_image',
            'cccd_front_url',
            'cccd_back_url',
            'representative',
            'business_type_id',
            'business_type',
            'bank_name',
            'bank_account',
        ];

        $sensitiveFields = [
            'license_number',
            'license_image',
            'cccd_front_url',
            'cccd_back_url',
            'representative',
            'business_type_id',
            'business_type',
            'bank_name',
            'bank_account',
        ];

        $payload = [];
        $changedFields = [];
        $legalChanged = false;
        $licenseSyncRequested = $this->hasSubmittedLicensePayload($request->all());
        $submittedLicenseItems = $licenseSyncRequested
            ? $this->normalizeLicenseItems($request->all())
            : [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $newValue = $validated[$field];
            $oldValue = $storeRowBefore?->{$field} ?? null;

            if ($this->hasValueChanged($oldValue, $newValue)) {
                $changedFields[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];

                if (in_array($field, $sensitiveFields, true)) {
                    $legalChanged = true;
                }
            }

            $payload[$field] = $newValue;
        }

        $locationPayload = null;
        if (array_key_exists('location', $validated)) {
            $locationPayload = [
                'lat' => (float) data_get($validated, 'location.lat'),
                'lng' => (float) data_get($validated, 'location.lng'),
            ];
            if ($this->hasValueChanged($this->extractLocationFromStoreRow($storeRowBefore), $locationPayload)) {
                $changedFields['location'] = [
                    'old' => $this->extractLocationFromStoreRow($storeRowBefore),
                    'new' => $locationPayload,
                ];
                $legalChanged = true;
            } else {
                $locationPayload = null;
            }
        }

        if (array_key_exists('sla_timeout_minutes', $validated) && Schema::hasColumn('stores', 'custom_sla_minutes')) {
            $newSlaMinutes = $validated['sla_timeout_minutes'] !== null ? (int) $validated['sla_timeout_minutes'] : null;
            $oldSlaMinutes = $storeRowBefore?->custom_sla_minutes !== null ? (int) $storeRowBefore->custom_sla_minutes : null;

            if ($this->hasValueChanged($oldSlaMinutes, $newSlaMinutes)) {
                $payload['custom_sla_minutes'] = $newSlaMinutes;
                $changedFields['sla_timeout_minutes'] = [
                    'old' => $oldSlaMinutes,
                    'new' => $newSlaMinutes,
                ];
                $legalChanged = true;
            }
        }

        if ($licenseSyncRequested) {
            $existingComparableLicenses = $this->normalizeComparableLicenseItems(
                $this->loadEditableLicenseItems($store->id),
            );
            $submittedComparableLicenses = $this->normalizeComparableLicenseItems($submittedLicenseItems);

            if ($this->hasValueChanged($existingComparableLicenses, $submittedComparableLicenses)) {
                $changedFields['licenses'] = [
                    'old' => $existingComparableLicenses,
                    'new' => $submittedComparableLicenses,
                ];
                $legalChanged = true;
            }
        }

        if (empty($payload) && $locationPayload === null && !array_key_exists('licenses', $changedFields)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'NO_UPDATABLE_FIELDS',
                    'message' => 'Không có dữ liệu hợp lệ để cập nhật.',
                ]],
            ], 422);
        }

        try {
            DB::transaction(function () use (
                $store,
                $payload,
                $locationPayload,
                $legalChanged,
                $changedFields,
                $user,
                $bindingRole,
                $statusBefore,
                $wasRejected,
                $licenseSyncRequested,
                $submittedLicenseItems
            ) {
                $storeType = $this->getStoreLocationType();
                $shouldResubmitRejectedStore = $wasRejected && !empty($changedFields);
                $shouldResetToPending = $shouldResubmitRejectedStore || ($legalChanged && $statusBefore !== 'pending');

                if ($shouldResetToPending) {
                    $payload['status'] = 'pending';
                    $payload['rejection_reason'] = null;
                    $payload['approved_by'] = null;
                    $payload['approved_at'] = null;

                    if (Schema::hasColumn('stores', 'submitted_at')) {
                        $payload['submitted_at'] = now();
                    }

                    if (Schema::hasColumn('stores', 'submitted_by')) {
                        $payload['submitted_by'] = $user->id;
                    }
                }

                if (!empty($payload)) {
                    $store->fill($payload);
                    $store->save();
                }

                if ($locationPayload !== null) {
                    if (in_array($storeType, ['geometry', 'point', 'geography'], true)) {
                        $this->updateStoreLocation($store->id, (float) $locationPayload['lng'], (float) $locationPayload['lat']);
                    } else {
                        $store->location = $locationPayload;
                        $store->save();
                    }
                }

                if ($licenseSyncRequested && Schema::hasTable('store_licenses')) {
                    $this->syncEditableLicenses($store, $submittedLicenseItems);

                    $primaryLicense = $submittedLicenseItems[0] ?? null;
                    $store->forceFill([
                        'license_number' => $primaryLicense['license_number'] ?? null,
                        'license_image' => $primaryLicense['image_url'] ?? null,
                    ])->save();
                }

                if ($shouldResetToPending && Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                    DB::table('users')
                        ->where('id', $store->owner_id)
                        ->update([
                            'role' => 'admin_store_pending',
                            'updated_at' => now(),
                        ]);
                }

                if (Schema::hasTable('store_operating_histories')) {
                    StoreOperatingHistory::query()->create([
                        'store_id' => $store->id,
                        'action_code' => 'profile_update',
                        'action_label' => 'Cap nhat ho so cua hang',
                        'status_after' => (string) ($store->status ?? 'pending'),
                        'reason' => $legalChanged ? 'Cap nhat thong tin phap ly can duyet lai' : 'Cap nhat thong tin cua hang',
                        'changed_by' => (string) $user->id,
                        'meta' => [
                            'changed_fields' => array_keys($changedFields),
                            'binding_role' => $bindingRole,
                            'legal_changed' => $legalChanged,
                            'resubmitted_from_rejected' => $shouldResubmitRejectedStore,
                        ],
                    ]);
                }
            });

            $updatedStoreRow = DB::table('stores')->where('id', $store->id)->first();
            $statusAfter = strtolower((string) ($updatedStoreRow?->status ?? $store->status ?? ''));

            if ($statusAfter !== $statusBefore) {
                Event::dispatch('store.approval_status_changed', [
                    'store_id' => $store->id,
                    'owner_id' => $store->owner_id,
                    'old_status' => $statusBefore,
                    'new_status' => $statusAfter,
                    'changed_at' => now()->toIso8601String(),
                ]);
            }

            if ($statusAfter === 'pending' && $statusAfter !== $statusBefore) {
                $this->approvalHistoryService->recordSubmission($store, $user->id ?? null);

                $owner = $this->resolveNotificationOwner($store->owner_id, $user);
                $this->createPartnerRegistrationNotifications($store, $owner);
            }

            return response()->json([
                'data' => [
                    'id' => $store->id,
                    'store_id' => $store->id,
                    'store_name' => (string) ($updatedStoreRow->name ?? $store->name ?? ''),
                    'store_code' => $updatedStoreRow->store_code ?? null,
                    'name' => (string) ($updatedStoreRow->name ?? $store->name ?? ''),
                    'email' => $updatedStoreRow->email ?? null,
                    'phone' => $updatedStoreRow->phone ?? null,
                    'address' => $updatedStoreRow->address ?? null,
                    'approval_status' => $updatedStoreRow->status ?? $store->status ?? 'pending',
                    'owner_id' => $updatedStoreRow->owner_id ?? $store->owner_id ?? null,
                    'created_at' => $this->toIso($updatedStoreRow->created_at ?? null),
                    'message' => $statusAfter === 'pending'
                        ? 'Hồ sơ cửa hàng đã được gửi lại để chờ duyệt.'
                        : 'Đã cập nhật hồ sơ cửa hàng thành công.',
                    'store' => $this->normalizeStoreDetail($updatedStoreRow),
                    'access' => [
                        'role' => $bindingRole ?? 'owner',
                        'can_access' => true,
                        'can_manage' => in_array($statusAfter, ['approved', 'active'], true),
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            Log::error('MyStoreController@update error', [
                'user_id' => $user->id,
                'store_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'SERVER_ERROR',
                    'message' => 'Có lỗi xảy ra khi cập nhật cửa hàng. Vui lòng thử lại sau.',
                ]],
            ], 500);
        }
    }

    /**
     * Create a new store for the current user (Multi-store support)
     * 
     * Endpoint: POST /api/my-stores
     * 
     * Creates:
     * - New record in stores table with owner_id = Auth::id(), status = 'pending'
     * - New record in user_role_bindings to assign admin_store role to owner
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                    ],
                ],
            ], 401);
        }

        // Check if required tables exist
        if (!Schema::hasTable('stores') || !Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'TABLE_NOT_FOUND',
                        'message' => 'Cơ sở dữ liệu chưa sẵn sàng. Liên hệ quản trị viên.',
                    ],
                ],
            ], 500);
        }

        // Validate request data
        $input = $request->all();
        if (array_key_exists('email', $input)) {
            $input['email'] = SafeEmail::normalizeForValidation($input['email']);
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', SafeEmail::validationRule()],
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:500'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'representative' => ['nullable', 'string', 'max:255'],
            'business_type_id' => ['nullable', 'integer', Rule::exists('store_business_types', 'id')],
            'business_type' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'cccd_front_url' => ['nullable', 'url', 'max:2000'],
            'cccd_back_url' => ['nullable', 'url', 'max:2000'],
            'license_type' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'license_image' => ['nullable', 'url', 'max:2000'],
            'license_images' => ['nullable', 'array'],
            'license_images.*' => ['nullable', 'url', 'max:2000'],
            'license_gallery' => ['nullable', 'array'],
            'license_gallery.*' => ['nullable', 'url', 'max:2000'],
            'license_urls' => ['nullable', 'array'],
            'license_urls.*' => ['nullable', 'url', 'max:2000'],
            'licenses' => ['nullable', 'array'],
            'licenses.*.license_type' => ['nullable', 'string', 'max:255'],
            'licenses.*.license_number' => ['nullable', 'string', 'max:100'],
            'licenses.*.image_url' => ['nullable', 'url', 'max:2000'],
            'licenses.*.expiry_date' => ['nullable', 'date'],
            'sla_timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Dữ liệu nhập vào không hợp lệ.',
                        'details' => $validator->errors()->toArray(),
                    ],
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $businessTypePayload = $this->businessTypeService->storePayloadFromInput($validated);
        $locationPayload = array_key_exists('location', $validated)
            ? [
                'lat' => (float) data_get($validated, 'location.lat'),
                'lng' => (float) data_get($validated, 'location.lng'),
            ]
            : null;

        try {
            $newStoreId = null;
            
            // Use transaction to ensure data consistency
            DB::transaction(function () use ($user, $request, $validated, $businessTypePayload, $locationPayload, &$newStoreId) {
                // Generate unique store code
                $storeCode = $this->generateStoreCode();

                $existingDraftStore = DB::table('stores')
                    ->where('owner_id', $user->id)
                    ->where('status', 'draft')
                    ->when(Schema::hasColumn('stores', 'deleted_at'), function ($query) {
                        $query->whereNull('deleted_at');
                    })
                    ->orderByDesc('created_at')
                    ->first();

                if ($existingDraftStore) {
                    $newStoreId = $existingDraftStore->id;
                    $storeUpdate = array_merge([
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'phone' => $validated['phone'],
                        'address' => $validated['address'],
                        'representative' => $validated['representative'] ?? null,
                        'bank_name' => $validated['bank_name'] ?? null,
                        'bank_account' => $validated['bank_account'] ?? null,
                        'license_number' => $validated['license_number'] ?? null,
                        'license_image' => $validated['license_image'] ?? null,
                        'cccd_front_url' => $validated['cccd_front_url'] ?? null,
                        'cccd_back_url' => $validated['cccd_back_url'] ?? null,
                        'status' => 'pending',
                        'updated_at' => now(),
                    ], $businessTypePayload);

                    if (Schema::hasColumn('stores', 'custom_sla_minutes') && $request->filled('sla_timeout_minutes')) {
                        $storeUpdate['custom_sla_minutes'] = (int) $request->input('sla_timeout_minutes');
                    }

                    DB::table('stores')->where('id', $newStoreId)->update($storeUpdate);
                } else {
                    // Create new store record
                    $newStoreId = Str::uuid()->toString();
                    
                    $storeInsert = array_merge([
                        'id' => $newStoreId,
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'phone' => $validated['phone'],
                        'address' => $validated['address'],
                        'representative' => $validated['representative'] ?? null,
                        'bank_name' => $validated['bank_name'] ?? null,
                        'bank_account' => $validated['bank_account'] ?? null,
                        'license_number' => $validated['license_number'] ?? null,
                        'license_image' => $validated['license_image'] ?? null,
                        'cccd_front_url' => $validated['cccd_front_url'] ?? null,
                        'cccd_back_url' => $validated['cccd_back_url'] ?? null,
                        'store_code' => $storeCode,
                        'owner_id' => $user->id,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $businessTypePayload);

                    if (Schema::hasColumn('stores', 'custom_sla_minutes') && $request->filled('sla_timeout_minutes')) {
                        $storeInsert['custom_sla_minutes'] = (int) $request->input('sla_timeout_minutes');
                    }

                    DB::table('stores')->insert($storeInsert);
                }

                if ($locationPayload !== null && Schema::hasColumn('stores', 'location')) {
                    $storeType = $this->getStoreLocationType();
                    if (in_array($storeType, ['geometry', 'point', 'geography'], true)) {
                        $this->updateStoreLocation($newStoreId, (float) $locationPayload['lng'], (float) $locationPayload['lat']);
                    } else {
                        $locationStore = Store::query()->find($newStoreId);
                        if ($locationStore instanceof Store) {
                            $locationStore->location = $locationPayload;
                            $locationStore->save();
                        }
                    }
                }

                // Get admin_store role id
                $adminStoreRole = DB::table('roles')
                    ->where('name', 'admin_store')
                    ->first();

                $roleId = $adminStoreRole?->id;

                if (!$roleId) {
                    throw new \Exception('admin_store role not found in database');
                }

                $bindingExists = DB::table('user_role_bindings')
                    ->where('user_id', $user->id)
                    ->where('role_id', $roleId)
                    ->where('entity_type', 'store')
                    ->where('entity_id', $newStoreId)
                    ->exists();

                // Create user_role_binding to assign admin_store role
                if (!$bindingExists) {
                    DB::table('user_role_bindings')->insert([
                        'user_id' => $user->id,
                        'role_id' => $roleId,
                        'entity_type' => 'store',
                        'entity_id' => $newStoreId,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $licenseItems = $this->normalizeLicenseItems($request->all());
                if (!empty($licenseItems) && Schema::hasTable('store_licenses')) {
                    if (isset($existingDraftStore) && $existingDraftStore) {
                        StoreLicense::query()->where('store_id', $newStoreId)->delete();
                    }

                    $now = now();
                    foreach ($licenseItems as $item) {
                        StoreLicense::query()->create([
                            'store_id' => $newStoreId,
                            'license_type' => $item['license_type'],
                            'license_number' => $item['license_number'],
                            'image_url' => $item['image_url'],
                            'expiry_date' => $item['expiry_date'],
                            'status' => 'pending',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $storeUpdate = [];
                    $primary = $licenseItems[0] ?? null;
                    if ($primary) {
                        if (!$request->filled('license_number') && !empty($primary['license_number'])) {
                            $storeUpdate['license_number'] = $primary['license_number'];
                        }
                        if (!$request->filled('license_image') && !empty($primary['image_url'])) {
                            $storeUpdate['license_image'] = $primary['image_url'];
                        }
                    }

                    if (!empty($storeUpdate)) {
                        $storeUpdate['updated_at'] = $now;
                        DB::table('stores')->where('id', $newStoreId)->update($storeUpdate);
                    }
                }
            });

            // Fetch the created store to return in response
            $newStore = DB::table('stores')
                ->where('id', $newStoreId)
                ->first();

            $newStoreModel = Store::query()->find($newStoreId);
            if ($newStoreModel instanceof Store) {
                $this->approvalHistoryService->recordSubmission($newStoreModel, $user->id ?? null);
                $pendingCount = Store::where('status', 'pending')->count();
                event(new \App\Events\StoreApprovalUpdated($newStoreModel->id, 'pending', $pendingCount));
            }

            return response()->json([
                'data' => [
                    'store_id' => $newStore->id,
                    'store_name' => $newStore->name,
                    'store_code' => $newStore->store_code,
                    'email' => $newStore->email,
                    'phone' => $newStore->phone,
                    'address' => $newStore->address,
                    'approval_status' => $newStore->status ?? 'pending',
                    'owner_id' => $newStore->owner_id,
                    'created_at' => $this->toIso($newStore->created_at),
                    'message' => 'Cửa hàng mới đã được tạo thành công. Vui lòng đợi admin duyệt hồ sơ.',
                ],
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('MyStoreController@store error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'SERVER_ERROR',
                        'message' => 'Có lỗi xảy ra khi tạo cửa hàng mới. Vui lòng thử lại sau.',
                    ],
                ],
            ], 500);
        }
    }

    /**
     * Remove a rejected store from the current owner's list.
     *
     * Endpoint: DELETE /api/my-stores/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Bạn chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                ]],
            ], 401);
        }

        if (!Schema::hasTable('stores')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'TABLE_NOT_FOUND',
                    'message' => 'Không tìm thấy bảng stores.',
                ]],
            ], 500);
        }

        $storeQuery = Store::query()
            ->where('id', $id)
            ->where('owner_id', $user->id);

        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        $store = $storeQuery->first();
        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'STORE_NOT_FOUND',
                    'message' => 'Không tìm thấy cửa hàng cần xóa.',
                ]],
            ], 404);
        }

        $status = strtolower((string) ($store->status ?? ''));
        if ($status !== 'rejected' && $status !== 'draft') {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'STORE_NOT_REJECTED',
                    'message' => 'Chỉ có thể xác nhận xóa cửa hàng đang bị từ chối hoặc bản nháp.',
                ]],
            ], 422);
        }

        DB::transaction(function () use ($store) {
            if (Schema::hasTable('user_role_bindings')) {
                DB::table('user_role_bindings')
                    ->where('entity_type', 'store')
                    ->where('entity_id', $store->id)
                    ->delete();
            }

            if (Schema::hasColumn('stores', 'deleted_at')) {
                $store->deleted_at = now();
                $store->save();
                return;
            }

            $store->delete();
        });

        return response()->json([
            'data' => [
                'store_id' => $store->id,
                'deleted' => true,
            ],
            'meta' => [
                'authenticated' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Generate unique store code
     * Format: ST + timestamp + random suffix
     * Example: ST-20260507-ABCD
     */
    private function generateStoreCode(): string
    {
        $timestamp = now()->format('YmdHis');
        $suffix = strtoupper(substr(Str::random(8), 0, 4));
        return "ST-{$timestamp}-{$suffix}";
    }

    /**
     * Convert datetime to ISO 8601 format
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
     * Get all permissions for a user at a specific store
     * 
     * @param string $userId
     * @param string $storeId
     * @return array
     */
    private function getPermissionsForUserStore(string $userId, string $storeId): array
    {
        if (!Schema::hasTable('store_staff_permissions')) {
            return [];
        }

        try {
            $permissions = DB::table('store_staff_permissions')
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->pluck('permission_code')
                ->map(fn($code) => (string) $code)
                ->values()
                ->all();

            return $permissions;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canUpdateStore(string $userId, Store $store): array
    {
        if ((string) $store->owner_id === $userId) {
            return [true, 'owner'];
        }

        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return [false, null];
        }

        $binding = DB::table('user_role_bindings as urb')
            ->leftJoin('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->where('urb.entity_id', $store->id)
            ->where('urb.status', 'active')
            ->whereIn('r.name', ['admin_store', 'store_manager'])
            ->select('r.name as role_name')
            ->first();

        return [$binding !== null, $binding->role_name ?? null];
    }

    private function hasValueChanged(mixed $current, mixed $new): bool
    {
        if (is_array($current) || is_array($new)) {
            return json_encode($current, JSON_UNESCAPED_UNICODE) !== json_encode($new, JSON_UNESCAPED_UNICODE);
        }

        return (string) $current !== (string) $new;
    }

    private function updateStoreLocation(string $storeId, float $lng, float $lat): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::table('stores')
                ->where('id', $storeId)
                ->update([
                    'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})', 4326)"),
                ]);

            return;
        }

        DB::table('stores')
            ->where('id', $storeId)
            ->update([
                'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
            ]);
    }

    private function getStoreLocationType(): ?string
    {
        try {
            return Schema::getColumnType('stores', 'location');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeStoreDetail(object $storeRow): array
    {
        $store = (array) $storeRow;
        $location = $this->extractLocationFromStoreRow($storeRow);

        if ($location !== null) {
            $store['location'] = $location;
        } elseif (array_key_exists('location', $store)) {
            $store['location'] = $store['location'];
        } else {
            $store['location'] = null;
        }

        $store['approval_status'] = $store['approval_status'] ?? ($store['status'] ?? null);

        foreach (['created_at', 'updated_at', 'approved_at', 'submitted_at'] as $field) {
            if (array_key_exists($field, $store)) {
                $store[$field] = $this->toIso($store[$field]);
            }
        }

        return $store;
    }

    private function extractLocationFromStoreRow(object $storeRow): ?array
    {
        $locationValue = $storeRow->location ?? null;

        if (is_string($locationValue)) {
            $decoded = json_decode($locationValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $locationValue = $decoded;
            }
        }

        if (is_array($locationValue)) {
            $lat = $locationValue['lat'] ?? null;
            $lng = $locationValue['lng'] ?? null;

            if (is_numeric($lat) && is_numeric($lng)) {
                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        try {
            if (!Schema::hasColumn('stores', 'location')) {
                return null;
            }

            $locationType = Schema::getColumnType('stores', 'location');
            if (!in_array($locationType, ['geometry', 'point', 'geography'], true)) {
                return null;
            }

            $point = DB::table('stores')
                ->where('id', $storeRow->id)
                ->selectRaw('ST_Y(location) as lat, ST_X(location) as lng')
                ->first();

            if ($point && $point->lat !== null && $point->lng !== null) {
                return [
                    'lat' => (float) $point->lat,
                    'lng' => (float) $point->lng,
                ];
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    private function normalizeLicenseItems(array $payload): array
    {
        $items = [];

        $defaultType = trim((string) ($payload['license_type'] ?? $payload['license_kind'] ?? 'GPLK'));
        if ($defaultType === '') {
            $defaultType = 'GPLK';
        }

        $defaultNumber = $payload['license_number'] ?? null;
        $defaultExpiry = $payload['license_expiry_date'] ?? $payload['expiry_date'] ?? null;

        if (!empty($payload['licenses']) && is_array($payload['licenses'])) {
            foreach ($payload['licenses'] as $license) {
                if (!is_array($license)) {
                    continue;
                }

                $type = (string) ($license['license_type'] ?? $license['type'] ?? $defaultType);
                if ($type === '') {
                    $type = $defaultType;
                }

                $items[] = [
                    'license_type' => $type,
                    'license_number' => $license['license_number'] ?? $license['number'] ?? $defaultNumber,
                    'image_url' => $license['image_url'] ?? $license['image'] ?? null,
                    'expiry_date' => $license['expiry_date'] ?? $defaultExpiry,
                ];
            }
        }

        $listKeys = ['license_images', 'license_gallery', 'license_urls'];
        foreach ($listKeys as $key) {
            if (empty($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }

            foreach ($payload[$key] as $url) {
                if (!$url) {
                    continue;
                }

                $items[] = [
                    'license_type' => $defaultType,
                    'license_number' => $defaultNumber,
                    'image_url' => $url,
                    'expiry_date' => $defaultExpiry,
                ];
            }
        }

        if (!empty($payload['license_image'])) {
            $items[] = [
                'license_type' => $defaultType,
                'license_number' => $defaultNumber,
                'image_url' => $payload['license_image'],
                'expiry_date' => $defaultExpiry,
            ];
        }

        return collect($items)
            ->filter(function (array $item) {
                return !empty($item['license_type']) && !empty($item['image_url']);
            })
            ->map(function (array $item) {
                return [
                    'license_type' => (string) $item['license_type'],
                    'license_number' => $item['license_number'] !== null ? (string) $item['license_number'] : null,
                    'image_url' => (string) $item['image_url'],
                    'expiry_date' => $item['expiry_date'] ?: null,
                ];
            })
            ->values()
            ->all();
    }

    private function hasSubmittedLicensePayload(array $payload): bool
    {
        foreach (['licenses', 'license_images', 'license_gallery', 'license_urls', 'license_image'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_array($value)) {
                return true;
            }

            if (is_string($value)) {
                return trim($value) !== '';
            }

            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    private function loadEditableLicenseItems(string $storeId): array
    {
        if (!Schema::hasTable('store_licenses')) {
            return [];
        }

        return StoreLicense::query()
            ->where('store_id', $storeId)
            ->whereIn('status', ['pending', 'rejected'])
            ->orderBy('created_at')
            ->get()
            ->map(function (StoreLicense $license) {
                return [
                    'license_type' => (string) ($license->license_type ?? 'GPLK'),
                    'license_number' => $license->license_number !== null ? (string) $license->license_number : null,
                    'image_url' => (string) ($license->image_url ?? ''),
                    'expiry_date' => $license->expiry_date?->toDateString(),
                ];
            })
            ->all();
    }

    private function normalizeComparableLicenseItems(array $items): array
    {
        $normalized = collect($items)
            ->map(function (array $item) {
                return [
                    'license_type' => trim((string) ($item['license_type'] ?? '')),
                    'license_number' => isset($item['license_number']) ? trim((string) $item['license_number']) : null,
                    'image_url' => trim((string) ($item['image_url'] ?? '')),
                    'expiry_date' => isset($item['expiry_date']) && $item['expiry_date'] !== null
                        ? trim((string) $item['expiry_date'])
                        : null,
                ];
            })
            ->sortBy([
                ['license_type', 'asc'],
                ['license_number', 'asc'],
                ['image_url', 'asc'],
                ['expiry_date', 'asc'],
            ])
            ->values();

        return $normalized->all();
    }

    private function syncEditableLicenses(Store $store, array $licenseItems): void
    {
        StoreLicense::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['pending', 'rejected'])
            ->delete();

        if (empty($licenseItems)) {
            return;
        }

        $now = now();
        foreach ($licenseItems as $item) {
            StoreLicense::query()->create([
                'store_id' => $store->id,
                'license_type' => $item['license_type'],
                'license_number' => $item['license_number'],
                'image_url' => $item['image_url'],
                'expiry_date' => $item['expiry_date'],
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveNotificationOwner(string $ownerId, object $fallbackUser): object
    {
        if (($fallbackUser->id ?? null) === $ownerId) {
            return $fallbackUser;
        }

        $owner = DB::table('users')
            ->select(['id', 'name', 'full_name', 'email'])
            ->where('id', $ownerId)
            ->first();

        return $owner ?? $fallbackUser;
    }

    private function createPartnerRegistrationNotifications(Store $store, object $owner): void
    {
        $storeCode = trim((string) ($store->store_code ?? ''));
        $storeLabel = trim((string) ($store->name ?? ''));
        $ownerLabel = trim((string) ($owner->full_name ?? $owner->name ?? $owner->email ?? ''));

        if ($storeCode === '') {
            $storeCode = (string) $store->id;
        }

        if ($storeLabel === '') {
            $storeLabel = 'Cua hang chua dat ten';
        }

        if ($ownerLabel === '') {
            $ownerLabel = 'User #' . (string) ($owner->id ?? 'unknown');
        }

        try {
            $this->notificationService->create([
                'audience_scope' => 'admin_shared',
                'type' => 'system',
                'title' => 'Đăng ký đối tác mới',
                'body' => 'Có hồ sơ đăng ký đối tác mới cần duyệt: ' . $storeCode . ' - ' . $storeLabel . '.',
                'message' => 'Có hồ sơ đăng ký đối tác mới cần duyệt. Cửa hàng: ' . $storeCode . ' - ' . $storeLabel . '. Chủ hồ sơ: ' . $ownerLabel . '.',
                'action_url' => self::ACTION_KEY_ADMIN_STORE_APPROVALS_PENDING,
            ]);

            $this->notificationService->create([
                'audience_scope' => 'user_private',
                'user_id' => (string) ($owner->id ?? ''),
                'type' => 'system',
                'title' => 'Gửi đăng ký đối tác thành công',
                'body' => 'Hồ sơ đăng ký đối tác của bạn đã được ghi nhận và đang chờ duyệt.',
                'message' => 'Bạn đã gửi đăng ký đối tác thành công cho cửa hàng ' . $storeLabel . '. Hệ thống sẽ thông báo ngay khi có kết quả duyệt.',
                'action_url' => self::ACTION_KEY_CLIENT_STORE_PARTNER_REGISTRATION,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
