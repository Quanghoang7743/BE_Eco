<?php

use App\Models\Order;
use App\Models\PreOrder;
use App\Models\Store;
use App\Services\StoreAdmin\StorePermissionCatalog;
use App\Support\PlatformAdminAccess;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    if (! $user) {
        return false;
    }

    // Support both UUID and integer IDs.
    return (string) $user->id === (string) $id;
});

Broadcast::channel('affiliate.members.{userId}', function ($user, $userId) {
    if (! $user) {
        return false;
    }

    return (string) $user->id === (string) $userId;
});

Broadcast::channel('affiliate.wallet.{userId}', function ($user, $userId) {
    if (! $user) {
        return false;
    }

    return (string) $user->id === (string) $userId;
});

Broadcast::channel('order-returns', function ($user) {
    return PlatformAdminAccess::allows($user);
});

Broadcast::channel('order-returns.store.{storeId}', function ($user, $storeId) {
    if (! $user) {
        return false;
    }

    if (PlatformAdminAccess::allows($user)) {
        return true;
    }

    $userId = (string) $user->id;
    $storeId = (string) $storeId;
    $ownerStoreQuery = Store::query()
        ->where('id', $storeId)
        ->where('owner_id', $userId);

    if (Schema::hasColumn('stores', 'deleted_at')) {
        $ownerStoreQuery->whereNull('deleted_at');
    }

    if ($ownerStoreQuery->exists()) {
        return true;
    }

    if (! Schema::hasTable('user_role_bindings') || ! Schema::hasTable('roles') || ! Schema::hasTable('store_staff_permissions')) {
        return false;
    }

    $hasActiveStoreBinding = DB::table('user_role_bindings as urb')
        ->join('roles as r', 'r.id', '=', 'urb.role_id')
        ->where('urb.user_id', $userId)
        ->where('urb.entity_type', 'store')
        ->where('urb.entity_id', $storeId)
        ->whereIn('r.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']))
        ->when(Schema::hasColumn('user_role_bindings', 'status'), fn ($query) => $query->where('urb.status', 'active'))
        ->exists();

    if (! $hasActiveStoreBinding) {
        return false;
    }

    foreach (app(StorePermissionCatalog::class)->requiredMiddlewareCodeGroups('VIEW_RETURN') as $permissionCodes) {
        $query = DB::table('store_staff_permissions as ssp')
            ->where('ssp.user_id', $userId)
            ->where('ssp.store_id', $storeId);

        $hasPermission = Schema::hasColumn('store_staff_permissions', 'permission_id') && Schema::hasTable('permissions')
            ? $query->leftJoin('permissions as p', 'p.id', '=', 'ssp.permission_id')
                ->where(function ($builder) use ($permissionCodes): void {
                    $builder->whereIn('p.code', $permissionCodes)
                        ->orWhereIn('ssp.permission_code', $permissionCodes);
                })
                ->exists()
            : $query->whereIn('ssp.permission_code', $permissionCodes)->exists();

        if (! $hasPermission) {
            return false;
        }
    }

    return true;
});

Broadcast::channel('order.{paymentRequestId}', function ($user, $paymentRequestId) {
    if (! $user) {
        return false;
    }

    $userId = (string) $user->id;

    return Order::query()
        ->where('id', (string) $paymentRequestId)
        ->where('user_id', $userId)
        ->exists()
        || PreOrder::query()
            ->where('id', (string) $paymentRequestId)
            ->where('user_id', $userId)
            ->exists();
});

Broadcast::channel('orders.store.{storeId}', function ($user, $storeId) {
    // Allow store admins and employees to listen to their store's orders
    if (! $user || ! property_exists($user, 'store_id')) {
        return false;
    }

    return (string) $user->store_id === (string) $storeId;
});

Broadcast::channel('private-store.{storeId}', function ($user, $storeId) {
    if (! $user || ! property_exists($user, 'store_id')) {
        return false;
    }

    return (string) $user->store_id === (string) $storeId;
});

Broadcast::channel('finance.store.{storeId}', function ($user, $storeId) {
    // Allow store admins and employees to listen to their store's finance updates
    if (! $user || ! property_exists($user, 'store_id')) {
        return false;
    }

    return (string) $user->store_id === (string) $storeId;
});
