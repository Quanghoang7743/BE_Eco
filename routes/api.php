<?php
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminMoneyWalletController;
use App\Http\Controllers\Api\AdminOrderReturnController;
use App\Http\Controllers\Api\AdminStoreWalletWithdrawalController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ClientCartController;
use App\Http\Controllers\Api\ClientArticleInteractionController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientCatalogController;
use App\Http\Controllers\Api\ClientComplaintController;
use App\Http\Controllers\Api\ClientNotificationController;
use App\Http\Controllers\Api\ClientOrderController;
use App\Http\Controllers\Api\ClientPaymentController;
use App\Http\Controllers\Api\ClientPasswordResetController;
use App\Http\Controllers\Api\ClientProductFavoriteController;
use App\Http\Controllers\Api\ClientReviewController;
use App\Http\Controllers\Api\ClientStorePreferenceController;
use App\Http\Controllers\Api\ClientUserController;
use App\Http\Controllers\Api\ClientAffiliateController;
use App\Http\Controllers\Api\ClientVerificationUser;
use App\Http\Controllers\Api\ClientWalletController;
use App\Http\Controllers\Api\ClientTicketController;
use App\Http\Controllers\Api\CustomerMoneyWalletController;
use App\Http\Controllers\Api\CustomerOrderReturnController;
use App\Http\Controllers\Api\RewardApiController;
use App\Http\Controllers\Api\MenuApiController;
use App\Http\Controllers\Api\AdminAffiliateController;
use App\Http\Controllers\Api\AdminAffiliateCustomerController;
use App\Http\Controllers\Api\AdminAffiliateCommissionController;
use App\Http\Controllers\Api\AdminAffiliatePayoutBatchController;
use App\Http\Controllers\Api\AdminAffiliateProfileController;
use App\Http\Controllers\Api\RbacAuthController;
use App\Http\Controllers\Api\StoreAdminAuthController;
use App\Http\Controllers\Api\StoreAdminComplaintController;
use App\Http\Controllers\Api\StoreAdminCustomerController;
use App\Http\Controllers\Api\StoreAdminDashboardController;
use App\Http\Controllers\Api\MyStoreController;
use App\Http\Controllers\Api\StoreAdminFinanceController;
use App\Http\Controllers\Api\StoreAdminLicenseController;
use App\Http\Controllers\Api\StoreAdminFlashSaleController;
use App\Http\Controllers\Api\StoreAdminMenuController;
use App\Http\Controllers\Api\StoreAdminNotificationController;
use App\Http\Controllers\Api\StoreAdminOrderController;
use App\Http\Controllers\Api\StoreAdminOrderReturnController;
use App\Http\Controllers\Api\StoreAdminOrderImportController;
use App\Http\Controllers\Api\StoreAdminOverviewController;
use App\Http\Controllers\Api\StoreAdminPasswordResetController;
use App\Http\Controllers\Api\StoreAdminProductController;
use App\Http\Controllers\Api\StoreAdminProductCategoryController;
use App\Http\Controllers\Api\StoreAdminProductDraftController;
use App\Http\Controllers\Api\StoreAdminProductDraftVariantConfigController;
use App\Http\Controllers\Api\StoreAdminProductValidationController;
use App\Http\Controllers\Api\StoreAdminRegistrationController;
use App\Http\Controllers\Api\StoreAdminReportController;
use App\Http\Controllers\Api\StoreAdminReviewController;
use App\Http\Controllers\Api\StoreAdminSettingsController;
use App\Http\Controllers\Api\StoreAdminStaffController;
use App\Http\Controllers\Api\StoreAdminTicketController;
use App\Http\Controllers\Api\StoreBusinessTypeController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\ProductCategoryNavigationController;
use App\Http\Controllers\Api\TicketConfigController;
use App\Http\Controllers\Api\StoreAdminUploadController;
use App\Http\Controllers\Api\VoucherApiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CustomerDrawController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', [ClientController::class, 'authenticatedUser']);
Route::middleware('auth:sanctum')->post('/user/change-password', [ClientUserController::class, 'changePassword']);

Route::get('/index', [ClientCatalogController::class, 'index']);
Route::get('/footer-menus', [MenuApiController::class, 'footerMenus']);
Route::get('/ecoluck/home/banners', [ClientCatalogController::class, 'heroBanners']);
Route::get('/ecoluck/home/hero-banner', [ClientCatalogController::class, 'heroBanner']);
Route::get('/ecoluck/home/banner', [ClientCatalogController::class, 'heroBanner']);
Route::get('/getInfoApp', [ClientCatalogController::class, 'getInfoApp']);
Route::get('/checkUser', [ClientController::class, 'checkUser']);
Route::get('/order-history-affilate', [ClientController::class, 'historyAffilate']);
Route::get('/order-history', [ClientController::class, 'historyOrder']);
Route::middleware('auth:sanctum')->get('/agency', [ClientController::class, 'agencyApi']);
Route::get('/commission/top10', [ClientController::class, 'topCommissionEarners']);
Route::get('/product/{slug}', [ClientCatalogController::class, 'showProduct'])->name('product.show');
Route::get('/article/{slug}', [ClientCatalogController::class, 'showArticle']);
Route::get('/articles/tabs', [ClientCatalogController::class, 'articleTabs']);
Route::get('/articles/section/{displayArea}', [ClientCatalogController::class, 'showArticlesBySection']);
Route::get('/articles/section/{displayArea}/list', [ClientCatalogController::class, 'listArticlesBySection']);
Route::post('/articles/{articleId}/share', [ClientArticleInteractionController::class, 'trackShare'])
    ->whereNumber('articleId');
Route::get('/customer/flash-sales/products', [ClientCatalogController::class, 'customerFlashSaleProducts']);
Route::get('/customer/flash-sales/upcoming-schedule', [ClientCatalogController::class, 'upcomingFlashSaleSchedule']);
Route::get('/policies', [PolicyController::class, 'index']);
Route::get('/policies/{slug}', [PolicyController::class, 'show']);
Route::get('/partner/business-types', [StoreBusinessTypeController::class, 'publicIndex']);
// Route::post('/cart/add/{productId}', [ClientController::class, 'addToCart']);
// Route::get('/cart', [ClientController::class, 'viewCart']);
Route::get('/check-stock/{productId}', [ClientCatalogController::class, 'checkStock']);
Route::get('/check-product/{id}', [ClientCatalogController::class, 'showProductId']);
Route::post('/order/place', [ClientOrderController::class, 'place']);
Route::post('/order/place-from-menu', [ClientOrderController::class, 'placeFromMenu']);
Route::post('/order/call-prepaid', [ClientOrderController::class, 'callPrepaidOrder']);
Route::post('/order/place-from-ticket', [ClientOrderController::class, 'placeFromTicket']);
Route::post('/order/pre-create', [ClientOrderController::class, 'preCreate']);
Route::get('/vnpay/return', [ClientOrderController::class, 'callback'])->name('api.vnpay.return');
Route::get('/products', [ClientCatalogController::class, 'getAllProduct']);
Route::get('/customer/products/search', [ClientCatalogController::class, 'getAllProduct'])->name('customer.products.search');
Route::get('/categories/home-nav', [ProductCategoryNavigationController::class, 'homeNav']);
Route::get('/categories/featured', [ProductCategoryNavigationController::class, 'featured']);
Route::get('/categories/{id}', [ClientCatalogController::class, 'showCategoryById']);
Route::get('/categories', [ClientCatalogController::class, 'getAllCategory']);
Route::post('/login', [ClientUserController::class, 'Login']);
Route::post('/register', [ClientUserController::class, 'register']);
Route::prefix('password')->group(function () {
    Route::post('/lookup-email', [ClientPasswordResetController::class, 'lookupEmail']);
    Route::post('/forgot', [ClientPasswordResetController::class, 'forgot']);
    Route::post('/verify-otp', [ClientPasswordResetController::class, 'verifyOtp']);
    Route::post('/reset', [ClientPasswordResetController::class, 'reset']);
});
Route::get('/users/check-receiver', [ClientUserController::class, 'checkReceiver']);
Route::get('/members', [ClientUserController::class, 'getMembers']);
Route::post('/order/checkout/{orderId}', [ClientOrderController::class, 'checkout'])->name('vnpay.checkout');

Route::put('/orders/{order}/cancel', [ClientOrderController::class, 'cancel']);
Route::post('/customer/orders/{order}/cancel', [ClientOrderController::class, 'cancel']);
Route::put('/orders/{order}/address', [ClientOrderController::class, 'updateAddress']);
Route::get('/users/me', [ClientUserController::class, 'me']);
Route::put('/users/{user}', [ClientUserController::class, 'updateUser'])->name('users.update');
Route::post('/users/{user}/avatar', [ClientUserController::class, 'updateAvatar'])->name('users.avatar');
Route::post('/zalo/signup', [ClientUserController::class, 'zaloSignup']);
Route::post('/payment/zalo/callback', [ClientPaymentController::class, 'handleZaloCallback']);
Route::get('/api/vnpay/ipn', [ClientPaymentController::class, 'handleIPN']);
Route::get('/orders', [ClientOrderController::class, 'indexOrder']); // danh sách
Route::get('/orders/{id}', [ClientOrderController::class, 'showOrderDetail']); // chi tiết
Route::post('/complaints', [ClientComplaintController::class, 'store'])->middleware('auth:sanctum');
Route::post('/complaints/upload', [ClientComplaintController::class, 'upload'])->middleware('auth:sanctum');
Route::patch('/complaints/{id}/status', [ClientComplaintController::class, 'updateStatus'])->middleware('auth:sanctum');
Route::post('/complaints/{id}/cancel', [ClientComplaintController::class, 'cancelComplaint'])->middleware('auth:sanctum');
Route::get('/reviews/my-order-ids', [ClientReviewController::class, 'reviewedOrderIds']);
Route::get('/reviews', [ClientReviewController::class, 'myReviews']);
Route::get('/reviews/me', [ClientReviewController::class, 'myReviews']);
Route::get('/reviews/my', [ClientReviewController::class, 'myReviews']);
Route::post('/reviews', [ClientReviewController::class, 'store']);
Route::get('/stores/{storeId}/reviews', [ClientReviewController::class, 'storeReviews']);
Route::get('/menu/{menuItemId}/reviews', [ClientReviewController::class, 'menuItemReviews']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [ClientNotificationController::class, 'notifications']);
    Route::patch('/notifications/{id}/read', [ClientNotificationController::class, 'markNotificationRead']);
    Route::patch('/notifications/read-all', [ClientNotificationController::class, 'markAllNotificationsRead']);
    Route::post('/notifications/store-invitations/{inviteId}/accept', [ClientNotificationController::class, 'acceptStoreInvitation']);
    Route::post('/notifications/store-invitations/{inviteId}/decline', [ClientNotificationController::class, 'declineStoreInvitation']);
    Route::post('/products/{productId}/favorite', [ClientProductFavoriteController::class, 'toggle'])
        ->whereNumber('productId');
    Route::get('/user/favorites/products', [ClientProductFavoriteController::class, 'index']);
    Route::get('/user/articles/interactions', [ClientArticleInteractionController::class, 'index']);
    Route::get('/articles/{articleId}/interaction', [ClientArticleInteractionController::class, 'status'])
        ->whereNumber('articleId');
    Route::post('/articles/{articleId}/like', [ClientArticleInteractionController::class, 'toggleLike'])
        ->whereNumber('articleId');
    Route::post('/articles/{articleId}/save', [ClientArticleInteractionController::class, 'toggleSave'])
        ->whereNumber('articleId');
    Route::get('/stores/{storeId}/preference', [ClientStorePreferenceController::class, 'getPreference']);
    Route::put('/stores/{storeId}/preference', [ClientStorePreferenceController::class, 'savePreference']);
    Route::get('/me/followed-stores', [ClientStorePreferenceController::class, 'followedStores']);
    Route::get('/me/favorite-stores', [ClientStorePreferenceController::class, 'favoriteStores']);
    Route::post('/customer/orders/{order}/return-request', [CustomerOrderReturnController::class, 'create'])->middleware('throttle:10,1');
    Route::post('/customer/order-returns/{return}/ship', [CustomerOrderReturnController::class, 'ship']);
    Route::post('/customer/order-returns/{return}/arrive-at-store', [CustomerOrderReturnController::class, 'arriveAtStore']);
    Route::post('/customer/order-returns/{return}/dispute', [CustomerOrderReturnController::class, 'dispute'])->middleware('throttle:10,1');
    Route::post('/customer/order-returns/upload', [CustomerOrderReturnController::class, 'upload']);
    Route::get('/customer/money-wallet', [CustomerMoneyWalletController::class, 'wallet']);
    Route::get('/customer/money-wallet/ledger', [CustomerMoneyWalletController::class, 'ledger']);
    Route::post('/customer/money-wallet/withdrawals', [CustomerMoneyWalletController::class, 'withdraw'])->middleware('throttle:5,1');
    Route::get('/prize-history', [ClientTicketController::class, 'prizeHistory']); // Prize history API
});
Route::get('/leaderboard', [ClientTicketController::class, 'leaderboard']);
Route::get('/tickets', [ClientTicketController::class, 'indexTickets']);
Route::get('/tickets/ledger', [ClientTicketController::class, 'ticketLedger']);
Route::get('/tickets/config', [ClientTicketController::class, 'ticketPricingConfig']);
Route::post('/tickets/purchase', [ClientTicketController::class, 'purchaseTickets']);
Route::middleware('auth:sanctum')->post('/tickets/gift', [ClientTicketController::class, 'giftTickets']);
Route::middleware('auth:sanctum')->post('/draw-rooms/{roomId}/join', [ClientTicketController::class, 'joinDrawRoom']);

Route::middleware('auth:sanctum')->post('/draw-rooms/{roomId}/withdraw', [ClientTicketController::class, 'withdrawDrawRoom']);
// Affiliate
Route::middleware(['auth:sanctum', 'throttle:20,1'])->group(function () {
    Route::get('/commission/history', [ClientController::class, 'commissionHistory']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/commission/dashboard', [ClientController::class, 'commissionDashboard']);
        Route::get('/commission/profile', [ClientController::class, 'commissionProfile']);
        Route::get('/commission/referrals', [ClientController::class, 'commissionReferrals']);
        Route::get('/commission/referral-orders', [ClientController::class, 'commissionReferralOrders']);
        Route::get('/commission/dashboard/chart', [ClientController::class, 'commissionDailyChart']);
        Route::get('/commission/payouts', [ClientController::class, 'commissionPayouts']);
        Route::get('/commission/payouts/{batchId}', [ClientController::class, 'commissionPayoutDetail']);
    });

    // Affiliate trọn đời - Nhóm API cho Người chia sẻ (User/Affiliate)
    Route::post('/affiliate/uploads', [ClientAffiliateController::class, 'upload']);
    Route::get('/affiliate/id-number/check', [ClientAffiliateController::class, 'checkIdNumber']);
    Route::post('/affiliate/register', [ClientAffiliateController::class, 'register']);
    Route::get('/affiliate/my-status', [ClientAffiliateController::class, 'myStatus']);
    Route::post('/verification/submit', [ClientVerificationUser::class, 'submitVerification']);
    Route::get('/verification/status', [ClientVerificationUser::class, 'status']);
    Route::get('/status', [ClientVerificationUser::class, 'status']);
    Route::get('/affiliate/links', [ClientAffiliateController::class, 'links']); // Lấy danh sách link affiliate đã tạo
    Route::get('/affiliate/downline', [ClientAffiliateController::class, 'downline']); // Lấy danh sách downline (người được giới thiệu) và thông tin liên quan
    Route::get('/affiliate/withdrawal-status', [ClientAffiliateController::class, 'withdrawalStatus']); // Trạng thái phê duyệt rút tiền affiliate
    Route::post('/affiliate/withdrawal-request', [ClientAffiliateController::class, 'submitWithdrawalRequest'])->name('affiliate.withdrawal-request'); // Gửi hồ sơ rút tiền affiliate để admin duyệt
    Route::delete('/affiliate/downline/{userId}', [ClientAffiliateController::class, 'deleteDownlineMember']); // Xóa một thành viên trong downline
    Route::post('/affiliate/report-uplline', [ClientAffiliateController::class, 'reportUpline']); // Báo cáo upline (người giới thiệu) vi phạm để được xem xét khóa hoa hồng

    Route::resource('banks', BankController::class)->only(['index']);
    Route::post('/banks/lookup', [BankController::class, 'lookup']);

    Route::get('/wallet/bank-accounts', [ClientWalletController::class, 'bankAccounts']);
    Route::post('/wallet/bank-account', [ClientWalletController::class, 'createOrUpdate']);
    Route::post('/wallet/affiliate-withdraw', [ClientWalletController::class, 'affiliateWithdraw']);
    Route::get('/wallet/logs', [ClientWalletController::class, 'walletLogs']);
    Route::get('/wallet/withdraw-requests', [ClientWalletController::class, 'withdrawRequests']);
});

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::get('/store-business-types', [StoreBusinessTypeController::class, 'adminIndex']);
    Route::get('/order-returns', [AdminOrderReturnController::class, 'index']);
    Route::post('/order-returns/{return}/resolve-dispute', [AdminOrderReturnController::class, 'resolveDispute']);
    Route::get('/money-wallet/withdrawals', [AdminMoneyWalletController::class, 'withdrawals']);
    Route::post('/money-wallet/withdrawals/{withdrawal}/mark-paid', [AdminMoneyWalletController::class, 'markWithdrawalPaid'])->middleware('throttle:20,1');
    Route::post('/money-wallet/withdrawals/{withdrawal}/cancel', [AdminMoneyWalletController::class, 'cancelWithdrawal'])->middleware('throttle:20,1');
    Route::get('/store-wallet/withdrawals', [AdminStoreWalletWithdrawalController::class, 'withdrawals']);
    Route::post('/store-wallet/withdrawals/{withdrawal}/mark-paid', [AdminStoreWalletWithdrawalController::class, 'markPaid'])->middleware('throttle:20,1');
    Route::post('/store-wallet/withdrawals/{withdrawal}/reject', [AdminStoreWalletWithdrawalController::class, 'reject'])->middleware('throttle:20,1');
    Route::post('/store-business-types', [StoreBusinessTypeController::class, 'store']);
    Route::put('/store-business-types/{storeBusinessType}', [StoreBusinessTypeController::class, 'update']);
    Route::delete('/store-business-types/{storeBusinessType}', [StoreBusinessTypeController::class, 'destroy']);
    Route::get('/affiliate/reports', [AdminAffiliateController::class, 'reports']);
    Route::patch('/affiliate/change-upline', [AdminAffiliateController::class, 'changeUpline']);
    Route::get('/affiliates', [AdminAffiliateProfileController::class, 'index']);
    Route::get('/affiliates/requests', [AdminAffiliateProfileController::class, 'requests']);
    Route::get('/affiliates/{id}', [AdminAffiliateProfileController::class, 'show']);
    Route::get('/affiliates/{id}/customers', [AdminAffiliateCustomerController::class, 'index']);
    Route::get('/affiliates/{id}/customers/summary', [AdminAffiliateCustomerController::class, 'summary']);
    Route::get('/affiliates/{affiliateId}/customers/{customerId}', [AdminAffiliateCustomerController::class, 'show']);
    Route::post('/affiliates/{affiliateId}/customers/{customerId}/transfer', [AdminAffiliateCustomerController::class, 'transfer']);
    Route::post('/affiliates/{affiliateId}/customers/{customerId}/remove', [AdminAffiliateCustomerController::class, 'remove']);
    Route::post('/affiliates/{affiliateId}/customers/{customerId}/lock', [AdminAffiliateCustomerController::class, 'lock']);
    Route::post('/affiliates/{affiliateId}/customers/{customerId}/unlock', [AdminAffiliateCustomerController::class, 'unlock']);
    Route::get('/affiliate/commissions', [AdminAffiliateCommissionController::class, 'index']);
    Route::get('/affiliate/commissions/{commissionId}', [AdminAffiliateCommissionController::class, 'show']);
    Route::post('/affiliate/commissions/{commissionId}/approve', [AdminAffiliateCommissionController::class, 'approve']);
    Route::post('/affiliate/commissions/{commissionId}/reject', [AdminAffiliateCommissionController::class, 'reject']);
    Route::post('/affiliate/commissions/{commissionId}/hold', [AdminAffiliateCommissionController::class, 'hold']);
    Route::post('/affiliate/commissions/{commissionId}/release', [AdminAffiliateCommissionController::class, 'release']);
    Route::post('/affiliate/commissions/{commissionId}/cancel', [AdminAffiliateCommissionController::class, 'cancel']);
    Route::post('/affiliate/commissions/{commissionId}/clawback', [AdminAffiliateCommissionController::class, 'clawback']);
    Route::get('/affiliate/payout-batches', [AdminAffiliatePayoutBatchController::class, 'index']);
    Route::post('/affiliate/payout-batches', [AdminAffiliatePayoutBatchController::class, 'store']);
    Route::get('/affiliate/payout-batches/{batchId}', [AdminAffiliatePayoutBatchController::class, 'show']);
    Route::post('/affiliate/payout-batches/{batchId}/submit', [AdminAffiliatePayoutBatchController::class, 'submit']);
    Route::post('/affiliate/payout-batches/{batchId}/approve', [AdminAffiliatePayoutBatchController::class, 'approve']);
    Route::post('/affiliate/payout-batches/{batchId}/mark-paid', [AdminAffiliatePayoutBatchController::class, 'markPaid']);
    Route::post('/affiliate/payout-batches/{batchId}/cancel', [AdminAffiliatePayoutBatchController::class, 'cancel']);
    Route::post('/affiliates/{id}/start-review', [AdminAffiliateProfileController::class, 'startReview']);
    Route::post('/affiliates/{id}/request-more-info', [AdminAffiliateProfileController::class, 'requestMoreInfo']);
    Route::post('/affiliates/{id}/approve', [AdminAffiliateProfileController::class, 'approve']);
    Route::post('/affiliates/{id}/reject', [AdminAffiliateProfileController::class, 'reject']);
    Route::post('/affiliates/{id}/suspend', [AdminAffiliateProfileController::class, 'suspend']);
    Route::post('/affiliates/{id}/lock', [AdminAffiliateProfileController::class, 'lock']);
    Route::post('/affiliates/{id}/unlock', [AdminAffiliateProfileController::class, 'unlock']);
    Route::post('/affiliates/{id}/terminate', [AdminAffiliateProfileController::class, 'terminate']);
    Route::patch('/affiliates/{id}/approve', [AdminAffiliateProfileController::class, 'approve']);
});

Route::get('/draw-rooms/today', [ClientTicketController::class, 'todayDrawRooms'])->withoutMiddleware('throttle:api');
Route::get('/draw-rooms/stream', [ClientTicketController::class, 'streamTodayDrawRooms'])->withoutMiddleware('throttle:api');
Route::get('/rooms', [ClientTicketController::class, 'rooms']);
Route::get('/rooms/{roomId}', [ClientTicketController::class, 'roomDetail']);
Route::get('/stations', [ClientCatalogController::class, 'stations']);
Route::get('/customer/draws/configuration', [CustomerDrawController::class, 'getConfig']);
Route::get('/recommended-products', [ClientCatalogController::class, 'recommended']);
Route::get('/stores/all-with-products', [ClientCatalogController::class, 'getAllStoresWithProducts']);
Route::get('/stores/{storeId}/menu', [ClientCatalogController::class, 'storeMenu']);
Route::get('/stores/{storeId}', [ClientCatalogController::class, 'storeDetail']);
Route::get('/stores/{storeId}/detail', [ClientCatalogController::class, 'storeDetail']);
Route::get('/vouchers', [VoucherApiController::class, 'index']);
Route::get('/vouchers/{code}', [VoucherApiController::class, 'show']);
Route::post('/spin', [RewardApiController::class, 'spin']);
Route::get('/rewards', [RewardApiController::class, 'getRewards']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);
});
Route::get('/image-proxy', [ClientCatalogController::class, 'imageProxy']);
Route::post('/zalo/user-info', [ClientUserController::class, 'getZaloUserInfo']);
Route::post('/cart/refresh', [ClientCatalogController::class, 'refreshCart'])->name('refreshCart');
Route::middleware('auth:sanctum')->get('/cart', [ClientCartController::class, 'index']);
Route::middleware('auth:sanctum')->post('/cart/sync', [ClientCartController::class, 'sync']);
Route::middleware('auth:sanctum')->delete('/cart', [ClientCartController::class, 'clear']);
Route::post('/payment/vietqr/create', [ClientPaymentController::class, 'createVietQr']);
Route::get('/payment/vietqr/status/{orderId}', [ClientPaymentController::class, 'paymentStatus']);
Route::post('/payment/webhook', [ClientPaymentController::class, 'sepayWebhook']);
Route::get('/payment/pre-order/status/{code}', [ClientPaymentController::class, 'preOrderStatus']);
Route::post('/payment/bank-transfer/notify', [ClientPaymentController::class, 'bankTransferNotify']);
Route::post('/payment/update-method', [ClientPaymentController::class, 'updatePaymentMethod']);
Route::get('/complaints/my', [ClientComplaintController::class, 'my'])->middleware('auth:sanctum');
Route::get('/complaints/{id}/messages', [ClientComplaintController::class, 'messages'])->middleware('auth:sanctum');
Route::post('/complaints/{id}/messages', [ClientComplaintController::class, 'storeMessage'])->middleware('auth:sanctum');
Route::put('/store-admin/uploads/presigned-put/{token}', [StoreAdminUploadController::class, 'presignedPut'])
    ->middleware('signed')
    ->name('store-admin.uploads.presigned-put');

Route::post('/store-admin/forgot-password', [StoreAdminPasswordResetController::class, 'forgotPassword']); // gui email reset password
Route::post('/store-admin/reset-password', [StoreAdminPasswordResetController::class, 'resetPassword']); // dat lai mat khau sau khi nhan duoc email

Route::middleware('auth:sanctum')->get('/store-registration/status', [StoreAdminRegistrationController::class, 'getRegistrationStatus']);

Route::middleware('auth:sanctum')->get('/my-stores', [MyStoreController::class, 'index']);
Route::middleware('auth:sanctum')->get('/my-stores/{id}', [MyStoreController::class, 'show']);
Route::middleware(['auth:sanctum', 'account.verification.approved'])->put('/my-stores/{id}', [MyStoreController::class, 'update']);
Route::middleware('auth:sanctum')->delete('/my-stores/{id}', [MyStoreController::class, 'destroy']);
Route::middleware(['auth:sanctum', 'account.verification.approved'])->post('/my-stores', [MyStoreController::class, 'store']);

Route::prefix('store-admin')->middleware(['auth:sanctum', 'account.verification.approved'])->group(function () {
    Route::post('/register', [StoreAdminRegistrationController::class, 'register']);
    Route::post('/stores/{storeId}/approve', [StoreAdminRegistrationController::class, 'approve']);
    Route::post('/stores/{storeId}/reject', [StoreAdminRegistrationController::class, 'reject']);
    Route::get('/', [StoreAdminAuthController::class, 'entry']);

    Route::get('/me', [StoreAdminAuthController::class, 'me']);
    Route::get('/bootstrap', [StoreAdminAuthController::class, 'bootstrap']);
    Route::get('/users/search', [StoreAdminStaffController::class, 'searchUsers'])->middleware('store.permission:CREATE_EMPLOYEE');
    Route::get('/dashboard/overview', [StoreAdminOverviewController::class, 'dashboard'])->middleware('store.permission:VIEW_DASHBOARD');
    Route::get('/reports/summary', [StoreAdminDashboardController::class, 'reportsSummary'])->middleware('store.permission:VIEW_DASHBOARD');
    Route::get('/reports/revenue', [StoreAdminReportController::class, 'revenue'])->middleware('store.permission:VIEW_REPORT');
    Route::get('/reports/ticket-redemptions', [StoreAdminReportController::class, 'ticketRedemptions'])->middleware('store.permission:VIEW_REPORT');
    Route::get('/reports/ticket-consumption', [StoreAdminReportController::class, 'ticketConsumption'])->middleware('store.permission:VIEW_TICKET_REPORT');
    Route::post('/reports/exports', [StoreAdminReportController::class, 'createExport'])->middleware('store.permission:EXPORT_REPORT');
    Route::get('/reports/exports/{job_id}/download', [StoreAdminReportController::class, 'downloadExport'])->middleware('store.permission:EXPORT_REPORT');
    Route::get('/reports/exports/{job_id}', [StoreAdminReportController::class, 'exportStatus'])->middleware('store.permission:EXPORT_REPORT');

    Route::get('/customers', [StoreAdminCustomerController::class, 'index'])->middleware('store.permission:VIEW_CUSTOMER');

    Route::get('/order-returns', [StoreAdminOrderReturnController::class, 'index'])->middleware('store.permission:VIEW_RETURN');
    Route::post('/order-returns/upload', [StoreAdminOrderReturnController::class, 'upload'])->middleware('store.permission:PROCESS_RETURN');
    Route::post('/order-returns/{return}/approve', [StoreAdminOrderReturnController::class, 'approve'])->middleware('store.permission:PROCESS_RETURN');
    Route::post('/order-returns/{return}/reject', [StoreAdminOrderReturnController::class, 'reject'])->middleware('store.permission:PROCESS_RETURN');
    Route::post('/order-returns/{return}/receive', [StoreAdminOrderReturnController::class, 'receive'])->middleware('store.permission:PROCESS_RETURN');

    Route::post('/orders/exports', [StoreAdminReportController::class, 'createOrderExport'])->middleware('store.permission:EXPORT_ORDER');
    Route::get('/orders/exports/{job_id}/download', [StoreAdminReportController::class, 'downloadExport'])->middleware('store.permission:EXPORT_ORDER');
    Route::get('/orders/exports/{job_id}', [StoreAdminReportController::class, 'exportStatus'])->middleware('store.permission:EXPORT_ORDER');
    Route::post('/orders/import', [StoreAdminOrderImportController::class, 'import'])->middleware('store.permission:IMPORT_ORDER');

    Route::get('/finance/summary', [StoreAdminFinanceController::class, 'summary'])->middleware('store.permission:VIEW_RECONCILIATION');
    Route::get('/finance/affiliate-overview', [StoreAdminFinanceController::class, 'affiliateOverview'])->middleware('store.permission:VIEW_RECONCILIATION');
    Route::post('/finance/confirm', [StoreAdminFinanceController::class, 'confirm'])->middleware('store.permission:CONFIRM_RECONCILIATION');
    Route::get('/finance/status', [StoreAdminFinanceController::class, 'getStatus'])->middleware('store.permission:VIEW_RECONCILIATION');
    Route::post('/finance/mark-paid', [StoreAdminFinanceController::class, 'markPaid'])->middleware('store.permission:CONFIRM_RECONCILIATION');
    Route::post('/finance/create-new', [StoreAdminFinanceController::class, 'createNew'])->middleware('store.permission:CONFIRM_RECONCILIATION');
    Route::get('/finance/reconciliations', [StoreAdminFinanceController::class, 'reconciliations'])->middleware('store.permission:VIEW_RECONCILIATION');
    Route::get('/finance/wallet-logs', [StoreAdminFinanceController::class, 'walletLogs'])->middleware('store.permission:VIEW_WALLET');
    Route::post('/finance/wallet/deposit', [StoreAdminFinanceController::class, 'createWalletDepositPreOrder'])->middleware('store.permission:VIEW_WALLET');
    Route::post('/finance/wallet/withdraw', [StoreAdminFinanceController::class, 'createWithdrawal'])->middleware('store.permission:WITHDRAW_WALLET');


    Route::get('/permissions', [StoreAdminStaffController::class, 'permissions']);
    Route::post('/staff', [StoreAdminStaffController::class, 'createStaff'])->middleware('store.permission:CREATE_EMPLOYEE');
    Route::post('/staff/assign', [StoreAdminStaffController::class, 'assignStaff'])->middleware('store.permission:CREATE_EMPLOYEE');
    Route::get('/staff', [StoreAdminStaffController::class, 'staffList'])->middleware('store.permission:VIEW_EMPLOYEE');
    Route::put('/staff/{id}/permissions', [StoreAdminStaffController::class, 'updateStaffPermissions'])->middleware('store.permission:ASSIGN_EMPLOYEE_PERMISSION');
    Route::delete('/staff/{id}', [StoreAdminStaffController::class, 'deleteStaff'])->middleware('store.permission:ASSIGN_EMPLOYEE_PERMISSION');
    Route::post('/staff/{id}/delete', [StoreAdminStaffController::class, 'deleteStaff'])->middleware('store.permission:ASSIGN_EMPLOYEE_PERMISSION');

    Route::post('/uploads/presign', [StoreAdminUploadController::class, 'presign']);
    Route::get('/products/categories/tree', [StoreAdminProductCategoryController::class, 'tree'])->middleware('store.permission:VIEW_MENU'); // Lay cay danh muc san pham.
    Route::patch('/products/categories/{id}', [StoreAdminProductCategoryController::class, 'update'])->middleware('store.permission:EDIT_MENU');
    Route::post('/products/categories', [StoreAdminProductCategoryController::class, 'store'])->middleware('store.permission:EDIT_MENU');
    Route::post('/products/drafts', [StoreAdminProductDraftController::class, 'store'])->middleware('store.permission:CREATE_PRODUCT'); // Tao ban nhap san pham moi tu wizard.
    Route::get('/products/drafts', [StoreAdminProductDraftController::class, 'index'])->middleware('store.permission:VIEW_MENU'); // Lay danh sach ban nhap cua cua hang hien tai (co phan trang).
    Route::get('/products/drafts/{id}', [StoreAdminProductDraftController::class, 'show'])->middleware('store.permission:VIEW_MENU'); // Lay chi tiet 1 ban nhap de mo lai wizard.
    Route::patch('/products/drafts/{id}', [StoreAdminProductDraftController::class, 'update'])->middleware('store.permission:EDIT_MENU'); // Cap nhat mot phan/noi dung ban nhap theo buoc.
    Route::delete('/products/drafts/{id}', [StoreAdminProductDraftController::class, 'destroy'])->middleware('store.permission:DELETE_PRODUCT'); // Xoa ban nhap khoi danh sach.
    Route::put('/products/drafts/{draft}/variant-config', [StoreAdminProductDraftVariantConfigController::class, 'upsert'])->middleware('store.permission:EDIT_MENU'); // Luu cau hinh phan loai va sinh ma tran bien the.
    Route::get('/products/drafts/{draft}/variant-config', [StoreAdminProductDraftVariantConfigController::class, 'show'])->middleware('store.permission:VIEW_MENU'); // Lay cau hinh phan loai va ma tran bien the.
    Route::post('/products/validate', [StoreAdminProductValidationController::class, 'validateDraft'])->middleware('store.permission:EDIT_MENU'); // Validate du lieu wizard truoc khi publish.
    Route::get('/tickets/config', [StoreAdminProductController::class, 'ticketPricingConfig'])->middleware('store.permission:VIEW_MENU'); // Lay cau hinh quy doi ve tu he thong.
    Route::get('/tickets/types', [TicketConfigController::class, 'getAvailableTicketTypes'])->middleware('store.permission:VIEW_MENU'); // Lay danh sach loai ve dang active de hien thi dropdown wizard.
    Route::post('/tickets/preview', [StoreAdminProductController::class, 'ticketPricingPreview'])->middleware('store.permission:EDIT_MENU'); // Tinh preview chi phi ve theo gia san pham va so ve tang.
    Route::get('/products', [StoreAdminProductController::class, 'index'])->middleware('store.permission:VIEW_MENU'); // Lay danh sach san pham cua cua hang, co the loc theo trang thai.
    Route::get('/products/approval-status', [StoreAdminProductController::class, 'approvalStatusList'])->middleware('store.permission:VIEW_MENU'); // Lay trang thai phe duyet cua nhieu san pham.
    Route::get('/products/{id}/approval-status', [StoreAdminProductController::class, 'approvalStatus'])->middleware('store.permission:VIEW_MENU'); // Lay trang thai phe duyet cua san pham theo scope cua hang.
    Route::get('/products/{id}', [StoreAdminProductController::class, 'show'])->middleware('store.permission:VIEW_MENU'); // Lay chi tiet san pham da publish de sua.
    Route::patch('/products/{id}', [StoreAdminProductController::class, 'update'])->middleware('store.permission:EDIT_MENU'); // Cap nhat san pham da publish.
    Route::delete('/products/{id}', [StoreAdminProductController::class, 'destroy'])->middleware('store.permission:DELETE_PRODUCT'); // Xoa san pham theo scope cua hang.
    Route::post('/products', [StoreAdminProductController::class, 'store'])->middleware('store.permission:CREATE_PRODUCT'); // Publish/Tao san pham moi.
    Route::get('/toppings', [StoreAdminProductController::class, 'toppings'])->middleware('store.permission:VIEW_MENU');


    Route::get('/menu/overview', [StoreAdminOverviewController::class, 'menu'])->middleware('store.permission:VIEW_MENU');
    Route::get('/menu/pricing', [StoreAdminMenuController::class, 'pricing'])->middleware('store.permission:VIEW_MENU'); // Lay gia standard va premium dang ap dung cho menu.
    Route::patch('/menu/pricing', [StoreAdminMenuController::class, 'updatePricing'])->middleware('store.permission:EDIT_PRICE'); // Cap nhat 1-2 muc gia toan menu.
    Route::get('/menu/categories', [StoreAdminMenuController::class, 'categories'])->middleware('store.permission:VIEW_MENU'); // Lay danh muc mon an cho filter va editor.
    Route::delete('/menu/categories/{name}', [StoreAdminMenuController::class, 'deleteCategory'])->middleware('store.permission:EDIT_MENU');
    Route::get('/menu/items', [StoreAdminMenuController::class, 'items'])->middleware('store.permission:VIEW_MENU'); // Lay danh sach mon theo category, q, in_stock, is_lucky, paging.
    Route::get('/menu/items/{id}', [StoreAdminMenuController::class, 'itemDetail'])->middleware('store.permission:VIEW_MENU'); // Lay chi tiet 1 mon.
    Route::post('/menu/items', [StoreAdminMenuController::class, 'createItem'])->middleware('store.permission:EDIT_MENU'); // Them mon moi.
    Route::patch('/menu/items/{id}', [StoreAdminMenuController::class, 'updateItem'])->middleware('store.permission:EDIT_MENU'); // Chinh sua ten, gia, danh muc, mo ta, tier, image.
    Route::delete('/menu/items/{id}', [StoreAdminMenuController::class, 'deleteItem'])->middleware('store.permission:DELETE_PRODUCT'); // Xoa mon khoi danh sach.
    Route::patch('/menu/items/{id}/flags', [StoreAdminMenuController::class, 'updateItemFlags'])->middleware('store.permission:TOGGLE_PRODUCT_SALE'); // Bat tat is_lucky va in_stock.
    Route::post('/menu/items/{id}/image', [StoreAdminMenuController::class, 'updateItemImage'])->middleware('store.permission:EDIT_MENU'); // Doi anh cua mon.

    Route::get('/flash-sales', [StoreAdminFlashSaleController::class, 'index'])->middleware('store.permission:VIEW_FLASH_SALE');
    Route::post('/flash-sales', [StoreAdminFlashSaleController::class, 'store'])->middleware('store.permission:CREATE_FLASH_SALE');
    Route::get('/flash-sales/products', [StoreAdminProductController::class, 'flashSaleProducts'])->middleware('store.permission:VIEW_FLASH_SALE');
    Route::get('/flash-sales/{id}', [StoreAdminFlashSaleController::class, 'show'])->middleware('store.permission:VIEW_FLASH_SALE')->whereUuid('id');
    Route::patch('/flash-sales/{id}', [StoreAdminFlashSaleController::class, 'update'])->middleware('store.permission:UPDATE_FLASH_SALE')->whereUuid('id');
    Route::delete('/flash-sales/{id}', [StoreAdminFlashSaleController::class, 'destroy'])->middleware('store.permission:DELETE_FLASH_SALE')->whereUuid('id');

    Route::get('/orders/overview', [StoreAdminOverviewController::class, 'orders'])->middleware('store.permission:VIEW_ORDER');
    Route::get('/orders', [StoreAdminOrderController::class, 'index'])->middleware('store.permission:VIEW_ORDER'); // Lay danh sach don theo status, q, from, to, paging.
    Route::get('/orders/counts', [StoreAdminOrderController::class, 'counts'])->middleware('store.permission:VIEW_ORDER'); // Lay so luong don theo tung trang thai de render tab va badge.
    Route::get('/orders/rejection-reasons', [StoreAdminOrderController::class, 'rejectionReasons'])->middleware('store.permission:VIEW_ORDER'); // Lay danh sach ly do tu choi mac dinh.
    Route::get('/orders/{id}/cancellation-details', [StoreAdminOrderController::class, 'cancellationDetails'])->middleware('store.permission:VIEW_ORDER'); // Lay thong tin ly do huy don de hien thi bao cao.
    Route::get('/orders/{id}/refund-details', [StoreAdminOrderController::class, 'refundDetails'])->middleware('store.permission:VIEW_ORDER'); // Lay thong tin hoan ve cua don hang.
    Route::get('/orders/{id}/invoice', [StoreAdminOrderController::class, 'invoice'])->middleware('store.permission:VIEW_ORDER'); // Lay du lieu hoa don cua don hang.
    Route::get('/orders/{id}', [StoreAdminOrderController::class, 'show'])->middleware('store.permission:VIEW_ORDER'); // Lay chi tiet don: customer, ticket code, items, pricing, dispute, timeline, internal note.
    Route::post('/orders/{id}/confirm', [StoreAdminOrderController::class, 'confirm'])->middleware('store.permission:ACCEPT_ORDER'); // Xac nhan don: pending -> confirmed.
    Route::post('/orders/{id}/reject', [StoreAdminOrderController::class, 'reject'])->middleware('store.permission:CANCEL_ORDER'); // Huy don pending|confirmed|preparing, luu ly do, hoan ve va reverse vi store neu da nhan don.
    Route::post('/orders/{id}/cancel-with-refund', [StoreAdminOrderController::class, 'cancelWithRefund'])->middleware('store.permission:CANCEL_ORDER'); // Huy don va dong bo vi store cho pending|confirmed|preparing.
    Route::post('/orders/{id}/mark-ready', [StoreAdminOrderController::class, 'markReady'])->middleware('store.permission:ACCEPT_ORDER'); // Chuyen don preparing -> ready.
    Route::post('/orders/{id}/mark-picked-up', [StoreAdminOrderController::class, 'markPickedUp'])->middleware('store.permission:ACCEPT_ORDER'); // Chuyen don ready -> picked_up.
    Route::post('/orders/{id}/mark-delivered', [StoreAdminOrderController::class, 'markDelivered'])->middleware('store.permission:ACCEPT_ORDER'); // Chuyen don picked_up -> delivered.
    Route::post('/orders/{id}/mark-returned', [StoreAdminOrderController::class, 'markReturned'])->middleware('store.permission:CANCEL_ORDER'); // Chuyen don delivered -> returned.
    Route::post('/orders/{id}/refund-ticket', [StoreAdminOrderController::class, 'refundTicket'])->middleware('store.permission:CANCEL_ORDER'); // Hoan ve khi cua hang tu choi don hoac loi van hanh.
    Route::put('/orders/{id}/cancel', [StoreAdminOrderController::class, 'cancelOrder'])->middleware('store.permission:CANCEL_ORDER'); // Huy don pending|confirmed|preparing, luu ly do, hoan ve va reverse vi store neu da nhan don.
    Route::post('/orders/{id}/resend', [StoreAdminOrderController::class, 'resend'])->middleware('store.permission:ACCEPT_ORDER');
    Route::put('/orders/{id}/rejection-details', [StoreAdminOrderController::class, 'rejectionDetails'])->middleware('store.permission:CANCEL_ORDER'); // Alias cho luong cap nhat ly do huy don.

    Route::get('/notifications', [StoreAdminNotificationController::class, 'index'])->middleware('store.permission:VIEW_NOTIFICATION'); // Lay danh sach thong bao cua cua hang.
    Route::patch('/notifications/{id}/read', [StoreAdminNotificationController::class, 'markRead'])->middleware('store.permission:MARK_NOTIFICATION_READ'); // Danh dau da doc mot thong bao.
    Route::patch('/notifications/read-all', [StoreAdminNotificationController::class, 'markAllRead'])->middleware('store.permission:MARK_ALL_NOTIFICATION_READ'); // Danh dau da doc toan bo thong bao.

    Route::get('/complaints', [StoreAdminComplaintController::class, 'index'])->middleware('store.permission:VIEW_COMPLAINT'); // Lay danh sach khieu nai lien quan cua hang.
    Route::get('/complaints/{id}', [StoreAdminComplaintController::class, 'show'])->middleware('store.permission:VIEW_COMPLAINT'); // Lay chi tiet mot khieu nai cua cua hang.
    Route::patch('/complaints/{id}/read', [StoreAdminComplaintController::class, 'markRead'])->middleware('store.permission:MARK_COMPLAINT_READ'); // Danh dau tin nhan khieu nai cua khach la da doc.
    Route::patch('/complaints/{id}/status', [StoreAdminComplaintController::class, 'updateStatus'])->middleware('store.permission:UPDATE_COMPLAINT'); // Cap nhat trang thai khieu nai.
    Route::post('/complaints/{id}/messages', [StoreAdminComplaintController::class, 'storeMessage'])->middleware('store.permission:REPLY_COMPLAINT'); // Gui phan hoi trong hoi thoai khieu nai.
    Route::post('/complaints/{id}/request-ticket-refund', [StoreAdminComplaintController::class, 'requestTicketRefund'])->middleware('store.permission:UPDATE_COMPLAINT'); // Gui de xuat hoan ve len he thong.
    Route::post('/complaints/{id}/accept-return', [StoreAdminComplaintController::class, 'acceptReturn'])->middleware('store.permission:UPDATE_COMPLAINT');
    Route::get('/reviews/overview', [StoreAdminOverviewController::class, 'reviews'])->middleware('store.permission:VIEW_REVIEW');
    Route::get('/reviews', [StoreAdminReviewController::class, 'index'])->middleware('store.permission:VIEW_REVIEW'); // Lay danh sach danh gia san pham cua cua hang.
    Route::get('/reviews/summary-by-menu', [StoreAdminReviewController::class, 'summaryByMenu'])->middleware('store.permission:VIEW_REVIEW'); // Tong hop danh gia theo mon.
    Route::get('/menu/{menuItemId}/reviews', [StoreAdminReviewController::class, 'menuItemReviews'])->middleware('store.permission:VIEW_REVIEW_DETAIL'); // Chi tiet review theo mon.
    Route::post('/reviews/{reviewId}/replies', [StoreAdminReviewController::class, 'reply'])->middleware('store.permission:REPLY_REVIEW'); // Admin tra loi danh gia.
    Route::delete('/reviews/{reviewId}', [StoreAdminReviewController::class, 'destroy'])->middleware('store.permission:DELETE_REVIEW'); // Xoa vinh vien danh gia.

    Route::get('/tickets', [StoreAdminTicketController::class, 'index'])->middleware('store.permission:VIEW_TICKET_REPORT'); // Lay danh sach ve da duoc dung de goi mon tai cua hang
    Route::get('/tickets/{id}', [StoreAdminTicketController::class, 'show'])->middleware('store.permission:VIEW_TICKET_REPORT'); // Lay chi tiet mot ve da su dung

    Route::get('/settings', [StoreAdminSettingsController::class, 'index'])->middleware('store.permission:VIEW_SETTINGS');
    Route::patch('/settings', [StoreAdminSettingsController::class, 'update'])->middleware('store.permission:VIEW_SETTINGS');
    Route::get('/sla/overview', [StoreAdminOverviewController::class, 'sla'])->middleware('store.permission:VIEW_SETTINGS');
    Route::get('/sla/config', [StoreAdminSettingsController::class, 'slaConfig'])->middleware('store.permission:VIEW_SETTINGS'); // Lay cau hinh SLA dang ap dung cho cua hang de tinh thoi gian phuc vu va hien thi canh bao tre han.
    Route::patch('/sla/config', [StoreAdminSettingsController::class, 'updateSlaConfig'])->middleware('store.permission:UPDATE_SLA_CONFIG'); // Cap nhat SLA rieng cho cua hang.
    Route::get('/sla/timeouts', [StoreAdminSettingsController::class, 'slaTimeouts'])->middleware('store.permission:VIEW_ORDER'); // C25: Lich su don timeout cua cua hang.
    Route::get('/settings/operating-panel', [StoreAdminSettingsController::class, 'operatingPanel'])->middleware('store.permission:VIEW_STORE');
    Route::patch('/settings/operating-panel/state', [StoreAdminSettingsController::class, 'updateOperatingPanelState'])->middleware('store.permission:UPDATE_OPERATING_STATE');
    Route::get('/settings/operating-panel/history', [StoreAdminSettingsController::class, 'operatingPanelHistory'])->middleware('store.permission:VIEW_STORE');
    Route::get('/profile/overview', [StoreAdminOverviewController::class, 'profile'])->middleware('store.permission:VIEW_STORE');
    Route::get('/profile', [StoreAdminAuthController::class, 'profile'])->middleware('store.permission:VIEW_STORE');

    Route::get('/licenses', [StoreAdminLicenseController::class, 'index'])->middleware('store.permission:VIEW_STORE');
    Route::post('/licenses', [StoreAdminLicenseController::class, 'store'])->middleware('store.permission:MANAGE_STORE_LICENSE');
    Route::patch('/licenses/{id}', [StoreAdminLicenseController::class, 'update'])->middleware('store.permission:MANAGE_STORE_LICENSE');
    Route::delete('/licenses/{id}', [StoreAdminLicenseController::class, 'destroy'])->middleware('store.permission:MANAGE_STORE_LICENSE');

    Route::get('/profile/status', [StoreAdminAuthController::class, 'profileStatus'])->middleware('store.permission:VIEW_STORE');
    Route::get('/profile/approval-history', [StoreAdminAuthController::class, 'approvalHistory'])->middleware('store.permission:VIEW_STORE');
    Route::patch('/profile', [StoreAdminAuthController::class, 'updateProfile'])->middleware('store.permission:EDIT_PROFILE');
    Route::get('/profile/operating-schedule', [StoreAdminAuthController::class, 'getOperatingSchedule'])->middleware('store.permission:VIEW_STORE');
    Route::patch('/profile/operating-schedule', [StoreAdminAuthController::class, 'updateOperatingSchedule'])->middleware('store.permission:UPDATE_OPERATING_SCHEDULE');
    Route::post('/profile/image', [StoreAdminAuthController::class, 'uploadProfileImage'])->middleware('store.permission:EDIT_PROFILE');
    Route::patch('/operating-state', [StoreAdminAuthController::class, 'updateOperatingState'])->middleware('store.permission:UPDATE_OPERATING_STATE');
});

Route::prefix('rbac')->group(function () {
    Route::post('/login', [RbacAuthController::class, 'login']);
    Route::post('/assign-role', [RbacAuthController::class, 'assignRole']);

    Route::get('/store/{store_id}/check', [RbacAuthController::class, 'checkStoreContext'])
        ->middleware(['rbac.jwt', 'rbac.role:admin_store,store,store_id']);
});
