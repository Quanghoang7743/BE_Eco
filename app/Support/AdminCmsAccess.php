<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminCmsAccess
{
    public const CMS_ROLES = ['admin', 'super_admin', 'sale', 'operator', 'operation_admin'];

    public const LOCKED_STATUSES = ['locked', 'inactive', 'blocked', 'suspended', 'disabled'];

    public static function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public static function isSale(?User $user = null): bool
    {
        $user ??= self::currentUser();

        return $user && (string) $user->role === 'sale';
    }

    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= self::currentUser();

        return $user && (string) $user->role === 'super_admin';
    }

    public static function isCmsUser(?User $user = null): bool
    {
        $user ??= self::currentUser();

        return $user && in_array((string) $user->role, self::CMS_ROLES, true);
    }

    public static function isLocked(?User $user = null): bool
    {
        $user ??= self::currentUser();

        if (! $user || ! Schema::hasColumn('users', 'status')) {
            return false;
        }

        $status = strtolower(trim((string) ($user->status ?? 'active')));

        return in_array($status, self::LOCKED_STATUSES, true);
    }

    public static function applyStoreScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= self::currentUser();

        if (self::isSale($user)) {
            $storeIds = self::assignedStoreIds($user);
            if (! empty($storeIds)) {
                $query->whereIn('id', $storeIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    public static function applyOrderScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= self::currentUser();

        if (self::isSale($user)) {
            if (self::canUseAssignmentWindowForOrders()) {
                $dateExpression = Schema::hasColumn('orders', 'delivered_at')
                    ? 'COALESCE(orders.delivered_at, orders.created_at)'
                    : 'orders.created_at';

                $query->whereExists(function ($assignment) use ($user, $dateExpression): void {
                    $assignment->selectRaw('1')
                        ->from('specialist_store_assignments')
                        ->where('specialist_store_assignments.specialist_id', $user->id)
                        ->whereColumn('specialist_store_assignments.store_id', 'orders.store_id')
                        ->whereRaw('specialist_store_assignments.assigned_from <= ' . $dateExpression)
                        ->where(function ($window) use ($dateExpression): void {
                            $window->whereNull('specialist_store_assignments.assigned_to')
                                ->orWhereRaw('specialist_store_assignments.assigned_to >= ' . $dateExpression);
                        });
                });
            } else {
                $storeIds = self::assignedStoreIds($user);
                if (! empty($storeIds) && Schema::hasColumn('orders', 'store_id')) {
                    $query->whereIn('orders.store_id', $storeIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        return $query;
    }

    public static function applyComplaintScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= self::currentUser();

        if (self::isSale($user)) {
            $storeIds = self::assignedStoreIds($user);
            $query->whereHas('store', function (Builder $storeQuery) use ($storeIds) {
                if (! empty($storeIds)) {
                    $storeQuery->whereIn('id', $storeIds);
                } else {
                    $storeQuery->whereRaw('1 = 0');
                }
            });
        }

        return $query;
    }

    public static function canViewStore(Store $store, ?User $user = null): bool
    {
        $user ??= self::currentUser();

        if (! self::isSale($user)) {
            return true;
        }

        if ((int) ($store->sale_id ?? 0) === (int) $user->id) {
            return true;
        }

        return in_array((string) $store->id, self::assignedStoreIds($user), true);
    }

    public static function abortUnlessCanViewStore(Store $store, ?User $user = null): void
    {
        abort_unless(self::canViewStore($store, $user), 403, 'Ban khong co quyen xem cua hang nay.');
    }

    public static function assignedStoreIds(?User $user = null): array
    {
        $user ??= self::currentUser();

        if (! $user || ! Schema::hasTable('stores')) {
            return [];
        }

        $assignmentStoreIds = self::activeAssignmentStoreIds($user);
        if (! empty($assignmentStoreIds)) {
            return $assignmentStoreIds;
        }

        if (! Schema::hasColumn('stores', 'sale_id')) {
            return [];
        }

        return Store::query()
            ->where('sale_id', $user->id)
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->all();
    }

    private static function activeAssignmentStoreIds(User $user): array
    {
        if (
            ! Schema::hasTable('specialist_store_assignments')
            || ! Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            || ! Schema::hasColumn('specialist_store_assignments', 'store_id')
            || ! Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            return [];
        }

        $now = now('Asia/Ho_Chi_Minh')->toDateTimeString();

        return DB::table('specialist_store_assignments')
            ->where('specialist_id', $user->id)
            ->where(function ($query) use ($now) {
                $query->whereNull('assigned_to')
                    ->orWhere('assigned_to', '>=', $now);
            })
            ->pluck('store_id')
            ->map(static fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private static function canUseAssignmentWindowForOrders(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'store_id')
            && Schema::hasColumn('orders', 'created_at')
            && Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_from')
            && Schema::hasColumn('specialist_store_assignments', 'assigned_to');
    }
}
