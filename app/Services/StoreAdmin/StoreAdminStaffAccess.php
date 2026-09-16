<?php

namespace App\Services\StoreAdmin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreAdminStaffAccess
{
    public function __construct(private readonly StorePermissionCatalog $catalog)
    {
    }

    public function resolveStoreContext(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            return [
                null,
                null,
                $this->errorResponse('UNAUTHENTICATED', 'Ban chua dang nhap hoac phien dang nhap da het han.', 401, false, false),
            ];
        }

        $storeId = $this->resolveRequestedStoreId($request);
        if ($storeId !== null) {
            return $this->resolveExplicitStore($user, $storeId);
        }

        $store = $this->resolveDefaultStore($user);
        if (!$store) {
            return [
                $user,
                null,
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc pham vi truy cap cua tai khoan nay.', 404, true, false),
            ];
        }

        return [$user, $store, null];
    }

    public function resolveRequestedStoreId(Request $request): ?string
    {
        foreach ([
            $request->query('store_id'),
            $request->input('store_id'),
            $request->attributes->get('store_context_id'),
            $request->header('X-Store-Id'),
        ] as $value) {
            $storeId = trim((string) $value);
            if ($storeId !== '') {
                return $storeId;
            }
        }

        return null;
    }

    public function resolveTargetStoreIds(Request $request, Store $fallbackStore): array
    {
        $raw = $request->input('store_ids', $request->input('storeIds', null));
        $values = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
        $ids = collect($values)
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return [(string) $fallbackStore->id];
        }

        $existing = Store::query()
            ->whereIn('id', $ids)
            ->when(Schema::hasColumn('stores', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        return $existing;
    }

    public function canManageStaff(int $userId, string $storeId): bool
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return false;
        }

        if (in_array((string) $user->role, ['admin', 'super_admin'], true)) {
            return true;
        }

        $store = Store::query()->where('id', $storeId)->first();
        if ($store && (string) $store->owner_id === (string) $userId) {
            return true;
        }

        if ($this->hasStoreRole($userId, $storeId, ['admin_store'])) {
            return true;
        }

        return $this->hasAnyPermission($userId, $storeId, [
            'CREATE_EMPLOYEE',
            'UPDATE_EMPLOYEE',
            'ASSIGN_EMPLOYEE_PERMISSION',
            'MANAGE_STAFF',
        ]);
    }

    public function hasAnyPermission(int $userId, string $storeId, array $codes): bool
    {
        if (!Schema::hasTable('store_staff_permissions')) {
            return false;
        }

        foreach ($codes as $code) {
            if ($this->hasPermissionGroups($userId, $storeId, $this->catalog->requiredMiddlewareCodeGroups((string) $code))) {
                return true;
            }
        }

        return false;
    }

    private function hasPermissionGroups(int $userId, string $storeId, array $permissionGroups): bool
    {
        foreach ($permissionGroups as $permissionCodes) {
            if (!$this->hasStoreRole($userId, $storeId, array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']))) {
                return false;
            }

            $query = DB::table('store_staff_permissions as ssp')
                ->where('ssp.user_id', $userId)
                ->where('ssp.store_id', $storeId);

            if (Schema::hasColumn('store_staff_permissions', 'permission_id') && Schema::hasTable('permissions')) {
                $hasPermission = $query->leftJoin('permissions as p', 'p.id', '=', 'ssp.permission_id')
                    ->where(function ($builder) use ($permissionCodes) {
                        $builder->whereIn('p.code', $permissionCodes)
                            ->orWhereIn('ssp.permission_code', $permissionCodes);
                    })
                    ->exists();
            } else {
                $hasPermission = $query->whereIn('ssp.permission_code', $permissionCodes)->exists();
            }

            if (!$hasPermission) {
                return false;
            }
        }

        return true;
    }

    public function errorResponse(
        string $code,
        string $message,
        int $status,
        bool $authenticated,
        bool $hasStore,
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

    private function resolveExplicitStore(User $user, string $storeId): array
    {
        $store = Store::query()
            ->where('id', $storeId)
            ->when(Schema::hasColumn('stores', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->first();

        if (!$store) {
            return [
                $user,
                null,
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang.', 404, true, false),
            ];
        }

        if ($this->canAccessStore($user, $store)) {
            return [$user, $store, null];
        }

        return [
            $user,
            null,
            $this->errorResponse('FORBIDDEN_ROLE', 'Tai khoan hien tai khong co quyen admin cua hang.', 403, true, false),
        ];
    }

    private function resolveDefaultStore(User $user): ?Store
    {
        $ownerStoreQuery = Store::query()
            ->where('owner_id', $user->id)
            ->when(Schema::hasColumn('stores', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'));

        $activeStoreQuery = (clone $ownerStoreQuery)->whereIn('status', ['active', 'approved']);
        $ownerStore = $activeStoreQuery->orderByDesc('created_at')->first();
        if (!$ownerStore) {
            $ownerStore = $ownerStoreQuery->orderByDesc('created_at')->first();
        }

        if ($ownerStore) {
            return $ownerStore;
        }

        if (in_array((string) $user->role, ['admin', 'super_admin'], true)) {
            return Store::query()->orderByDesc('created_at')->first();
        }

        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return null;
        }

        $binding = DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $user->id)
            ->where('urb.entity_type', 'store')
            ->whereIn('r.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']))
            ->when(Schema::hasColumn('user_role_bindings', 'status'), fn ($query) => $query->where('urb.status', 'active'))
            ->orderByDesc('urb.id')
            ->select('urb.entity_id')
            ->first();

        return $binding && $binding->entity_id
            ? Store::query()->where('id', (string) $binding->entity_id)->first()
            : null;
    }

    private function canAccessStore(User $user, Store $store): bool
    {
        if ((string) $store->owner_id === (string) $user->id) {
            return true;
        }

        if (in_array((string) $user->role, ['admin', 'super_admin'], true)) {
            return true;
        }

        return $this->hasStoreRole((int) $user->id, (string) $store->id, array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']));
    }

    private function hasStoreRole(int $userId, string $storeId, array $roles): bool
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return false;
        }

        return DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->where('urb.entity_id', $storeId)
            ->whereIn('r.name', $roles)
            ->when(Schema::hasColumn('user_role_bindings', 'status'), fn ($query) => $query->where('urb.status', 'active'))
            ->exists();
    }
}
