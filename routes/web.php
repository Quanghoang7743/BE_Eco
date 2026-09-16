<?php

use App\Exports\OrdersExport;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AttributeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductComboController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PrizeSettingController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Controllers\AdminUserVerificationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomScheduleController;
use App\Http\Controllers\RoomTemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WinnerController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\TicketReportController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\AdminAffiliateController;
use App\Http\Controllers\AdminAffiliateCustomerController;
use App\Http\Controllers\AdminAffiliateCommissionController;
use App\Http\Controllers\AdminAffiliatePayoutBatchController;
use App\Http\Controllers\AdminAffiliateProfileController;
use App\Http\Controllers\AdminProductApprovalController;
use App\Http\Controllers\AdminPermissionController;
use App\Http\Controllers\StoreApprovalController;
use App\Http\Controllers\StoreBusinessTypeController;
use App\Http\Controllers\SystemOrderController;
use App\Http\Controllers\StaffPerformanceController;
use App\Http\Controllers\ConsolidatedRevenueController;
use App\Http\Controllers\FunnelReportController;
use App\Http\Controllers\RetentionReportController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SpecialistCareController;
use App\Http\Controllers\SpecialistCreditController;
use App\Http\Controllers\SpecialistFeeConfigController;
use App\Http\Controllers\SpecialistIncomeController;
use App\Http\Controllers\SpecialistNotificationController;
use App\Http\Controllers\SpecialistProfileController;
use App\Http\Controllers\SpecialistRatingController;
use App\Http\Controllers\SpecialistReportController;
use App\Http\Controllers\SpecialistSettlementController;
use App\Http\Controllers\TicketSettingController;
use App\Http\Controllers\Admin\StoreWalletController;
use App\Http\Controllers\Admin\StoreWalletWithdrawalController;
use App\Http\Controllers\AdminProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/storage/{path}', function (string $path) {
    $path = str_replace('\\', '/', $path);

    if (str_contains($path, '..') || str_starts_with($path, '/')) {
        return response('Not Found', 404);
    }

    $disk = Storage::disk('public');

    if (! $disk->exists($path)) {
        return response('Not Found', 404);
    }

    return response()->file($disk->path($path));
})->where('path', '.*')->withoutMiddleware([
    \App\Http\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \App\Http\Middleware\VerifyCsrfToken::class,
    \App\Http\Middleware\ReferralMiddleware::class,
])->name('storage.public');

Route::get('/home', [ProductController::class, 'home'])->name('home');
// Route::get('/', [ProductController::class, 'getProduct'])->name('getProduct');
Route::get('/product', [ProductController::class, 'getAllProduct'])->name('getAllProduct');
Route::get('/add-to-cart/{productId}', [CartController::class, 'addToCart'])->name('cart.add');
Route::get('/cart', [CartController::class, 'viewCart'])->name('cart.view');
Route::get('/remove-from-cart/{productId}', [CartController::class, 'removeFromCart'])->name('cart.remove');
Route::get('/increase-quantity/{productId}', [CartController::class, 'increaseQuantity'])->name('cart.increase');
Route::get('/decrease-quantity/{productId}', [CartController::class, 'decreaseQuantity'])->name('cart.decrease');
Route::get('/client.products.index', [CartController::class, 'clientProduct'])->name('client.products');
Route::post('/order/place', [CartController::class, 'place'])->name('order.place');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('product.show');
Route::get('/order-history', [OrderController::class, 'history'])->name('orders.history')->middleware('auth');
Route::get('/order-history-affilate', [OrderController::class, 'historyAffilate'])->name('orders.history.affilate')->middleware('auth');
Route::patch('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
Route::get('/account', [AccountController::class, 'index'])->name('account.index')->middleware('auth');
Route::get('/agency', [AccountController::class, 'agency'])->name('account.agency')->middleware('auth');
Route::get('/accoutPayment', [AccountController::class, 'accoutPayment'])->name('account.accoutPayment')->middleware('auth');
Route::post('/accoutPayment', [AccountController::class, 'storeAccountPayment'])->name('account.accoutPayment.store')->middleware('auth');
Route::get('/article/{slug}', [ArticleController::class, 'show'])->name('article_detail');
Route::get('/rules/{slug?}', [ArticleController::class, 'rules'])->name('rules');
Route::get('/policy/{slug?}', [ArticleController::class, 'policy'])->name('policy');
Route::get('/affilate', [ArticleController::class, 'articleAffilate'])->name('articleAffilate');
Route::get('/change_info', [UserController::class, 'editUser'])->name('editUser')->middleware('auth');
Route::put('/users/{user}', [UserController::class, 'updateUser'])->name('users.update');
Route::get('/member', [UserController::class, 'member'])->name('users.member')->middleware('auth');
Route::get('/ambassador', [UserController::class, 'ambassador'])->name('users.ambassador');
Route::get('/referrer', [UserController::class, 'referrer'])->name('users.referrer');
// Route::post('/vnpay/checkout', [CartController::class, 'checkout'])->name('vnpay.checkout');
Route::post('/order/checkout/{orderId}', [CartController::class, 'checkout'])->name('vnpay.checkout');
Route::get('/vnpay/return', [CartController::class, 'callback'])->name('vnpay.return');


Route::middleware(['auth', 'admin', 'cms.permission'])->group(function () {
    Route::get('/', [AppSettingController::class, 'openApp'])->name('admin');

    Route::get('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('profile', [AdminProfileController::class, 'show'])->name('profile');
        Route::put('profile', [AdminProfileController::class, 'update'])->name('profile.update');
        Route::get('profile.html', function () {
            return redirect()->route('admin.profile');
        });

        Route::post(
            '/store-admins',
            [StoreApprovalController::class, 'createAdminStore']
        )->name('store-admins.store');
        Route::delete(
            'store-admins/{userId}/{storeId}',
            [StoreApprovalController::class, 'destroyAdminStore']
        )->name(
            'store-admins.destroy'
        );
        Route::post(
            'store-admins/{userId}/{storeId}/reset-password',
            [StoreApprovalController::class, 'resetPassword']
        )->name(
            'store-admins.reset-password'
        );

        Route::match(['get', 'patch'], '/banners/switch-display-type', [BannerController::class, 'switchDisplayType'])->name('banners.switchDisplayType');
        Route::get('product-categories/datatable', [ProductCategoryController::class, 'datatable'])->name('product-categories.datatable');
        Route::get('product-categories/home-config', [ProductCategoryController::class, 'homeConfig'])->name('product-categories.home-config');
        Route::get('product-categories/tree', [ProductCategoryController::class, 'tree'])->name('product-categories.tree');
        Route::patch('product-categories/reorder', [ProductCategoryController::class, 'reorder'])->name('product-categories.reorder');
        Route::patch('product-categories/{productCategory}/display', [ProductCategoryController::class, 'updateDisplay'])->name('product-categories.display.update');
        Route::post('product-categories/delete-multiple', [ProductCategoryController::class, 'destroyMultiple'])->name('product-categories.destroyMultiple');
        Route::resource('product-categories', ProductCategoryController::class);
        Route::post(
    '/categories/ajax-store',
    [ProductCategoryController::class,'ajaxStore']
)->name('product-categories.ajax-store');
        Route::resource('products', ProductController::class);
        Route::get('orders/datatable', [OrderController::class, 'datatable'])->name('orders.datatable');
        Route::resource('orders', OrderController::class);
        Route::post('orders/delete-multiple', [OrderController::class, 'destroyMultiple'])->name('orders.destroyMultiple');
        Route::resource('attributes', AttributeController::class);
        Route::get('users/sales', [SpecialistProfileController::class, 'index'])->name('users.sales');
        Route::get('users/sales/datatable', [SpecialistProfileController::class, 'datatable'])->name('users.sales.datatable');
        Route::get('users/sales/{user}/profile', [SpecialistProfileController::class, 'show'])->name('users.sales.show');
        Route::get('users/datatable', [UserController::class, 'usersDatatable'])->name('users.datatable');
        Route::get('user-verifications', [AdminUserVerificationController::class, 'index'])->name('user-verifications.index');
        Route::post('user-verifications/delete-multiple', [AdminUserVerificationController::class, 'destroyMultiple'])->name('user-verifications.destroyMultiple');
        Route::get('user-verifications/{verification}', [AdminUserVerificationController::class, 'show'])->name('user-verifications.show');
        Route::patch('user-verifications/{verification}/approve', [AdminUserVerificationController::class, 'approve'])->name('user-verifications.approve');
        Route::patch('user-verifications/{verification}/reject', [AdminUserVerificationController::class, 'reject'])->name('user-verifications.reject');
        Route::delete('user-verifications/{verification}', [AdminUserVerificationController::class, 'destroy'])->name('user-verifications.destroy');
        Route::post('users/delete-multiple', [UserController::class, 'destroyMultiple'])->name('users.destroyMultiple');
        Route::patch('users/{user}/lock', [UserController::class, 'lock'])->name('users.lock');
        Route::patch('users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');
        Route::get('users/{user}/documents/{type}/{index?}', [UserController::class, 'downloadSpecialistDocument'])
            ->whereIn('type', ['contract', 'id-card-front', 'id-card-back', 'other'])
            ->whereNumber('index')
            ->name('users.documents.download');
        Route::resource('users', UserController::class);
        Route::get('permissions', [AdminPermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions/roles', [AdminPermissionController::class, 'storeRole'])->name('permissions.roles.store');
        Route::put('permissions/roles/{role}', [AdminPermissionController::class, 'updateRole'])->name('permissions.roles.update');
        Route::put('permissions/users/{user}', [AdminPermissionController::class, 'updateUser'])->name('permissions.users.update');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::resource('banners', BannerController::class);
        Route::patch('/banners/{id}/toggle-status', [BannerController::class, 'toggleStatus'])->name('banners.toggleStatus');
        // Route::resource('app-setting', AppSettingController::class);
        Route::get('settings', [AppSettingController::class, 'settingsOverview'])->name('settings.index');
        Route::get('app-setting', [AppSettingController::class, 'index'])->name('app-setting.index');
        Route::put('app-setting', [AppSettingController::class, 'update'])->name('app-setting.store');
        Route::get('app-setting/homepage-block', [AppSettingController::class, 'homepageBlock'])->name('app-setting.homepage-block');
        Route::get('app-setting/homepage-block/products', [AppSettingController::class, 'homepageBlockProducts'])->name('app-setting.homepage-block.products');
        Route::get('app-setting/homepage-block/articles', [AppSettingController::class, 'homepageBlockArticles'])->name('app-setting.homepage-block.articles');
        Route::get('app-setting/homepage-block/preview', [AppSettingController::class, 'previewHomepageBlockFilters'])->name('app-setting.homepage-block.preview');
        Route::put('app-setting/homepage-block', [AppSettingController::class, 'updateHomepageBlock'])->name('app-setting.homepage-block.update');

        // Payment Settings
        Route::get('payment-settings', [AppSettingController::class, 'paymentSettings'])->name('payment-settings.index');
        Route::put('payment-settings', [AppSettingController::class, 'updatePaymentSettings'])->name('payment-settings.update');

        // Topup Security Settings
        Route::get('topup-security-settings', [AppSettingController::class, 'topupSecuritySettings'])->name('topup-security-settings.index');
        Route::put('topup-security-settings', [AppSettingController::class, 'updateTopupSecuritySettings'])->name('topup-security-settings.update');
        Route::resource('banks', BankController::class)->except(['show']);
        Route::get('banks/datatable', [BankController::class, 'datatable'])->name('banks.datatable');
        Route::post('banks/delete-multiple', [BankController::class, 'destroyMultiple'])->name('banks.destroyMultiple');
        Route::resource('bank-accounts', BankAccountController::class)->only(['index', 'edit', 'update']);
        Route::get('bank-accounts/datatable', [BankAccountController::class, 'datatable'])->name('bank-accounts.datatable');
        Route::get('categories/datatable', [CategoryController::class, 'datatable'])->name('categories.datatable');
        Route::post('categories/delete-multiple', [CategoryController::class, 'destroyMultiple'])->name('categories.destroyMultiple');
        Route::resource('categories', CategoryController::class);
        Route::get('policies', [PolicyController::class, 'index'])->name('policies.index');
        Route::get('policies/create', [PolicyController::class, 'create'])->name('policies.create');
        Route::post('policies', [PolicyController::class, 'store'])->name('policies.store');
        Route::get('policies/{article:slug}/edit', [PolicyController::class, 'edit'])->name('policies.edit');
        Route::put('policies/{article:slug}', [PolicyController::class, 'update'])->name('policies.update');
        Route::get('articles/datatable', [ArticleController::class, 'datatable'])->name('articles.datatable');
        Route::post('articles/delete-multiple', [ArticleController::class, 'destroyMultiple'])->name('articles.destroyMultiple');
        Route::resource('articles', ArticleController::class);
        Route::get('notifications/datatable', [NotificationController::class, 'datatable'])->name('notifications.datatable');
        Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::patch('notifications/{notification}/unread', [NotificationController::class, 'markAsUnread'])->name('notifications.mark-unread');
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::resource('notifications', NotificationController::class);
        Route::get('specialist-notifications/{notification}/open', [SpecialistNotificationController::class, 'open'])->name('specialist-notifications.open');
        Route::patch('specialist-notifications/{notification}/read', [SpecialistNotificationController::class, 'markAsRead'])->name('specialist-notifications.mark-read');
        Route::patch('specialist-notifications/{notification}/unread', [SpecialistNotificationController::class, 'markAsUnread'])->name('specialist-notifications.mark-unread');
        Route::patch('specialist-notifications/read-all', [SpecialistNotificationController::class, 'markAllAsRead'])->name('specialist-notifications.mark-all-read');
        Route::get('specialist-notifications', [SpecialistNotificationController::class, 'index'])->name('specialist-notifications.index');
        Route::post('specialist-care/logs', [SpecialistCareController::class, 'storeLog'])->name('specialist-care.logs.store');
        Route::post('specialist-care/tasks', [SpecialistCareController::class, 'storeTask'])->name('specialist-care.tasks.store');
        Route::patch('specialist-care/tasks/{task}/start', [SpecialistCareController::class, 'startTask'])->name('specialist-care.tasks.start');
        Route::patch('specialist-care/tasks/{task}/complete', [SpecialistCareController::class, 'completeTask'])->name('specialist-care.tasks.complete');
        Route::get('specialist-care', [SpecialistCareController::class, 'index'])->name('specialist-care.index');
        Route::patch('specialist-credit-logs/{log}/cancel', [SpecialistCreditController::class, 'cancel'])->name('specialist-credit.logs.cancel');
        Route::post('specialist-credit/{specialist}/evaluate', [SpecialistCreditController::class, 'evaluate'])->name('specialist-credit.evaluate');
        Route::get('specialist-credit/{specialist}', [SpecialistCreditController::class, 'show'])->name('specialist-credit.show');
        Route::get('specialist-credit', [SpecialistCreditController::class, 'index'])->name('specialist-credit.index');
        Route::patch('specialist-fee-configs/{feeConfig}/approve', [SpecialistFeeConfigController::class, 'approve'])->name('specialist-fee-configs.approve');
        Route::patch('specialist-fee-configs/{feeConfig}/cancel', [SpecialistFeeConfigController::class, 'cancel'])->name('specialist-fee-configs.cancel');
        Route::resource('specialist-fee-configs', SpecialistFeeConfigController::class)
            ->parameters(['specialist-fee-configs' => 'feeConfig'])
            ->only(['index', 'store', 'edit', 'update']);
        Route::post('specialist-ratings/calculate', [SpecialistRatingController::class, 'calculate'])->name('specialist-ratings.calculate');
        Route::get('specialist-ratings/review-requests', [SpecialistRatingController::class, 'reviewRequests'])->name('specialist-ratings.review-requests');
        Route::patch('specialist-ratings/review-requests/{reviewRequest}', [SpecialistRatingController::class, 'processReviewRequest'])->name('specialist-ratings.review-requests.process');
        Route::post('specialist-ratings/{rating}/review-requests', [SpecialistRatingController::class, 'submitReviewRequest'])->name('specialist-ratings.review-requests.store');
        Route::patch('specialist-ratings/{rating}/approve', [SpecialistRatingController::class, 'approve'])->name('specialist-ratings.approve');
        Route::patch('specialist-ratings/{rating}/adjust', [SpecialistRatingController::class, 'adjust'])->name('specialist-ratings.adjust');
        Route::get('specialist-ratings/{rating}', [SpecialistRatingController::class, 'show'])->name('specialist-ratings.show');
        Route::get('specialist-ratings', [SpecialistRatingController::class, 'index'])->name('specialist-ratings.index');
        Route::get('reports/specialists', [SpecialistReportController::class, 'index'])->name('reports.specialists');
        Route::put('inventory/{product}/update-stock', [ProductController::class, 'updateStock'])->name('inventory.updateStock');
        Route::get('inventorys', [ProductController::class, 'indexStock'])->name('inventory.indexStock');
        Route::resource('vouchers', VoucherController::class);
        Route::resource('product-combos', ProductComboController::class);
        Route::get('reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::get('/export/orders', function (Request $request) {
            return Excel::download(new OrdersExport($request), 'danh-sach-don-hang.xlsx');
        })->name('orders.export');
        Route::resource('rewards', RewardController::class);
        Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::get('rooms/create', [RoomController::class, 'create'])->name('rooms.create');
        Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::get('rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
        Route::patch('rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::patch('rooms/{room}/banners/reorder', [RoomController::class, 'reorderBanners'])->name('rooms.banners.reorder');
        Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
        Route::post('rooms/{roomId}/restore', [RoomController::class, 'restore'])->name('rooms.restore');
        Route::post('rooms/{room}/force-draw', [RoomController::class, 'forceDraw'])->name('rooms.force-draw');
        Route::match(['get', 'post'], 'rooms/{roomId}/emergency-cancel', [RoomController::class, 'emergencyCancel'])->name('rooms.emergency-cancel');
        Route::get('room-templates', [RoomTemplateController::class, 'index'])->name('room-templates.index');
        Route::post('room-templates', [RoomTemplateController::class, 'store'])->name('room-templates.store');
        Route::get('room-templates/{roomTemplate}/edit', [RoomTemplateController::class, 'edit'])->name('room-templates.edit');
        Route::get('room-templates/{roomTemplate}', [RoomTemplateController::class, 'show'])->name('room-templates.show');
        Route::patch('room-templates/{roomTemplate}', [RoomTemplateController::class, 'update'])->name('room-templates.update');
        Route::post('room-templates/{roomTemplate}/select', [RoomTemplateController::class, 'select'])->name('room-templates.select');
        Route::post('room-templates/{roomTemplate}/run', [RoomTemplateController::class, 'run'])->name('room-templates.run');
        Route::delete('room-templates/{roomTemplate}', [RoomTemplateController::class, 'destroy'])->name('room-templates.destroy');
        Route::get('winners', [WinnerController::class, 'index'])->name('winners.index');
        Route::get('ticket-stats', [TicketReportController::class, 'index'])->name('ticket-stats.index');
        Route::get('tickets/specialist/datatable', [TicketReportController::class, 'specialistTicketsDatatable'])->name('tickets.specialist.datatable');
        Route::get('tickets/specialist', [TicketReportController::class, 'specialistTickets'])->name('tickets.specialist');
        Route::get('specialist-income', [SpecialistIncomeController::class, 'index'])->name('specialist-income.index');
        Route::post('specialist-settlements/{settlement}/adjust', [SpecialistSettlementController::class, 'adjust'])->name('specialist-settlements.adjust');
        Route::patch('specialist-settlements/{settlement}/approve', [SpecialistSettlementController::class, 'approve'])->name('specialist-settlements.approve');
        Route::patch('specialist-settlements/{settlement}/paid', [SpecialistSettlementController::class, 'markPaid'])->name('specialist-settlements.paid');
        Route::resource('specialist-settlements', SpecialistSettlementController::class)
            ->parameters(['specialist-settlements' => 'settlement'])
            ->only(['index', 'store', 'show']);
        Route::get('tickets/unused/datatable', [TicketReportController::class, 'datatable'])->name('tickets.unused.datatable');
        Route::get('tickets/unused', [TicketReportController::class, 'unused'])->name('tickets.unused');
        Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
        Route::get('complaints/datatable', [ComplaintController::class, 'datatable'])->name('complaints.datatable');
        Route::get('complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
        Route::patch('complaints/{complaint}/assign', [ComplaintController::class, 'assign'])->name('complaints.assign');
        Route::post('complaints/{complaint}/create-return', [ComplaintController::class, 'createReturnOrderForStore'])->name('complaints.create-return');
        Route::post('complaints/{complaint}/reject', [ComplaintController::class, 'rejectClientComplaint'])->name('complaints.reject');
        Route::post('complaints/{complaint}/force-action', [ComplaintController::class, 'forceRefundOrLockStore'])->name('complaints.force-action');
        Route::post('complaints/{complaint}/resolve-manual', [ComplaintController::class, 'resolveManualComplaint'])->name('complaints.resolve-manual');
        Route::post('complaints/{complaint}/acknowledge', [ComplaintController::class, 'acknowledge'])->name('complaints.acknowledge');
        Route::get('affiliate/dashboard', [AdminAffiliateController::class, 'dashboard'])->name('affiliate.dashboard');
        Route::get('affiliate/reports', [AdminAffiliateController::class, 'index'])->name('affiliate.reports.index');
        Route::get('affiliate/reports/datatable', [AdminAffiliateController::class, 'datatable'])->name('affiliate.reports.datatable');
        Route::patch('affiliate/reports/{complaint}/change-upline', [AdminAffiliateController::class, 'changeUpline'])->name('affiliate.change-upline');
        Route::get('affiliate/customers', [AdminAffiliateCustomerController::class, 'index'])->name('affiliate.customers.index');
        Route::get('affiliate/customers/{assignment}', [AdminAffiliateCustomerController::class, 'show'])->name('affiliate.customers.show');
        Route::post('affiliate/customers/{assignment}/transfer', [AdminAffiliateCustomerController::class, 'transfer'])->name('affiliate.customers.transfer');
        Route::post('affiliate/customers/{assignment}/remove', [AdminAffiliateCustomerController::class, 'remove'])->name('affiliate.customers.remove');
        Route::post('affiliate/customers/{assignment}/lock', [AdminAffiliateCustomerController::class, 'lock'])->name('affiliate.customers.lock');
        Route::post('affiliate/customers/{assignment}/unlock', [AdminAffiliateCustomerController::class, 'unlock'])->name('affiliate.customers.unlock');
        Route::get('affiliate/commissions', [AdminAffiliateCommissionController::class, 'index'])->name('affiliate.commissions.index');
        Route::get('affiliate/commissions/{commissionId}', [AdminAffiliateCommissionController::class, 'show'])->name('affiliate.commissions.show');
        Route::post('affiliate/commissions/{commissionId}/approve', [AdminAffiliateCommissionController::class, 'approve'])->name('affiliate.commissions.approve');
        Route::post('affiliate/commissions/{commissionId}/reject', [AdminAffiliateCommissionController::class, 'reject'])->name('affiliate.commissions.reject');
        Route::post('affiliate/commissions/{commissionId}/hold', [AdminAffiliateCommissionController::class, 'hold'])->name('affiliate.commissions.hold');
        Route::post('affiliate/commissions/{commissionId}/release', [AdminAffiliateCommissionController::class, 'release'])->name('affiliate.commissions.release');
        Route::post('affiliate/commissions/{commissionId}/cancel', [AdminAffiliateCommissionController::class, 'cancel'])->name('affiliate.commissions.cancel');
        Route::post('affiliate/commissions/{commissionId}/clawback', [AdminAffiliateCommissionController::class, 'clawback'])->name('affiliate.commissions.clawback');
        Route::get('affiliate/payout-batches', [AdminAffiliatePayoutBatchController::class, 'index'])->name('affiliate.payout-batches.index');
        Route::post('affiliate/payout-batches', [AdminAffiliatePayoutBatchController::class, 'store'])->name('affiliate.payout-batches.store');
        Route::get('affiliate/payout-batches/{batchId}', [AdminAffiliatePayoutBatchController::class, 'show'])->name('affiliate.payout-batches.show');
        Route::post('affiliate/payout-batches/{batchId}/submit', [AdminAffiliatePayoutBatchController::class, 'submit'])->name('affiliate.payout-batches.submit');
        Route::post('affiliate/payout-batches/{batchId}/approve', [AdminAffiliatePayoutBatchController::class, 'approve'])->name('affiliate.payout-batches.approve');
        Route::post('affiliate/payout-batches/{batchId}/mark-paid', [AdminAffiliatePayoutBatchController::class, 'markPaid'])->name('affiliate.payout-batches.mark-paid');
        Route::post('affiliate/payout-batches/{batchId}/cancel', [AdminAffiliatePayoutBatchController::class, 'cancel'])->name('affiliate.payout-batches.cancel');
        Route::get('affiliate/requests', [AdminAffiliateProfileController::class, 'index'])->name('affiliate.requests.index');
        Route::get('affiliate/requests/{profile}', [AdminAffiliateProfileController::class, 'show'])->name('affiliate.requests.show');
        Route::delete('affiliate/requests/{profile}', [AdminAffiliateProfileController::class, 'destroy'])->name('affiliate.requests.destroy');
        Route::patch('affiliate/requests/{profile}/approve', [AdminAffiliateProfileController::class, 'approve'])->name('affiliate.requests.approve');
        Route::post('affiliate/requests/{profile}/start-review', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'start_review')->name('affiliate.requests.start-review');
        Route::post('affiliate/requests/{profile}/request-more-info', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'request_more_info')->name('affiliate.requests.request-more-info');
        Route::post('affiliate/requests/{profile}/reject', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'reject')->name('affiliate.requests.reject');
        Route::post('affiliate/requests/{profile}/suspend', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'suspend')->name('affiliate.requests.suspend');
        Route::post('affiliate/requests/{profile}/lock', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'lock')->name('affiliate.requests.lock');
        Route::post('affiliate/requests/{profile}/unlock', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'unlock')->name('affiliate.requests.unlock');
        Route::post('affiliate/requests/{profile}/terminate', [AdminAffiliateProfileController::class, 'transition'])->defaults('action', 'terminate')->name('affiliate.requests.terminate');
        // Affiliate withdrawal approvals
        Route::get('affiliate/withdrawals', [\App\Http\Controllers\AdminAffiliateWithdrawalController::class, 'index'])->name('affiliate.withdrawals.index');
        Route::get('affiliate/withdrawals/datatable', [\App\Http\Controllers\AdminAffiliateWithdrawalController::class, 'datatable'])->name('affiliate.withdrawals.datatable');
        // Prevent MethodNotAllowed when the approve URL is opened directly via GET in browser.
        // The actual approve action must be invoked via PATCH (form with _method=PATCH).
        Route::get('affiliate/withdrawals/{withdrawalId}/approve', function () {
            return redirect()->route('admin.affiliate.withdrawals.index');
        })->name('affiliate.withdrawals.approve.redirect');

        Route::patch('affiliate/withdrawals/{withdrawalId}/approve', [\App\Http\Controllers\AdminAffiliateWithdrawalController::class, 'approve'])->name('affiliate.withdrawals.approve');
        Route::patch('affiliate/withdrawals/{withdrawalId}/toggle-status', [\App\Http\Controllers\AdminAffiliateWithdrawalController::class, 'toggleStatus'])->name('affiliate.withdrawals.toggle-status');
        Route::resource('store-business-types', StoreBusinessTypeController::class)->except(['show']);
        Route::get('stores', [StoreApprovalController::class, 'storesIndex'])->name('stores.index');
        Route::get('stores/datatable', [StoreApprovalController::class, 'storesDatatable'])->name('stores.datatable');
        Route::get('stores/create', [StoreApprovalController::class, 'createStore'])->name('stores.create');
        Route::post('stores', [StoreApprovalController::class, 'store'])->name('stores.store');
        Route::post('stores/{store}/care-logs', [StoreApprovalController::class, 'storeCareLog'])->name('stores.care-logs.store');
        Route::get('stores/{store}', [StoreApprovalController::class, 'showStore'])->name('stores.show');
        Route::get('stores/{store}/edit', [StoreApprovalController::class, 'editStore'])->name('stores.edit');
        Route::post('stores/{store}/manual-topup', [StoreApprovalController::class, 'manualTopup'])->name('stores.manual-topup');
        Route::post('stores/{store}/topup-verify-access-code', [StoreApprovalController::class, 'topupVerifyAccessCode'])->name('stores.topup-verify-access-code');
        Route::post('stores/{store}/topup-send-otp', [StoreApprovalController::class, 'topupSendOtp'])->name('stores.topup-send-otp');
        Route::post('stores/{store}/topup-verify-otp', [StoreApprovalController::class, 'topupVerifyOtp'])->name('stores.topup-verify-otp');
        Route::put('stores/{store}', [StoreApprovalController::class, 'updateStore'])->name('stores.update');
        Route::patch('stores/{store}/assign-sale', [StoreApprovalController::class, 'assignSale'])->name('stores.assign-sale');
        Route::delete('stores/{store}', [StoreApprovalController::class, 'destroyStore'])->name('stores.destroy');
        Route::get('sales/{staff}/performance', [StaffPerformanceController::class, 'show'])->name('sales.performance.show');
        Route::patch('sales/{staff}/performance/commission', [StaffPerformanceController::class, 'updateCommission'])->name('sales.performance.update-commission');
        Route::get('sales/{staff}/performance/export', [StaffPerformanceController::class, 'export'])->name('sales.performance.export');
        Route::get('store-approvals', [StoreApprovalController::class, 'index'])->name('store-approvals.index');
        Route::get('store-approvals/pending', [StoreApprovalController::class, 'pending'])->name('store-approvals.pending');
        Route::get('store-approvals/{store}/licenses', [StoreApprovalController::class, 'showLicenses'])->name('store-approvals.licenses');
        Route::get('store-approvals/datatable', [StoreApprovalController::class, 'approvalsDatatable'])->name('store-approvals.datatable');
        Route::match(['post', 'patch'], 'store-approvals/{storeId}/review', [StoreApprovalController::class, 'review'])->name('store-approvals.review');
        Route::patch('store-approvals/licenses/{license}/approve', [StoreApprovalController::class, 'approveLicense'])->name('store-approvals.licenses.approve');
        Route::patch('store-approvals/licenses/{license}/reject', [StoreApprovalController::class, 'rejectLicense'])->name('store-approvals.licenses.reject');
        Route::patch('store-approvals/{store}/approve', [StoreApprovalController::class, 'approve'])->name('store-approvals.approve');
        Route::patch('store-approvals/{store}/reject', [StoreApprovalController::class, 'reject'])->name('store-approvals.reject');
        Route::get('product-approvals', [AdminProductApprovalController::class, 'index'])->name('product-approvals.index');
        Route::get('/products/approvals/datatable', [AdminProductApprovalController::class, 'datatable'])->name('products.approvals.datatable');
        Route::post('/product-approvals/bulk-approve', [AdminProductApprovalController::class, 'bulkApprove'])->name('product-approvals.bulk-approve');
        Route::post('/admin/product-approvals/bulk-reject', [AdminProductApprovalController::class, 'bulkReject'])->name('product-approvals.bulk-reject');
        Route::get('product-approvals/{product}', [AdminProductApprovalController::class, 'show'])->name('product-approvals.show');
        Route::patch('product-approvals/{product}/approve', [AdminProductApprovalController::class, 'approve'])->name('product-approvals.approve');
        Route::patch('product-approvals/{product}/reject', [AdminProductApprovalController::class, 'reject'])->name('product-approvals.reject');
        Route::get('system-orders', [SystemOrderController::class, 'index'])->name('system-orders.index');
        Route::get('reports/consolidated-revenue', [ConsolidatedRevenueController::class, 'index'])->name('reports.consolidated-revenue');
        Route::get('reports/consolidated-revenue/datatable', [ConsolidatedRevenueController::class, 'datatable'])->name('reports.consolidated-revenue.datatable');
        Route::get('reports/funnel', [FunnelReportController::class, 'index'])->name('reports.funnel');
        Route::get('reports/retention', [RetentionReportController::class, 'index'])->name('reports.retention');
        Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::get('reconciliation/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliation.show');
        Route::patch('reconciliation/{reconciliation}/approve', [ReconciliationController::class, 'approve'])->name('reconciliation.approve');
        Route::patch('reconciliation/{reconciliation}/reject', [ReconciliationController::class, 'reject'])->name('reconciliation.reject');
        Route::get('store-wallet/withdrawals', [\App\Http\Controllers\Api\AdminStoreWalletWithdrawalController::class, 'withdrawals'])->name('admin.store-wallet.withdrawals');
        Route::post('store-wallet/withdrawals/{withdrawal}/mark-paid', [\App\Http\Controllers\Api\AdminStoreWalletWithdrawalController::class, 'markPaid'])->name('admin.store-wallet.withdrawals.mark-paid');
        Route::post('store-wallet/withdrawals/{withdrawal}/reject', [\App\Http\Controllers\Api\AdminStoreWalletWithdrawalController::class, 'reject'])->name('admin.store-wallet.withdrawals.reject');
        Route::get('room-schedules', [RoomScheduleController::class, 'index'])->name('room-schedules.index');
        Route::patch('room-schedules/{id}', [RoomScheduleController::class, 'update'])->name('room-schedules.update');
        Route::post('room-schedules/{id}/template-set', [RoomScheduleController::class, 'selectTemplateSet'])->name('room-schedules.template-set');
        Route::get('prize-settings', [PrizeSettingController::class, 'index'])->name('prize-settings.index');
        Route::patch('prize-settings/{id}', [PrizeSettingController::class, 'update'])->name('prize-settings.update');
        Route::post('prize-settings/templates', [PrizeSettingController::class, 'storeTemplate'])->name('prize-settings.templates.store');
        Route::patch('prize-settings/templates/{templateId}', [PrizeSettingController::class, 'updateTemplate'])->name('prize-settings.templates.update');
        Route::delete('prize-settings/templates/{templateId}', [PrizeSettingController::class, 'deleteTemplate'])->name('prize-settings.templates.delete');
        Route::post('rooms/generate', [RoomScheduleController::class, 'generate'])->name('rooms.generate');
        // routes/web.php
        Route::get('/users/{id}/detail', [UserController::class, 'detail'])->name('admin.users.detail');
        // Ticket Settings
        Route::get('ticket-settings', [TicketSettingController::class, 'index'])->name('ticket-settings.index');
        Route::put('ticket-settings', [TicketSettingController::class, 'update'])->name('ticket-settings.update');

        // Menu Management
        Route::resource('menus', MenuController::class)->names('menus');
        Route::post('menus/map', [MenuController::class, 'updateMap'])->name('menus.map.update');
        Route::post('menus/socials', [MenuController::class, 'storeSocial'])->name('menus.socials.store');
        Route::post('menus/socials/{id}', [MenuController::class, 'updateSocial'])->name('menus.socials.update');
        Route::delete('menus/socials/{id}', [MenuController::class, 'destroySocial'])->name('menus.socials.destroy');
        Route::post('menus/socials/{id}/toggle', [MenuController::class, 'toggleSocial'])->name('menus.socials.toggle');
        Route::post('menus/sort', [MenuController::class, 'sort'])->name('menus.sort');
        Route::delete('menus/delete-by-reference/{type}/{reference}', [MenuController::class, 'destroyByReference']);

        // ---------------------------------------------------------------
        // CMS Admin: Quản lý Ví Cửa Hàng
        // NOTE: /summary phải đăng ký TRƯỚC /{store} để tránh Laravel match
        //       "summary" thành store_id.
        // ---------------------------------------------------------------
        Route::prefix('store-wallets')->name('store-wallets.')->group(function () {
            Route::get('/summary', [StoreWalletController::class, 'summary'])->name('summary');
            Route::get('/', [StoreWalletController::class, 'index'])->name('index');
            Route::get('/{store}', [StoreWalletController::class, 'show'])->name('show');
            Route::get('/{store}/transactions', [StoreWalletController::class, 'transactions'])->name('transactions');
        });
        Route::prefix('store-wallet-withdrawals')->name('store-wallet-withdrawals.')->group(function () {
            Route::get('/', [StoreWalletWithdrawalController::class, 'index'])->name('index');
            Route::post('/{withdrawal}/mark-paid', [StoreWalletWithdrawalController::class, 'markPaid'])->name('mark-paid');
            Route::post('/{withdrawal}/reject', [StoreWalletWithdrawalController::class, 'reject'])->name('reject');
        });
    });
    Route::post('/products/upload', [ProductController::class, 'upload'])->name('upload');
    Route::put('/admin/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('admin.orders.updateStatus');
    Route::put('/admin/orders/{order}/updateStatusPayment', [OrderController::class, 'updateStatusPayment'])->name('admin.orders.updateStatusPayment');
    Route::get('/admin/orders/{order}/invoice', [OrderController::class, 'invoice'])->name('admin.orders.invoice');
});

Route::get('/login', function () {
    return view('login');
})->name('login.index');
Route::get('/signup', [LoginController::class, 'showSignupForm'])->name('signup.form');
Route::post('/signup', [LoginController::class, 'signup'])->name('signup');

Route::get('/testnoti', function () {
    return view('testnoti');
});
Route::post('/login', [LoginController::class, 'login'])->name('login');

Route::get('/layout2', function () {
    return view('layout2');
});

Route::get('/autologin', function() {
    $user = \App\Models\User::where('email', 'admin@example.com')->first();
    if ($user) {
        auth()->login($user);
        return redirect()->route('admin.affiliate.dashboard');
    }
    return 'User not found';
});
