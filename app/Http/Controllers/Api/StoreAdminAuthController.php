<?php

namespace App\Http\Controllers\Api;

use App\Events\StoreAdminProductUpdated;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreHour;
use App\Models\StoreStaffPermission;
use App\Models\UserRoleBinding;
use App\Services\StoreAdmin\StorePermissionCatalog;
use App\Support\SafeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreAdminAuthController extends Controller
{
    public function entry(Request $request): JsonResponse
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

        $store = Store::query()
            ->where('owner_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'STORE_NOT_FOUND',
                        'message' => 'Bạn chưa có hồ sơ đăng ký cửa hàng.',
                    ],
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $store->id,
                'name' => $store->name,
                'status' => $store->status,
                'rejection_reason' => $store->rejection_reason,
                'approved_at' => optional($store->approved_at)?->toIso8601String(),
                'details' => [
                    'address' => $store->address,
                    'phone' => $store->phone,
                    'license_image' => $store->license_image,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $context = $this->resolveAdminAccessContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $user = $context['user'];
        $store = $context['store'];
        $permissions = $context['permissions'];
        $canAccessAdminStore = $context['can_access_admin_store'];
        $canManageAdminStore = $context['can_manage_admin_store'];
        $accountRole = $context['account_role'];

        return response()->json([
            'data' => [
                'account_role' => $accountRole,
                'user_role' => $accountRole,
                'approval_status' => $store ? (string) $store->status : 'unknown',
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name ?? $user->name,
                    'role' => $user->role,
                    'avatar_url' => $user->avatar_url,
                ],
                'store' => $store ? [
                    'id' => $store->id,
                    'name' => $store->name,
                    'store_code' => $store->store_code,
                    'status' => $store->status,
                    'approval_status' => $store->status,
                    'rejection_reason' => $store->rejection_reason,
                    'updated_at' => optional($store->updated_at)?->toIso8601String(),
                ] : null,
                'store_id' => $store ? (string) $store->id : null,
                'store_name' => $store ? (string) $store->name : null,
                'approval_status' => $store ? (string) $store->status : 'unknown',
                'permissions' => array_values($permissions),
                'can_access_admin_store' => $canAccessAdminStore,
                'can_manage_admin_store' => $canManageAdminStore,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => (bool) $store,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function resolveAdminAccessContext(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [
                'user' => null,
                'store' => null,
                'permissions' => [],
                'can_access_admin_store' => false,
                'can_manage_admin_store' => false,
                'account_role' => null,
                'error' => response()->json([
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
                ], 401),
            ];
        }

        $requestedStoreId = $this->resolveRequestedStoreId($request);
        if ($requestedStoreId !== null) {
            $storeQuery = Store::query()->where('id', $requestedStoreId);
            if (Schema::hasColumn('stores', 'deleted_at')) {
                $storeQuery->whereNull('deleted_at');
            }

            $store = $storeQuery->first();
            if (!$store) {
                return [
                    'user' => $user,
                    'store' => null,
                    'permissions' => [],
                    'can_access_admin_store' => false,
                    'can_manage_admin_store' => false,
                    'account_role' => null,
                    'error' => response()->json([
                        'data' => null,
                        'meta' => [
                            'authenticated' => true,
                            'request_at' => now()->toIso8601String(),
                        ],
                        'errors' => [
                            [
                                'code' => 'STORE_NOT_FOUND',
                                'message' => 'Khong tim thay cua hang.',
                            ],
                        ],
                    ], 404),
                ];
            }

            $isOwner = (string) $store->owner_id === (string) $user->id;
            if ($isOwner) {
                $storeStatus = strtolower((string) ($store->status ?? ''));
                $isApproved = in_array($storeStatus, ['approved', 'active'], true);
                $isPending = ($storeStatus === 'pending');
                $isRejected = ($storeStatus === 'rejected');

                if ($isApproved) {
                    return [
                        'user' => $user,
                        'store' => $store,
                        'permissions' => $this->resolveOwnerPermissions(),
                        'can_access_admin_store' => true,
                        'can_manage_admin_store' => true,
                        'account_role' => 'admin_store_active',
                        'error' => null,
                    ];
                }

                if ($isPending) {
                    return [
                        'user' => $user,
                        'store' => $store,
                        'permissions' => [],
                        'can_access_admin_store' => true,
                        'can_manage_admin_store' => false,
                        'account_role' => 'admin_store_pending',
                        'error' => null,
                    ];
                }

                if ($isRejected) {
                    return [
                        'user' => $user,
                        'store' => $store,
                        'permissions' => [],
                        'can_access_admin_store' => false,
                        'can_manage_admin_store' => false,
                        'account_role' => 'account_user',
                        'error' => null,
                    ];
                }
            }

            $bindingQuery = UserRoleBinding::query()
                ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
                ->where('user_role_bindings.user_id', $user->id)
                ->where('user_role_bindings.entity_type', 'store')
                ->where('user_role_bindings.entity_id', (string) $store->id)
                ->whereIn('roles.name', ['store_staff', 'store_manager', 'store_custom']);

            if (Schema::hasColumn('user_role_bindings', 'status')) {
                $bindingQuery->where('user_role_bindings.status', 'active');
            }

            $binding = $bindingQuery
                ->select([
                    'user_role_bindings.entity_id',
                    'roles.name as role_name',
                ])
                ->orderByDesc('user_role_bindings.id')
                ->first();

            if ($binding) {
                $roleName = (string) $binding->role_name;
                $canManage = ($roleName === 'store_manager');

                return [
                    'user' => $user,
                    'store' => $store,
                    'permissions' => $this->resolveStaffPermissions((int) $user->id, (string) $store->id, $roleName),
                    'can_access_admin_store' => true,
                    'can_manage_admin_store' => $canManage,
                    'account_role' => $roleName,
                    'error' => null,
                ];
            }

            return [
                'user' => $user,
                'store' => null,
                'permissions' => [],
                'can_access_admin_store' => false,
                'can_manage_admin_store' => false,
                'account_role' => null,
                'error' => response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [
                        [
                            'code' => 'FORBIDDEN_ROLE',
                            'message' => 'Tài khoản hiện tại chưa được gán quyền truy cập cửa hàng.',
                        ],
                    ],
                ], 403),
            ];
        }

        $store = Store::query()
            ->where('owner_id', $user->id)
            ->orderByDesc('updated_at')
            ->first();

        $isOwner = (bool) $store;
        $permissionCodes = [];
        $canAccessAdminStore = false;
        $canManageAdminStore = false;
        $accountRole = null;

        if ($store) {
            $storeStatus = strtolower((string) ($store->status ?? ''));
            $isApproved = in_array($storeStatus, ['approved', 'active'], true);
            $isPending  = ($storeStatus === 'pending');
            $isRejected = ($storeStatus === 'rejected');

            if ($isApproved) {
                $canAccessAdminStore = true;
                $canManageAdminStore = true;
                $accountRole = 'admin_store_active';
                $permissionCodes = $this->resolveOwnerPermissions();
            } elseif ($isPending) {
                $canAccessAdminStore = true;
                $canManageAdminStore = false;
                $accountRole = 'admin_store_pending';
            } elseif ($isRejected) {
                // Rejected owner: still return store info so frontend can display rejected status
                // but do NOT grant any admin access
                $canAccessAdminStore = false;
                $canManageAdminStore = false;
                $accountRole = 'account_user';
            }
        } else {
            $bindingQuery = UserRoleBinding::query()
                ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
                ->where('user_role_bindings.user_id', $user->id)
                ->where('user_role_bindings.entity_type', 'store')
                ->whereIn('roles.name', ['store_staff', 'store_manager', 'store_custom']);

            if (Schema::hasColumn('user_role_bindings', 'status')) {
                $bindingQuery->where('user_role_bindings.status', 'active');
            }

            $binding = $bindingQuery
                ->orderByDesc('user_role_bindings.id')
                ->select([
                    'user_role_bindings.entity_id',
                    'roles.name as role_name',
                ])
                ->first();

            if ($binding) {
                $store = Store::query()->where('id', $binding->entity_id)->first();

                if ($store) {
                    $canAccessAdminStore = true;
                    $accountRole = (string) $binding->role_name;
                    $canManageAdminStore = ((string) $binding->role_name === 'store_manager');
                    $permissionCodes = $this->resolveStaffPermissions((int) $user->id, (string) $store->id, (string) $binding->role_name);
                }
            }
        }

        if (!$canAccessAdminStore) {
            // For rejected owners, return 200 with rejected status instead of 403
            if ($store && strtolower((string) ($store->status ?? '')) === 'rejected') {
                return [
                    'user' => $user,
                    'store' => $store,
                    'permissions' => [],
                    'can_access_admin_store' => false,
                    'can_manage_admin_store' => false,
                    'account_role' => 'account_user',
                    'error' => null,
                ];
            }

            // For pending owners, return 200 with pending status
            if ($store && strtolower((string) ($store->status ?? '')) === 'pending') {
                return [
                    'user' => $user,
                    'store' => $store,
                    'permissions' => [],
                    'can_access_admin_store' => true,
                    'can_manage_admin_store' => false,
                    'account_role' => 'admin_store_pending',
                    'error' => null,
                ];
            }

            return [
                'user' => $user,
                'store' => null,
                'permissions' => [],
                'can_access_admin_store' => false,
                'can_manage_admin_store' => false,
                'account_role' => null,
                'error' => response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [
                        [
                            'code' => 'FORBIDDEN_ROLE',
                            'message' => 'Tài khoản hiện tại chưa được gán quyền truy cập cửa hàng.',
                        ],
                    ],
                ], 403),
            ];
        }

        return [
            'user' => $user,
            'store' => $store,
            'permissions' => $permissionCodes,
            'can_access_admin_store' => true,
            'can_manage_admin_store' => $canManageAdminStore,
            'account_role' => $accountRole,
            'error' => null,
        ];
    }

    private function resolveOwnerPermissions(): array
    {
        if (!Schema::hasTable('permissions')) {
            return [];
        }

        return \App\Models\Permission::query()
            ->orderBy('code')
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();
    }

    private function resolveStaffPermissions(int $userId, string $storeId, string $roleName): array
    {
        if (!Schema::hasTable('store_staff_permissions')) {
            return [];
        }

        $permissions = StoreStaffPermission::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();

        if (!empty($permissions)) {
            return $permissions;
        }

        if ($roleName === 'store_manager') {
            return $this->resolveOwnerPermissions();
        }

        return $permissions;
    }

    private function resolveRequestedStoreId(Request $request): ?string
    {
        $storeId = trim((string) $request->query('store_id', ''));
        if ($storeId !== '') {
            return $storeId;
        }

        $attributeStoreId = trim((string) $request->attributes->get('store_context_id', ''));
        if ($attributeStoreId !== '') {
            return $attributeStoreId;
        }

        $headerStoreId = trim((string) $request->header('X-Store-Id', ''));
        if ($headerStoreId !== '') {
            return $headerStoreId;
        }

        return null;
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $store = $context['store'];
        $user = $context['user'];
        $accessContext = $this->resolveAdminAccessContext($request);
        if (!$accessContext['error']) {
            $user = $accessContext['user'];
            $store = $accessContext['store'] ?? $store;
        }

        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'STORE_NOT_FOUND',
                        'message' => 'Không tìm thấy cửa hàng thuộc quyền quản lý của tài khoản này.',
                    ],
                ],
            ], 404);
        }

        $pendingOrdersCount = 0;
        $newComplaintsCount = 0;
        $slaAlertsCount = 0;

        if (Schema::hasTable('orders')) {
            $pendingOrdersQuery = DB::table('orders')
                ->where('store_id', $store->id);

            if (Schema::hasColumn('orders', 'status')) {
                $pendingOrdersQuery->where('status', 'pending');
            }

            $pendingOrdersCount = (int) $pendingOrdersQuery->count();

            $slaAlertsQuery = DB::table('orders')
                ->where('store_id', $store->id);

            if (Schema::hasColumn('orders', 'status')) {
                $slaAlertsQuery->whereNotIn(DB::raw('LOWER(status)'), [
                    'delivered',
                    'canceled',
                    'cancelled',
                    'timeout',
                    'disputed',
                    'rejected',
                ]);
            }

            $hasSlaWarningSent = Schema::hasColumn('orders', 'sla_warning_sent');
            $hasCreatedAt = Schema::hasColumn('orders', 'created_at');
            $hasSlaTimeoutMinutes = Schema::hasColumn('orders', 'sla_timeout_minutes');

            if ($hasSlaWarningSent || ($hasCreatedAt && $hasSlaTimeoutMinutes)) {
                $slaAlertsQuery->where(function ($q) use ($hasSlaWarningSent, $hasCreatedAt, $hasSlaTimeoutMinutes) {
                    if ($hasSlaWarningSent) {
                        $q->orWhere('sla_warning_sent', true);
                    }

                    if ($hasCreatedAt && $hasSlaTimeoutMinutes) {
                        $warningThreshold = now()->addMinutes(2)->toDateTimeString();
                        $driver = DB::connection()->getDriverName();

                        if ($driver === 'pgsql') {
                            $q->orWhereRaw("(created_at + (COALESCE(sla_timeout_minutes, 0) || ' minutes')::interval) <= ?", [$warningThreshold]);
                        } else {
                            $q->orWhereRaw('TIMESTAMPADD(MINUTE, COALESCE(sla_timeout_minutes, 0), created_at) <= ?', [$warningThreshold]);
                        }
                    }
                });

                $slaAlertsCount = (int) $slaAlertsQuery->count();
            }
        }

        if (Schema::hasTable('complaints')) {
            $newComplaintsQuery = DB::table('complaints')
                ->where('store_id', $store->id);

            if (Schema::hasColumn('complaints', 'status')) {
                $newComplaintsQuery->where('status', 'pending');
            }

            $newComplaintsCount = (int) $newComplaintsQuery->count();
        }

        $includes = collect(explode(',', (string) $request->query('include', 'profile,settings,badges')))
            ->map(fn ($item) => trim(strtolower($item)))
            ->filter()
            ->values()
            ->all();
        $shouldInclude = fn (string $key): bool => in_array($key, $includes, true);
        $permissions = $accessContext['permissions'] ?? [];
        $accountRole = $accessContext['account_role'] ?? $user?->role ?? 'admin_store';
        $canAccessAdminStore = (bool) ($accessContext['can_access_admin_store'] ?? true);
        $canManageAdminStore = (bool) ($accessContext['can_manage_admin_store'] ?? true);

        $data = [
            'me' => [
                'account_role' => $accountRole,
                'user_role' => $accountRole,
                'approval_status' => (string) ($store->status ?? 'unknown'),
                'user' => [
                    'id' => $user?->id,
                    'full_name' => $user?->full_name ?? $user?->name,
                    'role' => $user?->role,
                    'avatar_url' => $user?->avatar_url,
                ],
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'store_code' => $store->store_code,
                    'status' => $store->status,
                    'approval_status' => $store->status,
                    'is_open' => (bool) $store->is_open,
                    'rejection_reason' => $store->rejection_reason,
                    'updated_at' => optional($store->updated_at)?->toIso8601String(),
                ],
                'store_id' => (string) $store->id,
                'store_name' => (string) $store->name,
                'permissions' => $permissions,
                'can_access_admin_store' => $canAccessAdminStore,
                'can_manage_admin_store' => $canManageAdminStore,
                'has_store_admin_role' => $canAccessAdminStore || $canManageAdminStore,
                'receiving_orders' => (bool) $store->is_open,
                'temporary_break' => !(bool) $store->is_open,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'store_code' => $store->store_code,
                'status' => $store->status,
                'is_open' => (bool) $store->is_open,
            ],
            'permissions' => $permissions,
        ];

        if ($shouldInclude('profile')) {
            $data['profileLight'] = [
                'store' => $this->serializeStore($store),
                'store_status' => strtolower((string) ($store->status ?? 'draft')),
                'user_role' => $accountRole,
                'account_role' => $accountRole,
                'rejection_reason' => $store->rejection_reason,
                'can_access_admin_store' => $canAccessAdminStore,
                'can_manage_admin_store' => $canManageAdminStore,
                'profile_completion' => $this->profileCompletion($store),
            ];
        }

        if ($shouldInclude('settings')) {
            $data['settings'] = $this->serializeBootstrapSettings($this->resolveBootstrapSettings($user?->id));
        }

        if ($shouldInclude('badges')) {
            $data['badges'] = [
                'pending_orders' => $pendingOrdersCount,
                'sla_alerts' => $slaAlertsCount,
                'new_complaints' => $newComplaintsCount,
            ];
        }

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

    private function resolveBootstrapSettings(mixed $userId): array
    {
        $defaults = [
            'notif_order' => true,
            'notif_via_zalo_oa' => true,
            'notif_via_zalo_miniapp' => true,
            'language' => 'vi',
            'theme' => 'light',
            'hide_review_banner' => false,
        ];

        if (!$userId || !Schema::hasTable('user_settings')) {
            return $defaults;
        }

        $setting = DB::table('user_settings')->where('user_id', $userId)->first();
        if (!$setting) {
            return $defaults;
        }

        return [
            'notif_order' => property_exists($setting, 'notif_order') ? (bool) $setting->notif_order : $defaults['notif_order'],
            'notif_via_zalo_oa' => property_exists($setting, 'notif_via_zalo_oa') ? (bool) $setting->notif_via_zalo_oa : $defaults['notif_via_zalo_oa'],
            'notif_via_zalo_miniapp' => property_exists($setting, 'notif_via_zalo_miniapp') ? (bool) $setting->notif_via_zalo_miniapp : $defaults['notif_via_zalo_miniapp'],
            'language' => property_exists($setting, 'language') && $setting->language ? (string) $setting->language : $defaults['language'],
            'theme' => property_exists($setting, 'theme') && $setting->theme ? (string) $setting->theme : $defaults['theme'],
            'hide_review_banner' => property_exists($setting, 'hide_review_banner') ? (bool) $setting->hide_review_banner : $defaults['hide_review_banner'],
        ];
    }

    private function serializeBootstrapSettings(array $settings): array
    {
        return [
            'settings' => [
                'notifications' => [
                    'notif_order' => (bool) $settings['notif_order'],
                    'notif_via_zalo_oa' => (bool) $settings['notif_via_zalo_oa'],
                    'notif_via_zalo_miniapp' => (bool) $settings['notif_via_zalo_miniapp'],
                ],
                'language' => (string) $settings['language'],
                'interface_mode' => (string) $settings['theme'],
                'theme' => (string) $settings['theme'],
                'hide_review_banner' => (bool) $settings['hide_review_banner'],
            ],
        ];
    }

    public function profile(Request $request): JsonResponse
    {
        [$store, $user, $error, $context] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $role = strtolower((string) ($context['account_role'] ?? $user->role ?? ''));
        $status = strtolower((string) ($store->status ?? 'draft'));

        return $this->successResponse([
            'store' => $this->serializeStore($store),
            'store_status' => $status,
            'user_role' => $role,
            'account_role' => $role,
            'rejection_reason' => $store->rejection_reason,
            'can_access_admin_store' => (bool) ($context['can_access_admin_store'] ?? in_array($role, ['admin_store_pending', 'admin_store', 'store_admin'], true)),
            'can_manage_admin_store' => (bool) ($context['can_manage_admin_store'] ?? (in_array($status, ['approved', 'active'], true) && in_array($role, ['admin_store', 'store_admin'], true))),
            'profile_completion' => $this->profileCompletion($store),
        ], true, true);
    }

    public function profileStatus(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $isWithinSchedule = null;
        if (Schema::hasTable('store_hours')) {
            $now = now();
            $dayOfWeek = (int) $now->dayOfWeek;
            $currentTime = $now->format('H:i:s');

            $hour = StoreHour::query()
                ->where('store_id', $store->id)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if ($hour) {
                if ((bool) $hour->is_closed) {
                    $isWithinSchedule = false;
                } elseif ($hour->open_time && $hour->close_time) {
                    $isWithinSchedule = $currentTime >= $hour->open_time && $currentTime < $hour->close_time;
                }
            }
        }

        $approvalStatus = (string) ($store->status ?? 'pending');
        $isApproved = !in_array(strtolower($approvalStatus), ['pending', 'rejected'], true);
        $canReceiveOrders = (bool) $store->is_open && $isApproved;
        if ($isWithinSchedule !== null) {
            $canReceiveOrders = $canReceiveOrders && $isWithinSchedule;
        }

        return $this->successResponse([
            'store' => [
                'id' => $store->id,
                'is_open' => (bool) $store->is_open,
                'status' => $approvalStatus,
                'updated_at' => optional($store->updated_at)?->toIso8601String(),
                'is_within_schedule' => $isWithinSchedule,
                'can_receive_orders' => $canReceiveOrders,
            ],
        ], true, true);
    }

    public function approvalHistory(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        [$validated, $validationError] = $this->validateRequest($request, [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validationError) {
            return $validationError;
        }

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 10);

        $history = $this->buildApprovalHistory($store);
        $total = count($history);
        $offset = ($page - 1) * $pageSize;
        $pagedData = array_slice($history, $offset, $pageSize);

        return response()->json([
            'data' => array_values($pagedData),
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
            'errors' => [],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $slugRules = ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        if (Schema::hasColumn('stores', 'slug')) {
            $slugRules[] = 'unique:stores,slug,' . $store->id;
        }

        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        [$validated, $validationError] = $this->validateRequest($request, [
            'name' => ['nullable', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'slug' => $slugRules,
            'description' => ['nullable', 'string', 'max:5000'],
            'brand_color' => ['nullable', 'string', 'max:10', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule()],
            'business_type' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'representative' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'url', 'max:2000'],
            'cover_image' => ['nullable', 'url', 'max:2000'],
            'banner_url' => ['nullable', 'url', 'max:2000'],
            'banner_urls' => ['nullable', 'array', 'max:10'],
            'banner_urls.*' => ['required', 'url', 'max:2000'],
            'open_time' => ['nullable', 'date_format:H:i:s'],
            'close_time' => ['nullable', 'date_format:H:i:s'],
            'min_order_tickets' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_order_tickets' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'zalo_link' => ['nullable', 'url', 'max:2000'],
            'facebook_link' => ['nullable', 'url', 'max:2000'],
            // location as nested object { lat, lng }
            'location' => ['nullable', 'array'],
            'location.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location.lng' => ['nullable', 'numeric', 'between:-180,180'],
            // location as flat fields (fallback for multipart/form-data or flat JSON)
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($validationError) {
            return $validationError;
        }

        if (array_key_exists('min_order_tickets', $validated) && array_key_exists('max_order_tickets', $validated)) {
            if ((int) $validated['min_order_tickets'] > (int) $validated['max_order_tickets']) {
                return $this->errorResponse(
                    'INVALID_TICKET_RANGE',
                    'Giới hạn vé tối thiểu không được lớn hơn giới hạn vé tối đa.',
                    422,
                    true,
                    true
                );
            }
        }

        $updatableFields = [
            'name',
            'slogan',
            'description',
            'brand_color',
            'address',
            'phone',
            'email',
            'business_type',
            'license_number',
            'representative',
            'bank_name',
            'bank_account',
            'logo_url',
            'cover_image',
            'banner_url',
            'open_time',
            'close_time',
            'min_order_tickets',
            'max_order_tickets',
            'zalo_link',
            'facebook_link',
        ];

        $payload = [];
        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (Schema::hasColumn('stores', 'banner_urls') && array_key_exists('banner_urls', $validated)) {
            $bannerUrls = $this->normalizeStoreBannerUrlList($validated['banner_urls'] ?? []);
            $payload['banner_urls'] = $bannerUrls;
            $payload['banner_url'] = $payload['banner_url'] ?? ($bannerUrls[0] ?? null);
        }

        if (array_key_exists('slug', $validated)) {
            $requestedSlug = $validated['slug'] !== null ? (string) $validated['slug'] : null;
            if ($requestedSlug !== null && $requestedSlug !== '') {
                $payload['slug'] = $this->generateUniqueStoreSlug($requestedSlug, $store->id);
            }
        } elseif (!empty($validated['name'] ?? null) && empty($store->slug)) {
            $payload['slug'] = $this->generateUniqueStoreSlug($validated['name'], $store->id);
        }

        if (!empty($payload['slug']) && Schema::hasColumn('stores', 'public_url')) {
            $payload['public_url'] = $this->buildStorePublicUrl($payload['slug']);
        }

        if (!empty($payload)) {
            $store->fill($payload);
            $store->save();
        }

        // Resolve lat/lng: prefer nested location.lat/lng, fallback to flat lat/lng fields
        $lat = $request->input('location.lat', $request->input('lat'));
        $lng = $request->input('location.lng', $request->input('lng'));

        $locationData = null;
        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng) && Schema::hasColumn('stores', 'location')) {
            $lat = (float) $lat;
            $lng = (float) $lng;
            $this->updateStoreLocation($store->id, $lng, $lat);
            $locationData = ['lat' => $lat, 'lng' => $lng];
        } else {
            // Keep existing location — fetch from DB so response always includes it
            $locationData = $this->fetchStoreLocation($store->id);
        }

        return $this->successResponse([
            'store' => $this->serializeStore($store, $locationData),
        ], true, true);
    }

    public function getOperatingSchedule(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        return $this->successResponse([
            'store_id' => $store->id,
            'operating_schedule' => $this->buildOperatingSchedule($store),
        ], true, true);
    }

    public function updateOperatingSchedule(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        [$validated, $validationError] = $this->validateRequest($request, [
            'schedules' => ['required', 'array', 'min:1', 'max:7'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'schedules.*.is_closed' => ['required', 'boolean'],
            'schedules.*.open_time' => ['nullable', 'date_format:H:i:s'],
            'schedules.*.close_time' => ['nullable', 'date_format:H:i:s'],
        ]);

        if ($validationError) {
            return $validationError;
        }

        $schedules = $validated['schedules'];

        foreach ($schedules as $index => $item) {
            $isClosed = (bool) $item['is_closed'];
            $openTime = $item['open_time'] ?? null;
            $closeTime = $item['close_time'] ?? null;

            if (!$isClosed && (!$openTime || !$closeTime)) {
                return $this->errorResponse(
                    'INVALID_SCHEDULE_TIME',
                    'open_time và close_time là bắt buộc khi is_closed = false.',
                    422,
                    true,
                    true,
                    ['index' => $index, 'day_of_week' => $item['day_of_week']]
                );
            }

            if (!$isClosed && $openTime >= $closeTime) {
                return $this->errorResponse(
                    'INVALID_SCHEDULE_RANGE',
                    'open_time phải nhỏ hơn close_time.',
                    422,
                    true,
                    true,
                    ['index' => $index, 'day_of_week' => $item['day_of_week']]
                );
            }
        }

        DB::transaction(function () use ($store, $schedules) {
            foreach ($schedules as $item) {
                $isClosed = (bool) $item['is_closed'];

                StoreHour::query()->updateOrCreate(
                    [
                        'store_id' => $store->id,
                        'day_of_week' => (int) $item['day_of_week'],
                    ],
                    [
                        'open_time' => $isClosed ? null : ($item['open_time'] ?? null),
                        'close_time' => $isClosed ? null : ($item['close_time'] ?? null),
                        'is_closed' => $isClosed,
                    ]
                );
            }
        });

        $activeHours = StoreHour::query()
            ->where('store_id', $store->id)
            ->where('is_closed', false)
            ->whereNotNull('open_time')
            ->whereNotNull('close_time')
            ->get();

        if ($activeHours->isNotEmpty()) {
            $store->open_time = (string) $activeHours->min('open_time');
            $store->close_time = (string) $activeHours->max('close_time');
            $store->save();
            $store->refresh();
        }

        return $this->successResponse([
            'store_id' => $store->id,
            'operating_schedule' => $this->buildOperatingSchedule($store),
            'store' => [
                'open_time' => $store->open_time,
                'close_time' => $store->close_time,
            ],
        ], true, true);
    }

    public function updateOperatingState(Request $request): JsonResponse
    {
        $context = $this->resolvePermissionScopedContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $store = $context['store'];
        $user = $context['user'];

        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'STORE_NOT_FOUND',
                        'message' => 'Không tìm thấy cửa hàng thuộc quyền quản lý của tài khoản này.',
                    ],
                ],
            ], 404);
        }

        [$validated, $validationError] = $this->validateRequest($request, [
            'is_open' => ['nullable', 'boolean'],
            'receiving_orders' => ['nullable', 'boolean'],
            'temporary_break' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validationError) {
            return $validationError;
        }

        $hasIsOpen = array_key_exists('is_open', $validated);
        $hasReceivingOrders = array_key_exists('receiving_orders', $validated);
        $hasTemporaryBreak = array_key_exists('temporary_break', $validated);

        if (!$hasIsOpen && !$hasReceivingOrders && !$hasTemporaryBreak) {
            return $this->errorResponse(
                'MISSING_OPERATING_STATE',
                'Yeu cau phai co it nhat mot trong cac truong: is_open, receiving_orders, temporary_break.',
                422,
                true,
                true
            );
        }

        $requestedOpenState = false;

        if ($hasIsOpen) {
            $requestedOpenState = (bool) $validated['is_open'];

            if ($hasReceivingOrders && $requestedOpenState !== (bool) $validated['receiving_orders']) {
                return $this->errorResponse(
                    'CONFLICT_OPERATING_STATE',
                    'is_open va receiving_orders khong dong nhat.',
                    422,
                    true,
                    true
                );
            }

            if ($hasTemporaryBreak && (bool) $validated['temporary_break'] === true && $requestedOpenState) {
                return $this->errorResponse(
                    'CONFLICT_OPERATING_STATE',
                    'Khong the temporary_break=true khi is_open=true.',
                    422,
                    true,
                    true
                );
            }
        } else {
            $temporaryBreakInput = $hasTemporaryBreak ? (bool) $validated['temporary_break'] : false;

            if ($temporaryBreakInput) {
                $requestedOpenState = false;
            } elseif ($hasReceivingOrders) {
                $requestedOpenState = (bool) $validated['receiving_orders'];
            }
        }

        $reason = $validated['reason'] ?? null;
        if ($reason === null && $hasTemporaryBreak && (bool) $validated['temporary_break'] === true) {
            $reason = 'temporary_break';
        }

        if ($reason === null && !$requestedOpenState) {
            $reason = 'manual_pause';
        }

        $currentStatus = strtolower((string) $store->status);

        if ($requestedOpenState && in_array($currentStatus, ['pending', 'rejected'], true)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'STORE_NOT_APPROVED',
                        'message' => 'Cửa hàng chưa được duyệt, không thể bật trạng thái nhận đơn.',
                    ],
                ],
            ], 422);
        }

        $oldIsOpen = (bool) $store->is_open;

        $payload = [
            'is_open' => $requestedOpenState,
        ];

        if (Schema::hasColumn('stores', 'temporary_close_reason')) {
            $payload['temporary_close_reason'] = $reason;
        }

        if (Schema::hasColumn('stores', 'out_of_stock_reason')) {
            $payload['out_of_stock_reason'] = $reason;
        }

        $store->fill($payload);
        $store->save();
        $store->refresh();

        $normalizedReason = strtolower((string) ($reason ?? ''));
        $temporaryBreak = !$requestedOpenState && (
            ($hasTemporaryBreak && (bool) $validated['temporary_break'] === true)
            || str_contains($normalizedReason, 'temporary')
            || str_contains($normalizedReason, 'tam nghi')
        );

        Event::dispatch('store.operating_status_changed', [
            'store_id' => $store->id,
            'old_is_open' => $oldIsOpen,
            'new_is_open' => (bool) $store->is_open,
            'reason' => $reason,
            'status' => $store->status,
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        if ($oldIsOpen !== (bool) $store->is_open) {
            event(new StoreAdminProductUpdated(
                (string) $store->id,
                '',
                'store_operating_state_changed',
                ['is_open' => (bool) $store->is_open]
            ));
        }

        return response()->json([
            'data' => [
                'store' => $this->serializeStore($store),
                'operating_state' => [
                    'is_open' => (bool) $store->is_open,
                    'receiving_orders' => (bool) $store->is_open,
                    'temporary_break' => $temporaryBreak,
                    'reason' => $reason,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function uploadProfileImage(Request $request): JsonResponse
    {
        [$validated, $validationError] = $this->validateRequest($request, [
            'store_id' => ['required', 'string'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'type' => ['required', 'string', 'in:logo,cover,banner'],
        ]);

        if ($validationError) {
            return $validationError;
        }

        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if ((string) $store->id !== (string) $validated['store_id']) {
            return $this->errorResponse(
                'STORE_MISMATCH',
                'Cửa hàng được yêu cầu không khớp với cửa hàng được xử lý.',
                400,
                true,
                true
            );
        }

        $type = $validated['type'];
        $column = match ($type) {
            'logo' => 'logo_url',
            'cover' => 'cover_image',
            'banner' => 'banner_url',
            default => 'logo_url',
        };

        if (!Schema::hasColumn('stores', $column)) {
            return $this->errorResponse(
                'COLUMN_NOT_FOUND',
                'Cấu trúc bảng stores chưa có trường ảnh cần cập nhật.',
                500,
                true,
                true
            );
        }

        $file = $request->file('image');
        $path = $file->store("uploads/stores/{$store->id}", 'public');
        $absoluteUrl = url(Storage::url($path));

        $oldValue = (string) ($store->{$column} ?? '');
        $store->{$column} = $absoluteUrl;

        if ($type === 'banner' && Schema::hasColumn('stores', 'banner_urls')) {
            $store->banner_urls = $this->normalizeStoreBannerUrlList([
                $absoluteUrl,
                ...$this->normalizeStoreBannerUrlList($store->banner_urls ?? [], $oldValue ?: null),
            ]);
        }

        $store->save();
        $store->refresh();

        if ($type !== 'banner' && !empty($oldValue)) {
            $oldPath = $this->extractStoragePath($oldValue);
            if ($oldPath && $oldPath !== $path && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        return $this->successResponse([
            'store' => $this->serializeStore($store),
            'image' => [
                'type' => $type,
                'url' => $absoluteUrl,
                'path' => $path,
            ],
        ], true, true);
    }

    /**
     * Serialize a store model to an API-ready array.
     *
     * @param  Store       $store        The store Eloquent model.
     * @param  array|null  $locationData Pre-fetched ['lat'=>float,'lng'=>float] or null.
     *                                   When null and the stores table has a location column,
     *                                   the method will query ST_X/ST_Y from the DB.
     */
    private function serializeStore(Store $store, ?array $locationData = null): array
    {
        $store->loadMissing('salePerson');
        $salePerson = $store->salePerson;
        $publicUrl = $store->public_url ?: ($store->slug ? $this->buildStorePublicUrl($store->slug) : null);
        $creditLimit = (float) ($store->credit_limit ?? 200000);
        $ticketDebt = (float) ($store->ticket_debt ?? 0);
        $walletBalance = (float) ($store->wallet_balance ?? 0);
        $displayRemainingCapacity = round($creditLimit - $ticketDebt + $walletBalance, 2);

        if ((bool) ($store->is_debt_locked ?? false) && $ticketDebt <= 0) {
            try {
                if (Schema::hasColumn('stores', 'is_debt_locked')) {
                    Store::query()->whereKey((string) $store->id)->update([
                        'is_debt_locked' => false,
                        'debt_locked_at' => null,
                        'debt_lock_reason' => null,
                        'updated_at' => now(),
                    ]);
                    $store->is_debt_locked = false;
                    $store->debt_locked_at = null;
                    $store->debt_lock_reason = null;
                }
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $remainingCapacity = max(0.0, $displayRemainingCapacity);
        $accountBalance = round($walletBalance - $ticketDebt, 2);
        $debtBalance = round(-1 * $ticketDebt, 2);
        $debtLockedAt = $store->debt_locked_at ?? null;
        $debtLockedAtValue = $debtLockedAt instanceof \DateTimeInterface
            ? $debtLockedAt->format(DATE_ATOM)
            : ($debtLockedAt ? (string) $debtLockedAt : null);

        // Resolve location: use pre-fetched data if provided, otherwise query DB
        if ($locationData === null && Schema::hasColumn('stores', 'location')) {
            $locationData = $this->fetchStoreLocation((string) $store->id);
        }
        $locationOutput = ($locationData !== null && isset($locationData['lat'], $locationData['lng']))
            ? ['lat' => (float) $locationData['lat'], 'lng' => (float) $locationData['lng']]
            : null;

        return [
            'id' => $store->id,
            'store_code' => $store->store_code,
            'name' => $store->name,
            'description' => $store->description,
            'tagline' => $store->slogan ?? $store->description,
            'slogan' => $store->slogan,
            'slug' => $store->slug,
            'brand_color' => $store->brand_color,
            'logo_url' => $store->logo_url,
            'banner_url' => $store->banner_url,
            'banner_urls' => $this->normalizeStoreBannerUrlList($store->banner_urls ?? [], $store->banner_url),
            'cover_image' => $store->cover_image,
            'address' => $store->address,
            'phone' => $store->phone,
            'email' => $store->email,
            'business_type' => $store->business_type,
            'license_number' => $store->license_number,
            'representative' => $store->representative,
            'bank_name' => $store->bank_name,
            'bank_account' => $store->bank_account,
            'license_image' => $store->license_image,
            'status' => $store->status,
            'approval_status' => $store->status,
            'is_open' => (bool) $store->is_open,
            'open_time' => $store->open_time,
            'close_time' => $store->close_time,
            'min_order_tickets' => $store->min_order_tickets,
            'max_order_tickets' => $store->max_order_tickets,
            'location' => $locationOutput,
            'credit' => [
                'credit_limit' => $creditLimit,
                'ticket_debt' => $ticketDebt,
                'debt_balance' => $debtBalance,
                'wallet_balance' => $walletBalance,
                'account_balance' => $accountBalance,
                'remaining_capacity' => $remainingCapacity,
                'display_remaining_capacity' => $displayRemainingCapacity,
                'capacity_deficit' => max(0.0, abs(min(0.0, $displayRemainingCapacity))),
                'credit_level' => (int) ($store->credit_level ?? 1),
                'credit_score' => (int) ($store->credit_score ?? 100),
                'is_debt_locked' => (bool) ($store->is_debt_locked ?? false),
                'is_near_limit' => (bool) ($store->is_near_limit ?? false),
                'debt_locked_at' => $debtLockedAtValue,
                'debt_lock_reason' => $store->debt_lock_reason,
            ],
            'zalo_link' => $store->zalo_link,
            'facebook_link' => $store->facebook_link,
            'identities' => [
                'public_url' => $publicUrl,
                'qr_code_url' => $store->qr_code_custom,
                'short_link' => null,
            ],
            'sale_contact' => $salePerson ? [
                'full_name' => (string) ($salePerson->full_name ?? $salePerson->name ?? ''),
                'phone' => (string) ($salePerson->phone ?? ''),
                'zalo_id' => (string) ($salePerson->zalo_id ?? ''),
                'email' => (string) ($salePerson->email ?? ''),
                'support_notes' => (string) ($store->sale_support_notes ?? ''),
            ] : null,
        ];
    }

    /**
     * Fetch the geographic location of a store using ST_X / ST_Y.
     * Returns ['lat' => float, 'lng' => float] or null if no location is set.
     */
    private function fetchStoreLocation(string $storeId): ?array
    {
        try {
            $row = DB::table('stores')
                ->selectRaw('ST_X(location) as location_lng, ST_Y(location) as location_lat')
                ->where('id', $storeId)
                ->whereNotNull('location')
                ->first();

            if ($row && $row->location_lat !== null && $row->location_lng !== null) {
                return [
                    'lat' => (float) $row->location_lat,
                    'lng' => (float) $row->location_lng,
                ];
            }
        } catch (\Throwable $e) {
            // Spatial functions may not be available — return null gracefully
        }

        return null;
    }

    private function normalizeStoreBannerUrlList(mixed $value, mixed $fallback = null): array
    {
        $items = [];

        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $items = is_array($decoded) ? $decoded : [$value];
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            $items[] = $fallback;
        }

        $urls = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $url = trim($item);
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $urls[$url] = $url;
        }

        return array_slice(array_values($urls), 0, 10);
    }

    private function generateUniqueStoreSlug(string $value, ?string $storeId = null): string
    {
        $base = Str::slug($value);
        if ($base === '') {
            $base = 'store';
        }

        $slug = $base;
        $counter = 1;

        while ($this->slugExists($slug, $storeId)) {
            $counter++;
            $slug = $base . '-' . $counter;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?string $storeId = null): bool
    {
        $query = Store::query()->where('slug', $slug);
        if ($storeId !== null) {
            $query->where('id', '!=', $storeId);
        }

        return $query->exists();
    }

    private function buildStorePublicUrl(string $slug): string
    {
        $base = (string) (config('app.store_public_base_url') ?: config('app.url'));
        $base = rtrim($base, '/');

        return $base . '/s/' . $slug;
    }

    private function profileCompletion(Store $store): array
    {
        $checks = [
            'name' => (string) ($store->name ?? ''),
            'phone' => (string) ($store->phone ?? ''),
            'address' => (string) ($store->address ?? ''),
            'license_image' => (string) ($store->license_image ?? ''),
        ];

        $locationData = $this->fetchStoreLocation((string) $store->id);
        $hasLocation = $locationData !== null;

        $total = count($checks) + 1;
        $filled = 0;
        $missing = [];

        foreach ($checks as $field => $value) {
            if (trim($value) !== '') {
                $filled++;
            } else {
                $missing[] = $field;
            }
        }

        if ($hasLocation) {
            $filled++;
        } else {
            $missing[] = 'location';
        }

        return [
            'percent' => (int) floor(($filled * 100) / $total),
            'missing_fields' => $missing,
        ];
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

    private function extractStoragePath(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $path = parse_url($value, PHP_URL_PATH) ?: '';
            if (str_starts_with($path, '/storage/')) {
                return ltrim(substr($path, strlen('/storage/')), '/');
            }

            return null;
        }

        if (str_starts_with($value, '/storage/')) {
            return ltrim(substr($value, strlen('/storage/')), '/');
        }

        return ltrim($value, '/');
    }

    private function buildOperatingSchedule(Store $store): array
    {
        $records = StoreHour::query()
            ->where('store_id', $store->id)
            ->get()
            ->keyBy('day_of_week');

        $defaultOpen = $store->open_time ?: '09:00:00';
        $defaultClose = $store->close_time ?: '21:00:00';

        $schedule = [];
        for ($day = 0; $day <= 6; $day++) {
            /** @var StoreHour|null $record */
            $record = $records->get($day);

            $schedule[] = [
                'day_of_week' => $day,
                'open_time' => $record?->open_time ?? $defaultOpen,
                'close_time' => $record?->close_time ?? $defaultClose,
                'is_closed' => (bool) ($record?->is_closed ?? false),
            ];
        }

        return $schedule;
    }

    private function buildApprovalHistory(Store $store): array
    {
        $history = [];

        $candidates = [
            'store_approval_histories',
            'store_approval_history',
            'store_status_histories',
            'store_status_history',
        ];

        foreach ($candidates as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            $statusColumn = Schema::hasColumn($table, 'status')
                ? 'status'
                : (Schema::hasColumn($table, 'new_status') ? 'new_status' : null);

            if ($statusColumn === null) {
                continue;
            }

            $rows = DB::table($table)
                ->where('store_id', $store->id)
                ->orderByDesc('created_at')
                ->get();

            foreach ($rows as $index => $row) {
                $status = (string) ($row->{$statusColumn} ?? 'pending');
                $previousStatus = property_exists($row, 'previous_status')
                    ? $row->previous_status
                    : (property_exists($row, 'old_status') ? $row->old_status : null);
                $changedAt = $this->serializeDate(
                    property_exists($row, 'changed_at')
                        ? $row->changed_at
                        : (property_exists($row, 'reviewed_at')
                            ? $row->reviewed_at
                            : ($row->created_at ?? $row->updated_at ?? null))
                );

                $reviewedBy = null;
                if (property_exists($row, 'reviewed_by') && $row->reviewed_by !== null && $row->reviewed_by !== '') {
                    $reviewedBy = (string) $row->reviewed_by;
                } elseif (property_exists($row, 'processed_by') && $row->processed_by !== null && $row->processed_by !== '') {
                    $reviewedBy = (string) $row->processed_by;
                }

                $reason = property_exists($row, 'reason') ? ($row->reason ?: null) : null;
                if ($reason === null && property_exists($row, 'rejection_reason')) {
                    $reason = $row->rejection_reason ?: null;
                }

                $note = property_exists($row, 'note') ? ($row->note ?: null) : null;
                if ($note === null && property_exists($row, 'comment')) {
                    $note = $row->comment ?: null;
                }

                $id = property_exists($row, 'id') && $row->id !== null
                    ? 'ah_' . (string) $row->id
                    : 'ah_' . (string) ($index + 1);
                $attemptNumber = property_exists($row, 'attempt_number')
                    ? max(1, (int) $row->attempt_number)
                    : ($index + 1);

                $history[] = [
                    'id' => $id,
                    'attempt_number' => $attemptNumber,
                    'previous_status' => $previousStatus !== null ? (string) $previousStatus : null,
                    'status' => $status,
                    'changed_at' => $changedAt,
                    'reviewed_by' => $reviewedBy,
                    'reason' => $reason,
                    'note' => $note,
                ];
            }

            if (!empty($history)) {
                usort($history, function (array $a, array $b): int {
                    return strcmp((string) ($b['changed_at'] ?? ''), (string) ($a['changed_at'] ?? ''));
                });

                return $history;
            }
        }

        $fallback = [];

        if (!empty($store->created_at)) {
            $fallback[] = [
                'id' => 'ah_created_' . $store->id,
                'attempt_number' => 1,
                'previous_status' => null,
                'status' => 'pending',
                'changed_at' => $this->serializeDate($store->created_at),
                'reviewed_by' => 'system',
                'reason' => null,
                'note' => 'Store submitted for review',
            ];
        }

        $currentStatus = (string) ($store->status ?? 'pending');
        $processedAt = $store->approved_at ?? $store->updated_at;
        $currentChangedAt = $this->serializeDate($processedAt);

        $previousStatus = null;
        if (!empty($fallback)) {
            $previousStatus = (string) ($fallback[0]['status'] ?? 'pending');
        }

        $hasCurrent = false;
        foreach ($fallback as $item) {
            if (($item['status'] ?? null) === $currentStatus && ($item['changed_at'] ?? null) === $currentChangedAt) {
                $hasCurrent = true;
                break;
            }
        }

        if (!$hasCurrent) {
            $fallback[] = [
                'id' => 'ah_current_' . $store->id,
                'attempt_number' => count($fallback) + 1,
                'previous_status' => $previousStatus,
                'status' => $currentStatus,
                'changed_at' => $currentChangedAt,
                'reviewed_by' => $this->resolveApproverName($store) ?? 'system',
                'reason' => $currentStatus === 'rejected' ? ($store->rejection_reason ?: null) : null,
                'note' => null,
            ];
        }

        usort($fallback, function (array $a, array $b): int {
            return strcmp((string) ($b['changed_at'] ?? ''), (string) ($a['changed_at'] ?? ''));
        });

        return $fallback;
    }

    private function resolveApproverName(Store $store): ?string
    {
        if (empty($store->approved_by)) {
            return null;
        }

        if (!Schema::hasTable('users')) {
            return null;
        }

        $fullNameColumn = Schema::hasColumn('users', 'full_name');
        $nameColumn = Schema::hasColumn('users', 'name');

        if (!$fullNameColumn && !$nameColumn) {
            return null;
        }

        $approver = DB::table('users')->where('id', $store->approved_by)->first();
        if (!$approver) {
            return null;
        }

        if ($fullNameColumn && !empty($approver->full_name)) {
            return (string) $approver->full_name;
        }

        if ($nameColumn && !empty($approver->name)) {
            return (string) $approver->name;
        }

        return null;
    }

    private function serializeDate(mixed $value): ?string
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

    private function resolveContext(Request $request): array
    {
        $user = $request->user();
        $storeContextId = $request->attributes->get('store_context_id');
        $requestedStoreId = $request->query('store_id') ?: $request->input('store_id') ?: $storeContextId;

        if (!$user) {
            return [
                'user' => null,
                'store' => null,
                'error' => response()->json([
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
                ], 401),
            ];
        }

        $accessContext = $this->resolveStoreAdminAccessContext($request);
        if (!$accessContext['error']) {
            return $accessContext;
        }

        $isStoreAdminRole = in_array((string) $user->role, [
            'store_admin',
            'admin_store',
            'admin_store_pending',
            'store_owner',
            'shop_admin',
            'admin',
        ], true);

        if (!$isStoreAdminRole) {
            return [
                'user' => $user,
                'store' => null,
                'error' => response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [
                        [
                            'code' => 'FORBIDDEN_ROLE',
                            'message' => 'Tài khoản hiện tại không có quyền admin cửa hàng.',
                        ],
                    ],
                ], 403),
            ];
        }

        $storeQuery = Store::query()->where('owner_id', $user->id);
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        if (!empty($requestedStoreId)) {
            $storeQuery->where('id', (string) $requestedStoreId);
        } else {
            $activeStoreQuery = (clone $storeQuery)->whereIn('status', ['active', 'approved']);
            if ($activeStoreQuery->exists()) {
                $storeQuery = $activeStoreQuery->orderByDesc('created_at');
            } else {
                $storeQuery->orderByDesc('created_at');
            }
        }

        return [
            'user' => $user,
            'store' => $storeQuery->first(),
            'error' => null,
        ];
    }

    private function resolvePermissionScopedContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if (!$context['error'] && $context['store']) {
            $user = $context['user'];
            $store = $context['store'];
            $role = (string) ($user->role ?? '');
            $isOwner = (string) $store->owner_id === (string) $user->id;
            $isPlatformAdmin = in_array($role, ['admin', 'super_admin'], true);
            $storeStatus = strtolower((string) ($store->status ?? ''));

            return array_merge($context, [
                'account_role' => $role,
                'can_access_admin_store' => true,
                'can_manage_admin_store' => $isPlatformAdmin || ($isOwner && in_array($storeStatus, ['approved', 'active'], true)),
            ]);
        }

        $user = $request->user();
        if (!$user) {
            return $context;
        }

        $requestedStoreId = $this->resolveRequestedStoreId($request);
        if ($requestedStoreId === null) {
            $inputStoreId = trim((string) $request->input('store_id', ''));
            $requestedStoreId = $inputStoreId !== '' ? $inputStoreId : null;
        }

        $binding = null;
        $store = null;

        if ($requestedStoreId !== null) {
            $storeQuery = Store::query()->where('id', $requestedStoreId);
            if (Schema::hasColumn('stores', 'deleted_at')) {
                $storeQuery->whereNull('deleted_at');
            }

            $store = $storeQuery->first();
            $binding = $store
                ? $this->resolveStoreRoleBinding((int) $user->id, (string) $store->id)
                : null;
        } else {
            $binding = $this->resolveStoreRoleBinding((int) $user->id);
            if ($binding && !empty($binding->entity_id)) {
                $storeQuery = Store::query()->where('id', (string) $binding->entity_id);
                if (Schema::hasColumn('stores', 'deleted_at')) {
                    $storeQuery->whereNull('deleted_at');
                }
                $store = $storeQuery->first();
            }
        }

        if (!$store) {
            return array_merge($context, [
                'user' => $user,
                'store' => null,
                'error' => $this->errorResponse(
                    'STORE_NOT_FOUND',
                    'Khong tim thay cua hang thuoc pham vi truy cap cua tai khoan nay.',
                    404,
                    false,
                    true
                ),
            ]);
        }

        $role = (string) ($user->role ?? '');
        $isOwner = (string) $store->owner_id === (string) $user->id;
        $isPlatformAdmin = in_array($role, ['admin', 'super_admin'], true);

        if (!$isOwner && !$isPlatformAdmin && !$binding) {
            return array_merge($context, [
                'user' => $user,
                'store' => null,
                'error' => response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [[
                        'code' => 'FORBIDDEN_ROLE',
                        'message' => 'Tai khoan hien tai chua duoc gan quyen truy cap cua hang.',
                    ]],
                ], 403),
            ]);
        }

        $accountRole = $binding ? (string) $binding->role_name : $role;

        return [
            'user' => $user,
            'store' => $store,
            'error' => null,
            'account_role' => $accountRole,
            'can_access_admin_store' => true,
            'can_manage_admin_store' => $isPlatformAdmin || $isOwner || $accountRole === 'store_manager',
        ];
    }

    private function resolveStoreRoleBinding(int $userId, ?string $storeId = null)
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return null;
        }

        $query = UserRoleBinding::query()
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.user_id', $userId)
            ->where('user_role_bindings.entity_type', 'store')
            ->whereIn('roles.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']));

        if ($storeId !== null) {
            $query->where('user_role_bindings.entity_id', $storeId);
        }

        if (Schema::hasColumn('user_role_bindings', 'status')) {
            $query->where('user_role_bindings.status', 'active');
        }

        return $query
            ->orderByDesc('user_role_bindings.id')
            ->select([
                'user_role_bindings.entity_id',
                'roles.name as role_name',
            ])
            ->first();
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolvePermissionScopedContext($request);
        if ($context['error']) {
            return [null, null, $context['error'], $context];
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
                $context,
            ];
        }

        return [$context['store'], $context['user'], null, $context];
    }

    private function validateRequest(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return [
                null,
                $this->errorResponse(
                    'VALIDATION_ERROR',
                    'Dữ liệu đầu vào không hợp lệ.',
                    422,
                    true,
                    true,
                    $validator->errors()->toArray()
                ),
            ];
        }

        return [$validator->validated(), null];
    }

    private function successResponse(array $data, bool $hasStore = true, bool $authenticated = true): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        ], $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
