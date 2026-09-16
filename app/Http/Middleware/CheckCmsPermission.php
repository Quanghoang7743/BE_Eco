<?php

namespace App\Http\Middleware;

use App\Support\AdminCmsAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCmsPermission
{
    private const ROUTE_PERMISSIONS = [
        'admin' => 'DASHBOARD_VIEW',
        'admin.users.sales' => ['SALE_MANAGE', 'USER_MANAGE'],
        'admin.users.sales.datatable' => ['SALE_MANAGE', 'USER_MANAGE'],
        'admin.users.sales.show' => ['SALE_MANAGE', 'USER_MANAGE'],
        'admin.users.documents.download' => ['DASHBOARD_VIEW', 'SALE_MANAGE', 'USER_MANAGE'],
        'admin.users.lock' => ['SALE_MANAGE', 'USER_MANAGE'],
        'admin.users.unlock' => ['SALE_MANAGE', 'USER_MANAGE'],
        'admin.users.*' => 'USER_MANAGE',
        'admin.admin.users.*' => 'USER_MANAGE',
        'admin.permissions.*' => 'ROLE_PERMISSION_MANAGE',
        'admin.roles.*' => 'ROLE_PERMISSION_MANAGE',
        'admin.orders.updateStatus' => 'ORDER_UPDATE',
        'admin.orders.updateStatusPayment' => 'ORDER_UPDATE',
        'admin.orders.destroy' => 'ORDER_CANCEL',
        'admin.orders.destroyMultiple' => 'ORDER_CANCEL',
        'admin.orders.store' => 'ORDER_UPDATE',
        'admin.orders.update' => 'ORDER_UPDATE',
        'admin.orders.*' => 'ORDER_VIEW',
        'admin.system-orders.*' => 'ORDER_VIEW',
        'admin.reports.consolidated-revenue*' => 'RECONCILIATION_MANAGE',
        'admin.reports.specialists' => ['DASHBOARD_VIEW', 'LUCKY_TICKET_VIEW', 'REPORT_VIEW', 'RECONCILIATION_MANAGE'],
        'admin.reports.*' => 'REPORT_VIEW',
        'admin.products.store' => 'PRODUCT_MANAGE',
        'admin.products.update' => 'PRODUCT_MANAGE',
        'admin.products.destroy' => 'PRODUCT_MANAGE',
        'admin.products.*' => 'PRODUCT_MANAGE',
        'admin.product-categories.*' => 'CATEGORY_MANAGE',
        'admin.categories.*' => 'CATEGORY_MANAGE',
        'admin.attributes.*' => 'PRODUCT_MANAGE',
        'admin.inventory.*' => 'PRODUCT_MANAGE',
        'admin.product-combos.*' => 'PRODUCT_MANAGE',
        'admin.reviews.*' => 'PRODUCT_VIEW',
        'admin.stores.assign-sale' => 'STORE_ASSIGN_SALE',
        'admin.stores.create' => 'STORE_CREATE',
        'admin.stores.store' => 'STORE_CREATE',
        'admin.stores.show' => 'STORE_VIEW',
        'admin.stores.care-logs.store' => 'STORE_VIEW',
        'admin.stores.edit' => 'STORE_VIEW',
        'admin.stores.update' => 'STORE_PROFILE_EDIT',
        'admin.stores.destroy' => 'STORE_LOCK',
        'admin.stores.*' => ['STORE_VIEW', 'STORE_APPROVE'],
        'admin.store-approvals.*' => 'STORE_APPROVE',
        'admin.product-approvals.*' => 'PRODUCT_APPROVE',
        'admin.sales.performance.update-commission' => ['SALE_MANAGE', 'STORE_ASSIGN_SALE'],
        'admin.sales.performance.export' => ['SALE_MANAGE', 'STORE_ASSIGN_SALE'],
        'admin.sales.performance.show' => ['SALE_MANAGE', 'STORE_ASSIGN_SALE'],
        'admin.sales.performance.*' => ['SALE_MANAGE', 'STORE_ASSIGN_SALE'],
        'admin.banners.*' => 'BANNER_MANAGE',
        'admin.articles.*' => 'ARTICLE_MANAGE',
        'admin.policies.*' => 'ARTICLE_MANAGE',
        'admin.notifications.*' => 'NOTIFICATION_MANAGE',
        'admin.specialist-notifications.*' => ['DASHBOARD_VIEW', 'LUCKY_TICKET_VIEW', 'NOTIFICATION_MANAGE'],
        'admin.specialist-care.*' => ['DASHBOARD_VIEW', 'STORE_VIEW', 'STORE_ASSIGN_SALE'],
        'admin.specialist-credit.*' => ['DASHBOARD_VIEW', 'SALE_MANAGE', 'STORE_ASSIGN_SALE'],
        'admin.specialist-fee-configs.*' => ['RECONCILIATION_MANAGE', 'SALE_MANAGE'],
        'admin.specialist-ratings.*' => ['DASHBOARD_VIEW', 'SALE_MANAGE', 'STORE_ASSIGN_SALE'],
        'admin.app-setting.*' => 'SETTING_MANAGE',
        'admin.payment-settings.*' => 'PAYMENT_SETTING_MANAGE',
        'admin.banks.*' => 'BANK_ACCOUNT_MANAGE',
        'admin.bank-accounts.*' => 'BANK_ACCOUNT_MANAGE',
        'admin.rooms.*' => 'REWARD_ROOM_CREATE',
        'admin.room-schedules.*' => 'REWARD_ROOM_CREATE',
        'admin.rewards.*' => 'REWARD_ROOM_CREATE',
        'admin.vouchers.*' => 'REWARD_ROOM_CREATE',
        'admin.winners.*' => 'REWARD_RESULT_PUBLISH',
        'admin.prize-settings.*' => 'REWARD_ROOM_ACTIVATE',
        'admin.ticket-stats.*' => 'LUCKY_TICKET_VIEW',
        'admin.tickets.*' => 'LUCKY_TICKET_VIEW',
        'admin.specialist-income.*' => ['LUCKY_TICKET_VIEW', 'RECONCILIATION_MANAGE'],
        'admin.specialist-settlements.*' => ['LUCKY_TICKET_VIEW', 'RECONCILIATION_MANAGE'],
        'admin.ticket-settings.*' => 'SETTING_MANAGE',
        'admin.complaints.assign' => 'USER_MANAGE',
        'admin.complaints.*' => ['USER_VIEW', 'STORE_VIEW'],
        'admin.affiliate.*' => 'AFFILIATE_VIEW',
        'admin.reconciliation.*' => 'RECONCILIATION_MANAGE',
        'admin.menus.*' => ['MENU_VIEW', 'MENU_EDIT'],
        'upload' => 'PRODUCT_MANAGE',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = (string) $request->route()?->getName();

        if (! $user) {
            abort(403);
        }

        if (AdminCmsAccess::isLocked($user)) {
            abort(403, 'Tai khoan cua ban dang bi khoa.');
        }

        if ($routeName === 'logout') {
            return $next($request);
        }

        if (AdminCmsAccess::isSuperAdmin($user) || $user->hasRole('super_admin')) {
            return $next($request);
        }

        $permissions = $this->permissionsForRoute($routeName);

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        if ($permissions === [] && $user->hasRole('super_admin')) {
            return $next($request);
        }

        abort(403, 'Bạn không có quyền truy cập chức năng này.');
    }

    private function permissionsForRoute(string $routeName): array
    {
        foreach (self::ROUTE_PERMISSIONS as $pattern => $permissions) {
            if ($routeName === $pattern || str($routeName)->is($pattern)) {
                return (array) $permissions;
            }
        }

        return [];
    }
}
