<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\StoreAdmin\StorePermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CheckStorePermission
{
    public function __construct(private readonly StorePermissionCatalog $catalog)
    {
    }

    public function handle(Request $request, Closure $next, string $permissionCode, ?string $storeRouteParam = null): Response
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
                    'message' => 'Ban chua dang nhap hoac phien dang nhap da het han.',
                ]],
            ], 401);
        }

        $resolvedStoreId = $this->resolveStoreId($request, (string) $user->id, $storeRouteParam);
        if ($resolvedStoreId === null) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'STORE_CONTEXT_NOT_FOUND',
                    'message' => 'Khong tim thay pham vi cua hang de kiem tra quyen.',
                ]],
            ], 403);
        }

        // Store owners and platform admins keep full access within their own store scope.
        if ($this->isStoreOwnerOrPlatformAdmin((string) ($user->role ?? ''), (string) $user->id, $resolvedStoreId, $permissionCode)) {
            $request->attributes->set('store_context_id', $resolvedStoreId);
            return $next($request);
        }

        if (! $this->storeAllowsPermission($resolvedStoreId, $permissionCode)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'STORE_NOT_ACTIVE',
                    'message' => 'Cua hang chua duoc kich hoat de thuc hien thao tac nay.',
                    'details' => [
                        'permission_code' => strtoupper(trim($permissionCode)),
                        'store_id' => $resolvedStoreId,
                    ],
                ]],
            ], 403);
        }

        $permissionGroups = $this->catalog->requiredMiddlewareCodeGroups($permissionCode);
        $hasPermission = $this->hasPermissionGroups((int) $user->id, $resolvedStoreId, $permissionGroups);

        if (!$hasPermission) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'FORBIDDEN_PERMISSION',
                    'message' => 'Ban khong co quyen thao tac nay trong cua hang hien tai.',
                    'details' => [
                        'permission_code' => strtoupper(trim($permissionCode)),
                        'required_permission_groups' => $permissionGroups,
                        'store_id' => $resolvedStoreId,
                    ],
                ]],
            ], 403);
        }

        $request->attributes->set('store_context_id', $resolvedStoreId);

        return $next($request);
    }

    private function resolveStoreId(Request $request, string $userId, ?string $storeRouteParam): ?string
    {
        if ($storeRouteParam !== null && $storeRouteParam !== '') {
            $fromRoute = $request->route($storeRouteParam);
            if (!empty($fromRoute)) {
                return (string) $fromRoute;
            }
        }

        $requestedStoreId = $request->query('store_id') ?: $request->input('store_id');
        if (!empty($requestedStoreId)) {
            return (string) $requestedStoreId;
        }

        $ownerStoreQuery = Store::query()->where('owner_id', $userId);
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $ownerStoreQuery->whereNull('deleted_at');
        }

        $activeStoreQuery = (clone $ownerStoreQuery)->whereIn('status', ['active', 'approved']);
        $ownerStore = $activeStoreQuery->orderByDesc('created_at')->first();
        if (!$ownerStore) {
            $ownerStore = $ownerStoreQuery->orderByDesc('created_at')->first();
        }

        if ($ownerStore) {
            return (string) $ownerStore->id;
        }

        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return null;
        }

        $binding = DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->whereIn('r.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']))
            ->when(Schema::hasColumn('user_role_bindings', 'status'), fn ($query) => $query->where('urb.status', 'active'))
            ->orderByDesc('urb.id')
            ->select('urb.entity_id')
            ->first();

        return $binding && !empty($binding->entity_id) ? (string) $binding->entity_id : null;
    }

    private function isStoreOwnerOrPlatformAdmin(string $role, string $userId, string $storeId, string $permissionCode): bool
    {
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return true;
        }

        $storeQuery = Store::query()->where('id', $storeId)->where('owner_id', $userId);
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        $store = $storeQuery->first();
        if (! $store) {
            return false;
        }

        return $this->storeAllowsPermission((string) $store->id, $permissionCode);
    }

    private function storeAllowsPermission(string $storeId, string $permissionCode): bool
    {
        $normalizedPermission = strtoupper(trim($permissionCode));
        if (in_array($normalizedPermission, ['VIEW_STORE'], true)) {
            return true;
        }

        if (! Schema::hasColumn('stores', 'status')) {
            return true;
        }

        $status = Store::query()
            ->where('id', $storeId)
            ->value('status');

        return in_array(strtolower(trim((string) $status)), Store::PUBLIC_STATUSES, true);
    }

    private function isManagerWithinStore(int $userId, string $storeId): bool
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return false;
        }

        $binding = DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->where('urb.entity_id', $storeId)
            ->where('r.name', 'store_manager');

        if (Schema::hasColumn('user_role_bindings', 'status')) {
            $binding->where('urb.status', 'active');
        }

        return $binding->exists();
    }

    private function hasPermissionGroups(int $userId, string $storeId, array $permissionGroups): bool
    {
        if (!Schema::hasTable('store_staff_permissions')) {
            return false;
        }

        if (!$this->hasActiveStoreBinding($userId, $storeId)) {
            return false;
        }

        foreach ($permissionGroups as $permissionCodes) {
            $query = DB::table('store_staff_permissions as ssp')
                ->where('ssp.user_id', $userId)
                ->where('ssp.store_id', $storeId);

            if (Schema::hasColumn('store_staff_permissions', 'permission_id') && Schema::hasTable('permissions')) {
                $hasPermission = $query
                    ->leftJoin('permissions as p', 'p.id', '=', 'ssp.permission_id')
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

    private function hasActiveStoreBinding(int $userId, string $storeId): bool
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return false;
        }

        return DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->where('urb.entity_id', $storeId)
            ->whereIn('r.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']))
            ->when(Schema::hasColumn('user_role_bindings', 'status'), fn ($query) => $query->where('urb.status', 'active'))
            ->exists();
    }
}
