<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\appSetting;
use App\Models\article;
use App\Models\banner;
use App\Models\Product;
use App\Models\User;
use App\Http\Requests\CommissionDailyChartRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\NewOrderPlaced;
use App\Events\CustomerCanceledOrderEvent;
use App\Events\OrderStatusUpdated;
use App\Models\BankAccount;
use App\Models\Commission;
use App\Models\CommissionUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ProductCategory;
use App\Models\ProductCombo;
use App\Models\ProductVariant;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomSchedule;
use App\Models\RoomTicket;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreAdminNotification;
use App\Models\StoreMenu;
use App\Models\Ticket;
use App\Models\TicketBatch;
use App\Models\UserBalance;
use App\Models\UserCartItem;
use App\Models\UserRoleBinding;
use App\Models\Voucher;
use App\Models\WithdrawRequest;
use App\Services\FlashSaleService;
use App\Services\OrderSlaService;
use App\Services\OrderStockRestorationService;
use App\Services\ProductReturnEligibilityService;
use App\Services\StoreAdmin\StorePermissionCatalog;
use App\Services\TicketLedgerService;
use App\Services\TicketRewardService;
use BcMath\Number;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ClientController extends Controller
{
    protected function resolveApiUser(Request $request): ?User
    {
        $requestUser = $request->user();
        if ($requestUser instanceof User) {
            return $requestUser->isActiveForAuth() ? $requestUser : null;
        }

        $authHeader = $request->header('Authorization');

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && $accessToken->tokenable instanceof User) {
                return $accessToken->tokenable->isActiveForAuth() ? $accessToken->tokenable : null;
            }
        }

        return null;
    }

    protected function resolveCommissionUser(Request $request): ?User
    {
        return $request->user() ?: $this->resolveApiUser($request);
    }

    protected function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $prefixLength = min(3, max(0, $length - 4));
        $suffix = substr($digits, -2);

        return substr($digits, 0, $prefixLength) . str_repeat('*', max(0, $length - $prefixLength - 2)) . $suffix;
    }

    protected function isPendingPaymentMethod(?string $paymentMethod): bool
    {
        $normalized = strtoupper(trim((string) $paymentMethod));

        // Only online / bank-based payment methods are considered pending payment.
        // CASH / COD orders are completed at checkout and should be treated as immediate.
        return in_array($normalized, ['BANK', 'BANK_TRANSFER', 'VIETQR', 'VNPAY'], true);
    }

    protected function applyApprovedProductScope($query)
    {
        if (Schema::hasColumn('products', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), Product::APPROVED_STATUSES);
        }

        return $query;
    }

    protected function productIsApprovedForPurchase(?Product $product): bool
    {
        if (! $product) {
            return false;
        }

        if (! Schema::hasColumn('products', 'status')) {
            return true;
        }

        return $product->hasApprovedStatus();
    }

    protected function variantIsEnabledForPurchase(?ProductVariant $variant): bool
    {
        if (! $variant) {
            return false;
        }

        if (! Schema::hasColumn('product_variants', 'enabled')) {
            return true;
        }

        return (bool) ($variant->enabled ?? false);
    }

    protected function storeIsDebtLocked(Store $store): bool
    {
        return Schema::hasColumn('stores', 'is_debt_locked')
            && (bool) ($store->is_debt_locked ?? false);
    }

    protected function storeHasPublicStatus(Store $store): bool
    {
        if (! Schema::hasColumn('stores', 'status')) {
            return true;
        }

        return $store->hasPublicStatus();
    }

    protected function storeIsPubliclyVisible(Store $store): bool
    {
        return $this->storeHasPublicStatus($store)
            && (bool) $store->is_open
            && ! $this->storeIsDebtLocked($store);
    }

    protected function applyPublicStoreVisibilityScope($query, string $storeAlias = 'stores')
    {
        if (Schema::hasColumn('stores', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE({$storeAlias}.status, ''))"), Store::PUBLIC_STATUSES);
        }

        if (Schema::hasColumn('stores', 'is_open')) {
            $query->where($storeAlias . '.is_open', true);
        }

        if (Schema::hasColumn('stores', 'is_debt_locked')) {
            $query->where(function ($visibilityQuery) use ($storeAlias) {
                $visibilityQuery
                    ->whereNull($storeAlias . '.is_debt_locked')
                    ->orWhere($storeAlias . '.is_debt_locked', false);
            });
        }

        return $query;
    }

    protected function applyPublicProductStoreVisibilityScope($query, string $productAlias = 'products')
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('products', 'store_id')) {
            return $query;
        }

        $query->whereExists(function ($storeQuery) use ($productAlias) {
            $storeQuery
                ->select(DB::raw(1))
                ->from('stores')
                ->whereColumn('stores.id', $productAlias . '.store_id');

            $this->applyPublicStoreVisibilityScope($storeQuery);
        });

        return $query;
    }

    protected function applyPurchasableMenuItemScope($query, string $menuAlias = 'store_menu')
    {
        if (Schema::hasTable('products') && Schema::hasColumn('store_menu', 'product_id')) {
            $query->where(function ($productQuery) use ($menuAlias) {
                $productQuery
                    ->whereNull($menuAlias . '.product_id')
                    ->orWhere($menuAlias . '.product_id', 0)
                    ->orWhereExists(function ($linkedProductQuery) use ($menuAlias) {
                        $linkedProductQuery
                            ->select(DB::raw(1))
                            ->from('products')
                            ->whereColumn('products.id', $menuAlias . '.product_id');

                        $this->applyApprovedProductScope($linkedProductQuery);
                        $this->applyPublicProductStoreVisibilityScope($linkedProductQuery);
                    });
            });
        }

        if (Schema::hasTable('product_variants')
            && Schema::hasColumn('store_menu', 'variant_id')
            && Schema::hasColumn('product_variants', 'enabled')) {
            $query->where(function ($variantQuery) use ($menuAlias) {
                $variantQuery
                    ->whereNull($menuAlias . '.variant_id')
                    ->orWhere($menuAlias . '.variant_id', 0)
                    ->orWhereExists(function ($linkedVariantQuery) use ($menuAlias) {
                        $linkedVariantQuery
                            ->select(DB::raw(1))
                            ->from('product_variants')
                            ->whereColumn('product_variants.id', $menuAlias . '.variant_id')
                            ->where('product_variants.enabled', true);

                        if (Schema::hasTable('products')) {
                            $linkedVariantQuery->whereExists(function ($variantProductQuery) {
                                $variantProductQuery
                                    ->select(DB::raw(1))
                                    ->from('products')
                                    ->whereColumn('products.id', 'product_variants.product_id');

                                $this->applyApprovedProductScope($variantProductQuery);
                                $this->applyPublicProductStoreVisibilityScope($variantProductQuery);
                            });
                        }
                    });
            });
        }

        return $query;
    }

    protected function resolveProductMenuAvailability(int $productId, ?string $storeId = null, ?int $variantId = null): ?bool
    {
        if ($productId <= 0
            || ! Schema::hasTable('store_menu')
            || ! Schema::hasColumn('store_menu', 'product_id')
            || ! Schema::hasColumn('store_menu', 'is_available')) {
            return null;
        }

        $query = DB::table('store_menu')
            ->where('product_id', $productId);

        if ($storeId !== null && $storeId !== '' && Schema::hasColumn('store_menu', 'store_id')) {
            $query->where('store_id', $storeId);
        }

        if ($variantId !== null && Schema::hasColumn('store_menu', 'variant_id')) {
            $query->where(function ($variantQuery) use ($variantId) {
                $variantQuery
                    ->whereNull('variant_id')
                    ->orWhere('variant_id', 0)
                    ->orWhere('variant_id', $variantId);
            });
        }

        $states = $query
            ->pluck('is_available')
            ->map(static fn ($value): bool => (bool) $value);

        if ($states->isEmpty()) {
            return null;
        }

        return $states->contains(true);
    }

    protected function assertProductMenuAvailable($product, ?int $variantId = null, string $field = 'cart'): void
    {
        $productId = (int) ($product->id ?? 0);
        $storeId = $product->store_id !== null ? (string) $product->store_id : null;
        $isAvailable = $this->resolveProductMenuAvailability($productId, $storeId, $variantId);

        if ($isAvailable === false) {
            $productName = trim((string) ($product->name ?? 'Sản phẩm'));

            throw ValidationException::withMessages([
                $field => ["Sản phẩm \"{$productName}\" đang đóng. Vui lòng chọn sản phẩm khác."],
            ]);
        }
    }

    protected function assertCartProductsMenuAvailable(array $cart, string $field = 'cart'): void
    {
        $productIds = collect($cart)
            ->map(static fn ($item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0)
            ->filter(static fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $productsById = Product::query()
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy('id');

        foreach ($cart as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = $productsById->get((int) ($item['id'] ?? 0));
            if (! $product) {
                continue;
            }

            $variantId = isset($item['variant_id']) && $item['variant_id'] !== ''
                ? (int) $item['variant_id']
                : null;

            $this->assertProductMenuAvailable($product, $variantId, $field);
        }
    }

    protected function resolveAffiliateRecipientId(?int $referrerId, ?int $currentUserId = null): ?int
    {
        if ($currentUserId) {
            $currentUser = User::query()->find($currentUserId);
            $storedReferrerId = (int) ($currentUser?->referrer_id ?? 0);

            if ($storedReferrerId > 0 && $storedReferrerId !== (int) $currentUserId) {
                return $storedReferrerId;
            }
        }

        if (! $referrerId) {
            return null;
        }

        $referrer = User::query()->find($referrerId);
        if (! $referrer) {
            return null;
        }

        $recipientId = (int) $referrer->id;
        if ($recipientId <= 0) {
            return null;
        }

        if ($currentUserId && $recipientId === (int) $currentUserId) {
            return null;
        }

        return $recipientId;
    }

    protected function calculateAffiliateSalesAmount(User $member): float
    {
        // Only count orders where payment was actually received.
        // For CASH/COD this happens when admin marks order delivered (delivered_at + status_payment = 'paid').
        // For online payments, `status_payment = 'paid'` or final statuses like 'delivered'/'completed' indicate payment.
        $query = Order::query()
            ->where('user_id', $member->id)
            ->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded'])
            ->where(function ($q) {
                $q->whereNotNull('delivered_at')
                  ->orWhereRaw("LOWER(COALESCE(status_payment, '')) = 'paid'")
                  ->orWhereRaw("LOWER(COALESCE(status, '')) IN ('delivered', 'completed')");
            });

        return (float) $query->sum(DB::raw('COALESCE(final_amount, total_amount, total, 0)'));
    }

    protected function calculateAffiliateCommissionAmount(User $member, bool $includePending = true): float
    {
        $query = CommissionUser::query()->where('user_id', $member->id);

        if ($includePending) {
            $query->whereIn('status', ['paid', 'pending']);
        } else {
            $query->where('status', 'paid');
        }

        return (float) $query->sum('amount');
    }

    protected function calculateAffiliateCommissionFromOrderHistory(User $member, bool $includePending = true): float
    {
        $query = CommissionUser::query()
            ->leftJoin('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.user_id', $member->id);

        if ($includePending) {
            $query->whereIn('commission_users.status', ['paid', 'pending']);
        } else {
            $query->where('commission_users.status', 'paid');
        }

        return (float) $query->sum('commission_users.amount');
    }

    /**
     * Batched version of calculateAffiliateSalesAmount().
     * Returns a map keyed by user id => total sales, computed in a single query.
     *
     * @param  array<int|string>  $userIds
     * @return array<int, float>
     */
    protected function calculateAffiliateSalesAmountForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $rows = Order::query()
            ->whereIn('user_id', $userIds)
            ->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded'])
            ->where(function ($q) {
                $q->whereNotNull('delivered_at')
                  ->orWhereRaw("LOWER(COALESCE(status_payment, '')) = 'paid'")
                  ->orWhereRaw("LOWER(COALESCE(status, '')) IN ('delivered', 'completed')");
            })
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(COALESCE(final_amount, total_amount, total, 0)) as aggregate_total')
            ->pluck('aggregate_total', 'user_id');

        return $this->normalizeAggregateMap($userIds, $rows);
    }

    /**
     * Batched version of calculateAffiliateCommissionAmount().
     *
     * @param  array<int|string>  $userIds
     * @return array<int, float>
     */
    protected function calculateAffiliateCommissionAmountForUsers(array $userIds, bool $includePending = true): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $query = CommissionUser::query()->whereIn('user_id', $userIds);

        if ($includePending) {
            $query->whereIn('status', ['paid', 'pending']);
        } else {
            $query->where('status', 'paid');
        }

        $rows = $query
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) as aggregate_total')
            ->pluck('aggregate_total', 'user_id');

        return $this->normalizeAggregateMap($userIds, $rows);
    }

    /**
     * Batched version of calculateAffiliateCommissionFromOrderHistory().
     *
     * @param  array<int|string>  $userIds
     * @return array<int, float>
     */
    protected function calculateAffiliateCommissionFromOrderHistoryForUsers(array $userIds, bool $includePending = true): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $query = CommissionUser::query()
            ->leftJoin('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.user_id', $userIds);

        if ($includePending) {
            $query->whereIn('commission_users.status', ['paid', 'pending']);
        } else {
            $query->where('commission_users.status', 'paid');
        }

        $rows = $query
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id as user_id, SUM(commission_users.amount) as aggregate_total')
            ->pluck('aggregate_total', 'user_id');

        return $this->normalizeAggregateMap($userIds, $rows);
    }

    /**
     * Normalizes a DB aggregate result into a map keyed by every requested user id (defaulting to 0.0).
     *
     * @param  array<int>  $userIds
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $rows
     * @return array<int, float>
     */
    private function normalizeAggregateMap(array $userIds, $rows): array
    {
        $map = [];
        foreach ($userIds as $id) {
            $map[(int) $id] = 0.0;
        }

        foreach ($rows as $userId => $sum) {
            $map[(int) $userId] = (float) $sum;
        }

        return $map;
    }

    /**
     * Validates if store is currently open based on is_open flag and operating hours.
     * Throws Exception if store is closed.
     *
     * @param Store $store
     * @throws \Exception
     */
    protected function validateStoreOperatingStatus(Store $store): void
    {
        $message = $this->getStoreOperatingStatusMessage($store);

        if ($message !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'store' => [$message],
            ]);
        }
    }

    protected function getStoreOperatingStatusMessage(Store $store): ?string
    {
        if (! $this->storeHasPublicStatus($store)) {
            return 'Cua hang hien khong kha dung.';
        }

        if ($this->storeIsDebtLocked($store)) {
            return 'Cua hang dang tam ngung nhan don do cong no.';
        }

        if (! $store->is_open) {
            return 'Cửa hàng hiện đang tạm nghỉ, vui lòng quay lại sau.';
        }

        if (! Schema::hasTable('store_hours')) {
            return null;
        }

        $now = \Carbon\Carbon::now('Asia/Ho_Chi_Minh');
        $currentDay = (int) $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $storeHour = DB::table('store_hours')
            ->where('store_id', $store->id)
            ->where('day_of_week', $currentDay)
            ->first();

        /**
         * Không có cấu hình giờ
         * => mặc định mở
         */
        if (! $storeHour) {
            return null;
        }

        if (! empty($storeHour->is_closed)) {
            return 'Cửa hàng không hoạt động vào thời gian này, vui lòng quay lại sau.';
        }

        $openTime = $storeHour->open_time;
        $closeTime = $storeHour->close_time;

        /**
         * Có record nhưng chưa cấu hình giờ đầy đủ
         * => mặc định mở
         */
        if (! $openTime || ! $closeTime) {
            return null;
        }

        // Hỗ trợ ca qua đêm
        if ($openTime <= $closeTime) {

            $isOpen =
                $currentTime >= $openTime &&
                $currentTime < $closeTime;
        } else {

            // VD: 22:00 -> 02:00
            $isOpen =
                $currentTime >= $openTime ||
                $currentTime < $closeTime;
        }

        if (! $isOpen) {
            return 'Cửa hàng không hoạt động vào thời gian này, vui lòng quay lại sau.';
        }

        return null;
    }

    protected function collectClosedStoresByIds(array $storeIds): array
    {
        $normalizedStoreIds = collect($storeIds)
            ->map(static fn($storeId) => trim((string) $storeId))
            ->filter(static fn(string $storeId) => $storeId !== '' && $storeId !== '__no_store__')
            ->unique()
            ->values();

        if ($normalizedStoreIds->isEmpty()) {
            return [];
        }

        $stores = Store::query()
            ->whereIn('id', $normalizedStoreIds->all())
            ->get()
            ->keyBy(static fn(Store $store) => (string) $store->id);

        $closedStores = [];

        foreach ($normalizedStoreIds as $storeId) {
            $store = $stores->get($storeId);

            if (! $store) {
                $closedStores[] = [
                    'id' => $storeId,
                    'name' => null,
                    'reason' => 'STORE_NOT_FOUND',
                ];

                continue;
            }

            if ($this->getStoreOperatingStatusMessage($store) !== null) {
                $closedStores[] = [
                    'id' => (string) $store->id,
                    'name' => (string) ($store->name ?? ''),
                    'reason' => $this->storeIsDebtLocked($store) ? 'STORE_DEBT_LOCKED' : 'STORE_CLOSED',
                ];
            }
        }

        return $closedStores;
    }

    protected function normalizeStoreIds(array $storeIds): array
    {
        return collect($storeIds)
            ->map(static fn($storeId) => trim((string) $storeId))
            ->filter(static fn(string $storeId) => $storeId !== '' && $storeId !== '__no_store__')
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveLinkedStoreIdsForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $ownerStoreIds = [];

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'owner_id')) {
            $ownerStoreQuery = Store::query()->where('owner_id', $user->id);

            if (Schema::hasColumn('stores', 'deleted_at')) {
                $ownerStoreQuery->whereNull('deleted_at');
            }

            $ownerStoreIds = $ownerStoreQuery->pluck('id')->all();
        }

        $bindingStoreIds = [];

        if (Schema::hasTable('user_role_bindings') && Schema::hasTable('roles')) {
            $bindingQuery = UserRoleBinding::query()
                ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
                ->where('user_role_bindings.user_id', $user->id)
                ->where('user_role_bindings.entity_type', 'store')
                ->whereIn('roles.name', array_merge(StorePermissionCatalog::STAFF_ROLES, ['admin_store']));

            if (Schema::hasColumn('user_role_bindings', 'status')) {
                $bindingQuery->where('user_role_bindings.status', 'active');
            }

            $bindingStoreIds = $bindingQuery
                ->pluck('user_role_bindings.entity_id')
                ->all();
        }

        return $this->normalizeStoreIds(array_merge($ownerStoreIds, $bindingStoreIds));
    }

    protected function resolveBlockedOwnStoreIds(?User $user, array $targetStoreIds): array
    {
        $normalizedTargetStoreIds = $this->normalizeStoreIds($targetStoreIds);

        if (! $user || empty($normalizedTargetStoreIds)) {
            return [];
        }

        $linkedStoreIds = $this->resolveLinkedStoreIdsForUser($user);

        if (empty($linkedStoreIds)) {
            return [];
        }

        $linkedStoreLookup = array_fill_keys($linkedStoreIds, true);

        return array_values(array_filter(
            $normalizedTargetStoreIds,
            static fn(string $storeId): bool => isset($linkedStoreLookup[$storeId])
        ));
    }

    protected function ownStoreOrderForbiddenResponse(array $blockedStoreIds)
    {
        return response()->json([
            'success' => false,
            'message' => 'Bạn không thể đặt sản phẩm của cửa hàng bạn.',
            'error_code' => 'OWN_STORE_ORDER_FORBIDDEN',
            'blocked_store_ids' => $this->normalizeStoreIds($blockedStoreIds),
        ], 422);
    }

    protected function normalizeOrderSnapshot(Order $order): Order
    {
        $this->resolveOrderSlaTimeout($order);

        $order->loadMissing(['user', 'store', 'items.product.store']);
        $returnDeadline = app(ProductReturnEligibilityService::class)->returnDeadline($order);
        $returnWindowOpen = $returnDeadline !== null && now()->lt($returnDeadline);
        $activeReturn = $this->resolveActiveReturnForOrder($order);
        $returnSummary = $this->buildReturnSummaryForOrder($order, $activeReturn);
        $order->setAttribute('return_deadline_at', $returnDeadline?->toIso8601String());
        $order->setAttribute('return_window_open', $returnWindowOpen);
        $order->setAttribute('active_return_id', $activeReturn ? (string) $activeReturn->id : null);
        $order->setAttribute('active_return_status', $activeReturn?->status);
        $order->setAttribute('has_active_return', $activeReturn !== null);
        $order->setAttribute('return_summary', $returnSummary);

        $storeName = trim((string) ($order->store?->name ?? $order->store_name ?? ''));
        $storeId = trim((string) ($order->store_id ?? ''));

        if ($storeName === '' && $order->items->isNotEmpty()) {
            $firstItem = $order->items->first();
            $storeName = trim((string) (data_get($firstItem, 'product.store.name') ?? data_get($firstItem, 'product.store_name') ?? ''));

            if ($storeId === '') {
                $storeId = trim((string) (data_get($firstItem, 'product.store_id') ?? ''));
            }
        }

        $customerName = trim((string) ($order->name ?? ''));
        if ($customerName === '') {
            $customerName = trim((string) ($order->user?->full_name ?? $order->user?->name ?? ''));
        }

        $customerPhone = trim((string) ($order->phone ?? $order->delivery_phone ?? ''));


        if ($customerPhone === '') {
            $customerPhone = trim((string) ($order->user?->phone ?? ''));
        }

        $totalAmount = (float) ($order->total_amount ?? $order->final_amount ?? $order->total ?? 0);
        if (($order->total ?? null) === null || (float) $order->total <= 0) {
            $order->setAttribute('total', $totalAmount);
        }
        if (($order->total_amount ?? null) === null || (float) $order->total_amount <= 0) {
            $order->setAttribute('total_amount', $totalAmount);
        }

        if (($order->final_amount ?? null) === null || (float) $order->final_amount <= 0) {
            $order->setAttribute('final_amount', $totalAmount);
        }

        if ($customerName !== '') {
            $order->setAttribute('name', $customerName);
        }
        if ($customerPhone !== '') {
            $order->setAttribute('phone', $customerPhone);
            if (empty($order->delivery_phone)) {
                $order->setAttribute('delivery_phone', $customerPhone);
            }
        }
        if ($storeId !== '' && empty($order->store_id)) {
            $order->setAttribute('store_id', $storeId);
        }
        if ($storeName !== '') {
            $order->setAttribute('store_name', $storeName);
        }

        $normalizedItems = $order->items->map(function (OrderItem $item) use ($returnWindowOpen) {
            $quantity = max(1, (int) ($item->quantity ?? 1));
            $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);

            if ($unitPrice <= 0) {
                $unitPrice = (float) (data_get($item, 'product.sale_price') ?? data_get($item, 'product.price') ?? 0);
            }

            if ($unitPrice <= 0) {
                $existingTotal = (float) ($item->total_price ?? 0);
                if ($existingTotal > 0) {
                    $unitPrice = round($existingTotal / $quantity, 2);
                }
            }

            $totalPrice = (float) ($item->total_price ?? 0);
            if ($totalPrice <= 0 && $unitPrice > 0) {
                $totalPrice = round($unitPrice * $quantity, 2);
            }

            $alreadyOpen = 0;
            if (Schema::hasTable('order_returns') && Schema::hasTable('order_return_items')) {
                $alreadyOpen = (int) \App\Models\OrderReturnItem::query()
                    ->where('order_item_id', (string) $item->id)
                    ->whereHas('orderReturn', fn ($query) => $query->whereIn('status', \App\Models\OrderReturn::OPEN_QUANTITY_STATUSES))
                    ->sum('quantity');
            }
            $availableQuantity = max(0, $quantity - $alreadyOpen);

            $item->setAttribute('unit_price', $unitPrice);
            $item->setAttribute('price', $unitPrice);
            $item->setAttribute('total_price', $totalPrice);
            $item->setAttribute('available_quantity', $availableQuantity);
            $item->setAttribute('return_blocked', !empty($item->return_blocked_at) || $availableQuantity <= 0);
            $item->setAttribute('return_eligible', $returnWindowOpen && empty($item->return_blocked_at) && $availableQuantity > 0);

            return $item;
        })->values();

        $order->setRelation('items', $normalizedItems);
        $order->setAttribute(
            'can_request_return',
            $returnWindowOpen
                && $activeReturn === null
                && $normalizedItems->contains(fn (OrderItem $item): bool => (bool) $item->getAttribute('return_eligible'))
        );

        return $order;
    }

    protected function resolveActiveReturnForOrder(Order $order): ?OrderReturn
    {
        if (! $order->getKey() || ! $this->hasDatabaseTable('order_returns')) {
            return null;
        }

        if ($order->relationLoaded('returns')) {
            return $order->returns
                ->whereNotIn('status', [OrderReturn::STATUS_REFUNDED, OrderReturn::STATUS_CLOSED])
                ->sortByDesc('created_at')
                ->first();
        }

        $columns = ['id', 'order_id', 'status', 'return_method', 'created_at'];
        if (Schema::hasColumn('order_returns', 'refund_bank_info')) {
            $columns[] = 'refund_bank_info';
        }

        return OrderReturn::query()
            ->select($columns)
            ->where('order_id', (string) $order->getKey())
            ->whereNotIn('status', [OrderReturn::STATUS_REFUNDED, OrderReturn::STATUS_CLOSED])
            ->orderByDesc('created_at')
            ->first();
    }

    protected function resolveLatestReturnForOrder(Order $order): ?OrderReturn
    {
        if (! $order->getKey() || ! $this->hasDatabaseTable('order_returns')) {
            return null;
        }

        if ($order->relationLoaded('returns')) {
            return $order->returns
                ->sortByDesc('created_at')
                ->first();
        }

        $columns = [
            'id',
            'order_id',
            'status',
            'return_method',
            'return_code',
            'refund_amount_estimated',
            'refund_amount_final',
            'created_at',
        ];
        if (Schema::hasColumn('order_returns', 'refund_bank_info')) {
            $columns[] = 'refund_bank_info';
        }

        return OrderReturn::query()
            ->select($columns)
            ->where('order_id', (string) $order->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    protected function buildReturnSummaryForOrder(Order $order, ?OrderReturn $activeReturn = null): array
    {
        $emptySummary = [
            'has_return' => false,
            'active_return_id' => null,
            'active_return_status' => null,
            'active_return_method' => null,
            'active_refund_bank_info' => null,
            'latest_return_id' => null,
            'latest_return_status' => null,
            'latest_return_method' => null,
            'latest_refund_bank_info' => null,
            'latest_return_code' => null,
            'refund_amount_estimated' => 0.0,
            'refund_amount_final' => 0.0,
        ];

        $latestReturn = $this->resolveLatestReturnForOrder($order);
        if (! $latestReturn) {
            return $emptySummary;
        }

        return [
            'has_return' => true,
            'active_return_id' => $activeReturn ? (string) $activeReturn->id : null,
            'active_return_status' => $activeReturn?->status,
            'active_return_method' => $activeReturn?->return_method,
            'active_refund_bank_info' => $activeReturn?->refund_bank_info,
            'latest_return_id' => (string) $latestReturn->id,
            'latest_return_status' => $latestReturn->status,
            'latest_return_method' => $latestReturn->return_method,
            'latest_refund_bank_info' => $latestReturn->refund_bank_info,
            'latest_return_code' => $latestReturn->return_code,
            'refund_amount_estimated' => (float) $latestReturn->refund_amount_estimated,
            'refund_amount_final' => (float) $latestReturn->refund_amount_final,
        ];
    }

    protected function hasDatabaseTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $exception) {
            try {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    return DB::table('sqlite_master')
                        ->where('type', 'table')
                        ->where('name', $table)
                        ->exists();
                }
            } catch (\Throwable $nestedException) {
                return false;
            }

            return false;
        }
    }

    protected function resolveOrderSlaTimeout(Order $order): void
    {
        if (!$order->isPending()) {
            return;
        }

        if (!$order->timeout_at) {
            $order->forceFill([
                'timeout_at' => $order->calculateTimeoutAt(),
            ])->save();
        }

        if (!$order->hasTimedOut()) {
            return;
        }

        app(OrderSlaService::class)->autoCancelTimeout($order);
        $order->refresh();
    }

    protected function normalizePreOrderItems(array $payload): array
    {
        $items = [];

        if (isset($payload['cart']) && is_array($payload['cart'])) {
            foreach ($payload['cart'] as $item) {
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
                $totalPrice = (float) ($item['total_price'] ?? ($unitPrice > 0 ? $unitPrice * $quantity : 0));

                $items[] = [
                    'product_name' => $item['name'] ?? 'Sản phẩm',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'thumbnail' => $item['thumbnail'] ?? '',
                    'ticket_reward_quantity' => $item['ticket_reward_quantity'] ?? 0,
                ];
            }
        } elseif (isset($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $item) {
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
                $totalPrice = (float) ($item['total_price'] ?? ($unitPrice > 0 ? $unitPrice * $quantity : 0));

                $items[] = [
                    'item_name' => $item['item_name'] ?? 'Món ăn',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'thumbnail' => $item['thumbnail'] ?? '',
                    'ticket_reward_quantity' => $item['ticket_reward_quantity'] ?? 0,
                ];
            }
        }

        return $items;
    }

    protected function phoneLookupCandidates(mixed $phone): array
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return [];
        }

        $digitsOnly = preg_replace('/\D+/', '', $raw) ?? '';
        $candidates = [$raw];

        if ($digitsOnly !== '') {
            $candidates[] = $digitsOnly;

            if (str_starts_with($digitsOnly, '84') && strlen($digitsOnly) > 2) {
                $candidates[] = '0' . substr($digitsOnly, 2);
            }

            if (str_starts_with($digitsOnly, '0') && strlen($digitsOnly) > 1) {
                $candidates[] = '84' . substr($digitsOnly, 1);
                $candidates[] = '+84' . substr($digitsOnly, 1);
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn($value) => $value !== '')));
    }

    public function settleOrderAsPaid(Order $order, ?string $paymentMethodOverride = null, ?string $transactionId = null): void
    {
        $dispatchOrderId = null;

        DB::transaction(function () use ($order, $paymentMethodOverride, $transactionId, &$dispatchOrderId) {
            $lockedOrder = Order::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return;
            }

            $hasStatusPayment = Schema::hasColumn($lockedOrder->getTable(), 'status_payment');
            $statusPaymentBefore = $hasStatusPayment
                ? strtolower((string) ($lockedOrder->status_payment ?? 'pending'))
                : strtolower((string) ($lockedOrder->status ?? 'pending'));

            $statusBefore = strtolower((string) ($lockedOrder->status ?? ''));

            if ($statusPaymentBefore === 'paid' && $statusBefore !== 'pending_payment') {
                return;
            }

            $hasCalledQuantity = Schema::hasTable('order_items')
                && Schema::hasColumn('order_items', 'called_quantity')
                && Schema::hasColumn('order_items', 'quantity');

            $isDeferCallOrder = false;
            if ($hasCalledQuantity) {
                $isDeferCallOrder = OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->whereRaw('COALESCE(quantity, 0) > COALESCE(called_quantity, 0)')
                    ->exists();
            }

            if ($statusBefore === 'pending_payment') {
                $canSyncStock = Schema::hasTable('order_items')
                    && Schema::hasColumn('order_items', 'product_id')
                    && Schema::hasColumn('order_items', 'quantity');
                $hasOrderItemVariantId = Schema::hasColumn('order_items', 'variant_id');
                $hasOrderItemMenuItemId = Schema::hasColumn('order_items', 'menu_item_id');
                $hasStoreMenuTable = Schema::hasTable('store_menu');
                $hasStoreMenuId = $hasStoreMenuTable && Schema::hasColumn('store_menu', 'id');
                $hasStoreMenuProductId = $hasStoreMenuTable && Schema::hasColumn('store_menu', 'product_id');
                $hasStoreMenuVariantId = $hasStoreMenuTable && Schema::hasColumn('store_menu', 'variant_id');
                $canResolveFromStoreMenu = $hasOrderItemMenuItemId
                    && $hasStoreMenuId
                    && ($hasStoreMenuProductId || $hasStoreMenuVariantId);
                $canSyncLegacyStock = Schema::hasTable('order_items')
                    && !Schema::hasColumn('order_items', 'product_id')
                    && Schema::hasColumn('order_items', 'item_name')
                    && Schema::hasColumn('order_items', 'quantity');

                if ($canSyncStock) {
                    $orderItems = OrderItem::query()
                        ->where('order_id', $lockedOrder->id)
                        ->lockForUpdate()
                        ->get();

                    foreach ($orderItems as $orderItem) {
                        $quantity = max(0, (int) ($orderItem->quantity ?? 0));

                        if ($quantity <= 0) {
                            continue;
                        }

                        $resolvedReference = $this->resolveOrderItemStockReference(
                            $orderItem,
                            $hasOrderItemVariantId,
                            $canResolveFromStoreMenu,
                            $hasStoreMenuProductId,
                            $hasStoreMenuVariantId
                        );

                        if ($resolvedReference === null) {
                            continue;
                        }

                        $productId = (int) ($resolvedReference['product_id'] ?? 0);
                        if ($productId <= 0) {
                            continue;
                        }

                        $variantId = (int) ($resolvedReference['variant_id'] ?? 0);

                        $this->deductStockByReference($productId, $variantId > 0 ? $variantId : null, $quantity);
                    }
                } elseif ($canSyncLegacyStock) {
                    $legacyOrderItems = OrderItem::query()
                        ->where('order_id', $lockedOrder->id)
                        ->lockForUpdate()
                        ->get();

                    foreach ($legacyOrderItems as $legacyOrderItem) {
                        $quantity = max(0, (int) ($legacyOrderItem->quantity ?? 0));
                        if ($quantity <= 0) {
                            continue;
                        }

                        $resolvedReference = $this->resolveLegacyOrderItemStockReference($legacyOrderItem);
                        if ($resolvedReference === null) {
                            continue;
                        }

                        $resolvedVariantId = (int) ($resolvedReference['variant_id'] ?? 0);
                        $resolvedProductId = (int) ($resolvedReference['product_id'] ?? 0);
                        $this->deductStockByReference(
                            $resolvedProductId,
                            $resolvedVariantId > 0 ? $resolvedVariantId : null,
                            $quantity
                        );
                    }
                }
            }

            $updatePayload = [];

            if ($hasStatusPayment) {
                $updatePayload['status_payment'] = 'paid';
            }

            if (Schema::hasColumn($lockedOrder->getTable(), 'payment_method')) {
                $normalizedPaymentMethod = trim((string) $paymentMethodOverride);
                if ($normalizedPaymentMethod !== '') {
                    $updatePayload['payment_method'] = $normalizedPaymentMethod;
                }
            }

            if (Schema::hasColumn($lockedOrder->getTable(), 'transaction_id') && $transactionId !== null) {
                $updatePayload['transaction_id'] = trim((string) $transactionId) !== ''
                    ? (string) $transactionId
                    : null;
            }

            if (Schema::hasColumn($lockedOrder->getTable(), 'status') && $statusBefore === 'pending_payment') {
                $updatePayload['status'] = $isDeferCallOrder ? 'paid_pending_call' : 'pending';
            }

            if (! empty($updatePayload)) {
                $lockedOrder->update($updatePayload);
            }

            if (($updatePayload['status'] ?? null) === 'pending') {
                $this->precheckStoreCreditForPendingOrder($lockedOrder->fresh() ?? $lockedOrder);
            }

            if (! empty($lockedOrder->user_id)) {
                $orderUser = User::query()->find($lockedOrder->user_id);
                $finalTotal = (float) ($lockedOrder->final_amount ?? $lockedOrder->total_amount ?? $lockedOrder->total ?? 0);
                // Only award tickets for non-cash payment methods
                // CASH orders will have tickets awarded when markDelivered is called by admin
                $paymentMethod = strtoupper(trim((string) ($lockedOrder->payment_method ?? '')));
                if (!in_array($paymentMethod, ['CASH', 'COD'], true)) {
                    $this->awardTicketsForOrder($orderUser, $lockedOrder, $finalTotal);
                }
            }

            if ($statusBefore === 'pending_payment') {
                $dispatchOrderId = (string) $lockedOrder->id;
            }
        });

        if ($dispatchOrderId !== null) {
            $refreshedOrder = Order::query()->where('id', $dispatchOrderId)->first();
            if ($refreshedOrder) {
                event(new NewOrderPlaced($refreshedOrder));
            }
        }
    }

    private function resolveOrderItemStockReference(
        OrderItem $orderItem,
        bool $hasOrderItemVariantId,
        bool $canResolveFromStoreMenu,
        bool $hasStoreMenuProductId,
        bool $hasStoreMenuVariantId
    ): ?array {
        $productId = (int) ($orderItem->product_id ?? 0);
        $variantId = $hasOrderItemVariantId ? (int) ($orderItem->variant_id ?? 0) : 0;

        if ($variantId > 0) {
            $variant = ProductVariant::query()->where('id', $variantId)->first();
            if ($variant) {
                return [
                    'product_id' => (int) ($variant->product_id ?? $productId),
                    'variant_id' => (int) $variant->id,
                ];
            }
        }

        if ($productId > 0) {
            return [
                'product_id' => $productId,
                'variant_id' => null,
            ];
        }

        if ($canResolveFromStoreMenu) {
            $menuItemId = trim((string) ($orderItem->menu_item_id ?? ''));
            if ($menuItemId !== '') {
                $selectColumns = [];
                if ($hasStoreMenuProductId) {
                    $selectColumns[] = 'product_id';
                }
                if ($hasStoreMenuVariantId) {
                    $selectColumns[] = 'variant_id';
                }

                if (!empty($selectColumns)) {
                    $menuRow = DB::table('store_menu')
                        ->where('id', $menuItemId)
                        ->first($selectColumns);

                    if ($menuRow) {
                        $menuVariantId = $hasStoreMenuVariantId ? (int) ($menuRow->variant_id ?? 0) : 0;
                        $menuProductId = $hasStoreMenuProductId ? (int) ($menuRow->product_id ?? 0) : 0;

                        if ($menuVariantId > 0) {
                            $variant = ProductVariant::query()->where('id', $menuVariantId)->first();
                            if ($variant) {
                                return [
                                    'product_id' => (int) ($variant->product_id ?? $menuProductId),
                                    'variant_id' => (int) $variant->id,
                                ];
                            }
                        }

                        if ($menuProductId > 0) {
                            return [
                                'product_id' => $menuProductId,
                                'variant_id' => null,
                            ];
                        }
                    }
                }
            }
        }

        return $this->resolveLegacyOrderItemStockReference($orderItem);
    }

    private function resolveLegacyOrderItemStockReference(OrderItem $orderItem): ?array
    {
        $rawItemName = trim((string) ($orderItem->item_name ?? ''));
        if ($rawItemName === '') {
            return null;
        }

        $baseName = $rawItemName;
        $variantSignature = '';

        if (preg_match('/^(.*?)\s*\[(.*?)\]\s*$/u', $rawItemName, $matches)) {
            $baseName = trim((string) ($matches[1] ?? $rawItemName));
            $variantSignature = trim((string) ($matches[2] ?? ''));
        }

        if ($baseName === '') {
            return null;
        }

        $normalize = static function (?string $value): string {
            $text = trim((string) ($value ?? ''));
            if ($text === '') {
                return '';
            }

            return function_exists('mb_strtolower')
                ? mb_strtolower($text)
                : strtolower($text);
        };

        $normalizedVariantSignature = $normalize($variantSignature);
        $unitPrice = (float) ($orderItem->unit_price ?? 0);

        $products = Product::query()
            ->where('name', $baseName)
            ->get(['id', 'name', 'price', 'sale_price']);

        if ($products->isEmpty()) {
            return null;
        }

        foreach ($products as $product) {
            $variants = ProductVariant::query()
                ->where('product_id', (int) $product->id)
                ->get(['id', 'product_id', 'price', 'sale_price']);

            if ($normalizedVariantSignature !== '') {
                foreach ($variants as $variant) {
                    $variantValues = $variant->attributeValues()
                        ->pluck('value')
                        ->map(fn($value) => trim((string) $value))
                        ->filter()
                        ->values()
                        ->all();

                    $resolvedSignature = $normalize(implode(' - ', $variantValues));
                    if ($resolvedSignature !== '' && $resolvedSignature === $normalizedVariantSignature) {
                        return [
                            'product_id' => (int) $product->id,
                            'variant_id' => (int) $variant->id,
                        ];
                    }
                }

                if ($unitPrice > 0) {
                    $priceMatchedVariants = $variants
                        ->filter(function ($variant) use ($unitPrice) {
                            $variantSalePrice = (float) ($variant->sale_price ?? 0);
                            $variantPrice = (float) ($variant->price ?? 0);
                            $effectiveUnitPrice = $variantSalePrice > 0 ? $variantSalePrice : $variantPrice;
                            return $effectiveUnitPrice > 0 && abs($effectiveUnitPrice - $unitPrice) < 0.01;
                        })
                        ->values();

                    if ($priceMatchedVariants->count() === 1) {
                        return [
                            'product_id' => (int) $product->id,
                            'variant_id' => (int) $priceMatchedVariants[0]->id,
                        ];
                    }
                }
            }
        }

        if ($products->count() === 1) {
            return [
                'product_id' => (int) $products[0]->id,
                'variant_id' => null,
            ];
        }

        if ($unitPrice > 0) {
            $matchedProductsByPrice = $products
                ->filter(function ($product) use ($unitPrice) {
                    $salePrice = (float) ($product->sale_price ?? 0);
                    $price = (float) ($product->price ?? 0);
                    $effectiveUnitPrice = $salePrice > 0 ? $salePrice : $price;
                    return $effectiveUnitPrice > 0 && abs($effectiveUnitPrice - $unitPrice) < 0.01;
                })
                ->values();

            if ($matchedProductsByPrice->count() === 1) {
                return [
                    'product_id' => (int) $matchedProductsByPrice[0]->id,
                    'variant_id' => null,
                ];
            }
        }

        return null;
    }

    protected function deductStockByReference(int $productId, ?int $variantId, int $quantity): void
    {
        if ($quantity <= 0 || $productId <= 0) {
            return;
        }

        if ($variantId !== null && $variantId > 0) {
            $variant = ProductVariant::query()
                ->where('id', $variantId)
                ->lockForUpdate()
                ->first();

            if ($variant) {
                $variant->decrement('stock', $quantity);
                $this->syncProductStockFromVariants((int) ($variant->product_id ?? $productId));
                return;
            }
        }

        $product = Product::query()
            ->where('id', $productId)
            ->lockForUpdate()
            ->first();

        if ($product) {
            $product->decrement('stock', $quantity);
        }
    }

    protected function syncProductStockFromVariants(int $productId): void
    {
        if (
            $productId <= 0
            || !Schema::hasTable('product_variants')
            || !Schema::hasColumn('product_variants', 'product_id')
            || !Schema::hasColumn('product_variants', 'stock')
            || !Schema::hasTable('products')
            || !Schema::hasColumn('products', 'stock')
        ) {
            return;
        }

        $aggregateStock = (int) ProductVariant::query()
            ->where('product_id', $productId)
            ->sum('stock');

        Product::query()
            ->where('id', $productId)
            ->update(['stock' => max(0, $aggregateStock)]);
    }

    /**
     * Display a listing of the resource.
     */
    public function checkUser(Request $request)
    {
        $user = null;
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }
        if (!$user) {
            return response()->json([
                'message' => 'Không có quyền truy cập',
                'error' => true
            ], 401);
        }
        $hasHighValueCombo = $this->hasHighValueCombo($user);
        $level = $this->getQualifiedDepth($user);
        return response()->json([
            'user' => $user,
            'hasHighValueCombo' => $hasHighValueCombo,
            'level' => $level
        ]);
    }
    private function getQualifiedDepth($user, $maxDepth = 3)
    {
        $level = 0;
        $currentUsers = collect([$user]);

        for ($i = 0; $i < $maxDepth; $i++) {
            $nextUsers = collect();

            foreach ($currentUsers  as $u) {
                foreach ($u->referredUsers ?? [] as $refUser) {
                    if ($this->hasHighValueCombo($refUser)) {
                        $nextUsers->push($refUser);
                    }
                }
            }

            if ($nextUsers->isEmpty()) {
                break;
            }

            $level++;
            $currentUsers = $nextUsers;
        }

        return $level;
    }
    private function hasHighValueCombo($user)
    {
        $baseQuery = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed');

        // Legacy schema: order_items.product_id -> products(id)
        if (
            Schema::hasTable('order_items')
            && Schema::hasColumn('order_items', 'product_id')
            && Schema::hasTable('products')
            && Schema::hasColumn('products', 'category_id')
            && Schema::hasColumn('products', 'sale_price')
        ) {
            return (clone $baseQuery)
                ->whereHas('items.product', function ($query) {
                    $query->where('category_id', 2)
                        ->where('sale_price', '>=', 1000000);
                })
                ->exists();
        }

        // New schema: order_items.menu_item_id -> store_menu(id)
        if (
            Schema::hasTable('order_items')
            && Schema::hasColumn('order_items', 'menu_item_id')
            && Schema::hasTable('store_menu')
            && Schema::hasColumn('store_menu', 'price')
        ) {
            return (clone $baseQuery)
                ->whereHas('items.menuItem', function ($query) {
                    $query->where('price', '>=', 1000000);
                })
                ->exists();
        }

        return false;
    }



    public function historyAffilate(Request $request)
    {
        $user = null;
        $authHeader = $request->header('Authorization'); // lấy header Authorization
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];

            // kiểm tra token có hợp lệ không
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }
        $orders = Order::with(['items.product', 'store:id,name,address'])
            ->where('referrer_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
        return response()->json([
            'orders' => $orders
        ]);
        // return view('ordersHistoryAffilate', compact('orders'));
    }

    public function historyOrder(Request $request)
    {
        $user = null;
        $authHeader = $request->header('Authorization'); // lấy header Authorization
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];

            // kiểm tra token có hợp lệ không
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }
        $orders = Order::with(['items.product', 'store:id,name,address'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
        return response()->json([
            'orders' => $orders
        ]);
        // return view('ordersHistoryAffilate', compact('orders'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function place(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'phone'            => 'required|string|max:20',
            'address'          => 'nullable|string|max:255',
            'payment_method'   => 'required|string',
            'note'             => 'nullable|string|max:500',
            'store_id'         => 'nullable',
            'defer_call'       => 'nullable|boolean',
            'cart'             => 'required|array|min:1',
            'cart.*.id'        => 'required|integer',
            'cart.*.quantity'  => 'required|integer|min:1|max:1000000',
            'cart.*.variant_id' => 'nullable|integer',
            'cart.*.name'      => 'nullable',
            'cart.*.toppings'  => 'nullable|array',
            'cart.*.affiliate_referrer_id' => 'nullable|string|max:50',
            'referrer_id'      => 'nullable|string|max:50',
            'oldUser'          => 'nullable',
            'include_vat'      => 'nullable|boolean',
            'voucher'          => 'nullable|string|exists:vouchers,code',
            'shipping_method'  => 'nullable|string|max:50',
            'delivery_method'  => 'nullable|string|max:50',
        ]);

        $currentUser = $this->resolveApiUser($request);
        $referrerId = $this->resolveAffiliateRecipientId(
            isset($validated['referrer_id']) ? User::resolveReferrerId($validated['referrer_id']) : null,
            $currentUser?->id ? (int) $currentUser->id : null,
        );

        $cart = array_values($validated['cart']);
        $validProductsQuery = $this->applyApprovedProductScope(Product::query())
            ->whereIn('id', collect($cart)->pluck('id')->map(fn($id) => (int) $id)->unique()->all());
        $this->applyPublicProductStoreVisibilityScope($validProductsQuery);
        $validProductsById = $validProductsQuery
            ->get()
            ->keyBy('id');

        $cart = collect($cart)
            ->filter(function (array $item) use ($validProductsById): bool {
                return $validProductsById->has((int) ($item['id'] ?? 0));
            })
            ->values()
            ->all();

        if (empty($cart)) {
            throw ValidationException::withMessages([
                'cart' => ['Giỏ hàng không hợp lệ hoặc có sản phẩm đã bị gỡ khỏi hệ thống. Vui lòng tải lại trang và thử lại.'],
            ]);
        }

        $blockedStoreIds = $this->resolveBlockedOwnStoreIds(
            $currentUser,
            collect($cart)
                ->map(fn(array $item) => $validProductsById->get((int) ($item['id'] ?? 0))?->store_id)
                ->all()
        );

        if (! empty($blockedStoreIds)) {
            return $this->ownStoreOrderForbiddenResponse($blockedStoreIds);
        }

        $cartTotalQuantity = (int) collect($cart)->sum(function ($item) {
            return (int) ($item['quantity'] ?? 0);
        });

        $deferCall = (bool) ($validated['defer_call'] ?? false);
        $shippingMethod = (string) ($validated['shipping_method'] ?? $validated['delivery_method'] ?? '');

        // Treat shipping_method: "later" as defer_call - don't notify admin until user calls
        if (strtolower($shippingMethod) === 'later') {
            $deferCall = true;
        }

        $paymentMethod = strtoupper((string) ($validated['payment_method'] ?? ''));
        $isPendingPayment = $this->isPendingPaymentMethod($paymentMethod);

        $voucherCode = $validated['voucher'] ?? null;
        $user = $this->resolveApiUser($request);

        if ($user && $referrerId && (int) $referrerId === (int) $user->id) {
            $referrerId = null;
        }

        // Gioi han 100 tinh theo so luong chua lay ra (quantity - called_quantity).
        // $currentUnpickedQuantity = 0;

        // if ($user) {
        //     $currentUnpickedQuantity = (int) OrderItem::query()
        //         ->join('orders', 'orders.id', '=', 'order_items.order_id')
        //         ->where('orders.user_id', $user->id)
        //         ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(order_items.quantity, 0) > COALESCE(order_items.called_quantity, 0) THEN COALESCE(order_items.quantity, 0) - COALESCE(order_items.called_quantity, 0) ELSE 0 END), 0) as remaining_quantity')
        //         ->value('remaining_quantity');
        // }

        // $newUnpickedQuantity = $deferCall ? $cartTotalQuantity : 0;
        // $totalUnpickedQuantity = $currentUnpickedQuantity + $newUnpickedQuantity;

        // if ($totalUnpickedQuantity > 100) {
        //     throw ValidationException::withMessages([
        //         'cart' => ["Số lượng sản phẩm chưa lấy ra là {$totalUnpickedQuantity}, vượt quá giới hạn 100. Vui lòng giảm số lượng sản phẩm lại để có thể đặt hàng."],
        //     ]);
        // }
        DB::beginTransaction();

        try {
            $flashSaleService = app(FlashSaleService::class);
            $hasOrderId = Schema::hasColumn('orders', 'id');
            $hasOrderCode = Schema::hasColumn('orders', 'order_code');
            $hasOrderUserId = Schema::hasColumn('orders', 'user_id');
            $hasOrderName = Schema::hasColumn('orders', 'name');
            $hasOrderPhone = Schema::hasColumn('orders', 'phone');
            $hasOrderAddress = Schema::hasColumn('orders', 'address');
            $hasOrderDeliveryPhone = Schema::hasColumn('orders', 'delivery_phone');
            $hasOrderDeliveryAddress = Schema::hasColumn('orders', 'delivery_address');
            $hasOrderPaymentMethod = Schema::hasColumn('orders', 'payment_method');
            $hasOrderStatusPayment = Schema::hasColumn('orders', 'status_payment');
            $hasOrderParentOrderId = Schema::hasColumn('orders', 'parent_order_id');
            $hasOrderStoreId = Schema::hasColumn('orders', 'store_id');
            $hasOrderTotal = Schema::hasColumn('orders', 'total');
            $hasOrderTotalAmount = Schema::hasColumn('orders', 'total_amount');
            $hasOrderFinalAmount = Schema::hasColumn('orders', 'final_amount');
            $hasOrderStatus = Schema::hasColumn('orders', 'status');
            $hasOrderReferrerId = Schema::hasColumn('orders', 'referrer_id');
            $hasOrderShippingMethod = Schema::hasColumn('orders', 'shipping_method');

            $hasOrderItemId = Schema::hasColumn('order_items', 'id');
            $hasOrderItemOrderId = Schema::hasColumn('order_items', 'order_id');
            $hasOrderItemProductId = Schema::hasColumn('order_items', 'product_id');
            $hasOrderItemVariantId = Schema::hasColumn('order_items', 'variant_id');
            $hasOrderItemProductName = Schema::hasColumn('order_items', 'product_name');
            $hasOrderItemItemName = Schema::hasColumn('order_items', 'item_name');
            $hasOrderItemThumbnail = Schema::hasColumn('order_items', 'thumbnail');
            $hasOrderItemPrice = Schema::hasColumn('order_items', 'price');
            $hasOrderItemUnitPrice = Schema::hasColumn('order_items', 'unit_price');
            $hasOrderItemQuantity = Schema::hasColumn('order_items', 'quantity');
            $hasOrderItemCalledQuantity = Schema::hasColumn('order_items', 'called_quantity');
            $hasOrderItemTotalPrice = Schema::hasColumn('order_items', 'total_price');
            $hasOrderItemReferrerId = Schema::hasColumn('order_items', 'referrer_id');
            $hasOrderItemCommissionAmount = Schema::hasColumn('order_items', 'commission_amount');
            $hasOrderItemCommissionRateMode = Schema::hasColumn('order_items', 'commission_rate_mode');
            $hasProductStoreId = Schema::hasColumn('products', 'store_id');

            $preparedItems = [];
            $total = 0.0;

            foreach ($cart as $item) {
                $product = $validProductsById->get((int) ($item['id'] ?? 0)) ?? null;
                if (!$product) {
                    continue;
                }

                $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;
                $variant = null;
                $allProductVariantsQuery = ProductVariant::query()->where('product_id', (int) $product->id);
                $productVariantsQuery = (clone $allProductVariantsQuery);
                if (Schema::hasColumn('product_variants', 'enabled')) {
                    $productVariantsQuery->where('enabled', true);
                }

                if (!empty($variantId)) {
                    $variant = (clone $productVariantsQuery)
                        ->where('id', $variantId)
                        ->first();

                    if (!$variant) {
                        throw ValidationException::withMessages([
                            'cart' => ["Biến thể đã chọn cho sản phẩm \"{$product->name}\" không hợp lệ. Vui lòng chọn lại phân loại."],
                        ]);
                    }
                } else {
                    $variantCount = (int) (clone $productVariantsQuery)->count();

                    if ($variantCount === 0 && (clone $allProductVariantsQuery)->exists()) {
                        throw ValidationException::withMessages([
                            'cart' => ["San pham \"{$product->name}\" khong con phan loai kha dung."],
                        ]);
                    }

                    if ($variantCount > 1) {
                        throw ValidationException::withMessages([
                            'cart' => ["Sản phẩm \"{$product->name}\" có nhiều biến thể. Vui lòng chọn phân loại trước khi thanh toán."],
                        ]);
                    }

                    if ($variantCount === 1) {
                        $variant = (clone $productVariantsQuery)->first();
                    }
                }

                $this->assertProductMenuAvailable(
                    $product,
                    $variant ? (int) $variant->id : (!empty($variantId) ? (int) $variantId : null)
                );

                $stock = $variant ? $variant->stock : $product->stock;
                if ((int) ($item['quantity'] ?? 0) > (int) $stock) {
                    throw ValidationException::withMessages([
                        'cart' => ["Sản phẩm \"{$product->name}\" chưa đủ {$stock} trong kho."]
                    ]);
                }

                if ($variant) {
                    $variantSalePrice = (float) ($variant->sale_price ?? 0);
                    $variantPrice = (float) ($variant->price ?? 0);
                    $basePrice = $variantSalePrice > 0 ? $variantSalePrice : $variantPrice;
                } else {
                    $productSalePrice = (float) ($product->sale_price ?? 0);
                    $productPrice = (float) ($product->price ?? 0);
                    $basePrice = $productSalePrice > 0 ? $productSalePrice : $productPrice;
                }

                $pricing = $flashSaleService->consumeFlashSaleQuantity(
                    $product->store_id !== null ? (string) $product->store_id : null,
                    (int) $product->id,
                    $variant ? (int) $variant->id : null,
                    (int) $item['quantity'],
                    $basePrice,
                    $currentUser?->id ? (int) $currentUser->id : null
                );

                $salePrice = (float) ($pricing['price'] ?? $basePrice);

                $toppingTotal = 0.0;
                if (!empty($item['toppings']) && is_array($item['toppings'])) {
                    foreach ($item['toppings'] as $topping) {
                        $tPrice = (float) ($topping['price'] ?? 0.0);
                        $tSalePrice = isset($topping['sale_price']) ? (float) $topping['sale_price'] : null;
                        if ($tSalePrice !== null && $tSalePrice > 0 && $tSalePrice < $tPrice) {
                            $toppingTotal += $tSalePrice;
                        } else {
                            $toppingTotal += $tPrice;
                        }
                    }
                }
                $salePrice += $toppingTotal;

                if ($salePrice <= 0) {
                    throw ValidationException::withMessages([
                        'cart' => ["Sản phẩm \"{$product->name}\" chưa có giá hợp lệ. Vui lòng cập nhật giá trước khi thanh toán."],
                    ]);
                }

                $quantity = (int) $item['quantity'];
                $subtotal = $salePrice * $quantity;
                $total += $subtotal;
                $productName = $product->name;

                if ($variant && $variant->attributeValues()->exists()) {
                    $attributeValues = $variant->attributeValues()->pluck('value')->toArray();
                    $productName .= ' [' . implode(' - ', $attributeValues) . ']';
                }

                if (!empty($item['toppings']) && is_array($item['toppings'])) {
                    $toppingNames = [];
                    foreach ($item['toppings'] as $topping) {
                        if (!empty($topping['name'])) {
                            $toppingNames[] = trim($topping['name']);
                        }
                    }
                    if (!empty($toppingNames)) {
                        $productName .= ' (Topping: ' . implode(', ', $toppingNames) . ')';
                    }
                }

                $preparedItems[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'sale_price' => $salePrice,
                    'subtotal' => $subtotal,
                    'product_name' => $productName,
                    'store_id' => $hasProductStoreId && !empty($product->store_id) ? (string) $product->store_id : null,
                    'commission_rate_mode' => $item['commissionRateMode'] ?? 'percent',
                    'affiliate_referrer_id' => $referrerId
                        ? null
                        : $this->resolveAffiliateRecipientId(
                            isset($item['affiliate_referrer_id']) ? User::resolveReferrerId($item['affiliate_referrer_id']) : null,
                            $currentUser?->id ? (int) $currentUser->id : null,
                        ),
                ];
            }

            if (empty($preparedItems)) {
                throw ValidationException::withMessages([
                    'cart' => ['Giỏ hàng không hợp lệ hoặc có sản phẩm đã bị gỡ khỏi hệ thống. Vui lòng tải lại trang và thử lại.'],
                ]);
            }

            $closedStores = $this->collectClosedStoresByIds(
                collect($preparedItems)
                    ->pluck('store_id')
                    ->all()
            );

            if (! empty($closedStores)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Một hoặc nhiều cửa hàng trong giỏ hàng hiện đã ngừng nhận đơn.',
                    'error_code' => 'STORE_CLOSED_EXCEPTION',
                    'invalid_store_ids' => array_values(array_map(static fn(array $store) => (string) $store['id'], $closedStores)),
                    'closed_stores' => $closedStores,
                ], 422);
            }

            $discountAmount = 0;
            if ($voucherCode) {
                $voucher = Voucher::where('code', $voucherCode)->first();

                if (!$voucher) {
                    throw ValidationException::withMessages([
                        'voucher' => ["Mã giảm giá không tồn tại."]
                    ]);
                }

                if (!$voucher->is_active) {
                    throw ValidationException::withMessages([
                        'voucher' => ["Mã giảm giá hiện không hoạt động."]
                    ]);
                }

                if (now()->lt($voucher->start_date)) {
                    throw ValidationException::withMessages([
                        'voucher' => ["Mã giảm giá chưa bắt đầu."]
                    ]);
                }

                if (now()->gt($voucher->end_date)) {
                    throw ValidationException::withMessages([
                        'voucher' => ["Mã giảm giá đã hết hạn."]
                    ]);
                }

                if ($voucher->quantity <= $voucher->used) {
                    throw ValidationException::withMessages([
                        'voucher' => ["Mã giảm giá đã được sử dụng hết."]
                    ]);
                }

                if ($voucher->min_order_value && $total < $voucher->min_order_value) {
                    throw ValidationException::withMessages([
                        'voucher' => ["Đơn hàng không đủ giá trị tối thiểu để áp dụng mã giảm giá."]
                    ]);
                }

                if ($voucher->type == 'percentage') {
                    $discountValue = (float) $voucher->discount_value;
                    $discountAmount = ceil($total * ($discountValue / 100));
                } else {
                    $discountAmount = (float) $voucher->discount_value;
                }

                $voucher->increment('used');
            }

            $includeVAT = $validated['include_vat'] ?? false;
            $vatAmount = $includeVAT ? ceil(($total - $discountAmount) * 0.08) : 0;

            $weights = array_map(static fn(array $line): float => (float) ($line['subtotal'] ?? 0), $preparedItems);
            $discountShares = $this->allocateAmountByWeight((float) $discountAmount, $weights);
            $vatShares = $this->allocateAmountByWeight((float) $vatAmount, $weights);

            $schemaFlags = [
                'hasOrderId' => $hasOrderId,
                'hasOrderCode' => $hasOrderCode,
                'hasOrderUserId' => $hasOrderUserId,
                'hasOrderName' => $hasOrderName,
                'hasOrderPhone' => $hasOrderPhone,
                'hasOrderAddress' => $hasOrderAddress,
                'hasOrderDeliveryPhone' => $hasOrderDeliveryPhone,
                'hasOrderDeliveryAddress' => $hasOrderDeliveryAddress,
                'hasOrderPaymentMethod' => $hasOrderPaymentMethod,
                'hasOrderStatusPayment' => $hasOrderStatusPayment,
                'hasOrderParentOrderId' => $hasOrderParentOrderId,
                'hasOrderStoreId' => $hasOrderStoreId,
                'hasOrderTotal' => $hasOrderTotal,
                'hasOrderTotalAmount' => $hasOrderTotalAmount,
                'hasOrderFinalAmount' => $hasOrderFinalAmount,
                'hasOrderStatus' => $hasOrderStatus,
                'hasOrderReferrerId' => $hasOrderReferrerId,
                'hasOrderShippingMethod' => $hasOrderShippingMethod,
                'hasOrderDeliveryNote' => Schema::hasColumn('orders', 'delivery_note'),
                'hasOrderItemId' => $hasOrderItemId,
                'hasOrderItemOrderId' => $hasOrderItemOrderId,
                'hasOrderItemProductId' => $hasOrderItemProductId,
                'hasOrderItemVariantId' => $hasOrderItemVariantId,
                'hasOrderItemProductName' => $hasOrderItemProductName,
                'hasOrderItemItemName' => $hasOrderItemItemName,
                'hasOrderItemThumbnail' => $hasOrderItemThumbnail,
                'hasOrderItemPrice' => $hasOrderItemPrice,
                'hasOrderItemUnitPrice' => $hasOrderItemUnitPrice,
                'hasOrderItemQuantity' => $hasOrderItemQuantity,
                'hasOrderItemCalledQuantity' => $hasOrderItemCalledQuantity,
                'hasOrderItemTotalPrice' => $hasOrderItemTotalPrice,
                'hasOrderItemReferrerId' => $hasOrderItemReferrerId,
                'hasOrderItemCommissionAmount' => $hasOrderItemCommissionAmount,
                'hasOrderItemCommissionRateMode' => $hasOrderItemCommissionRateMode,
            ];

            $groupedItemsByStore = [];
            foreach ($preparedItems as $index => $line) {
                $preparedItems[$index]['discount_amount'] = (float) ($discountShares[$index] ?? 0);
                $preparedItems[$index]['vat_amount'] = (float) ($vatShares[$index] ?? 0);

                $storeKey = trim((string) ($preparedItems[$index]['store_id'] ?? ''));
                if ($storeKey === '') {
                    $storeKey = trim((string) ($validated['store_id'] ?? ''));
                }
                if ($storeKey === '') {
                    $storeKey = '__no_store__';
                }

                if (!array_key_exists($storeKey, $groupedItemsByStore)) {
                    $groupedItemsByStore[$storeKey] = [];
                }

                $groupedItemsByStore[$storeKey][] = $preparedItems[$index];
            }

            $createdOrders = [];
            foreach ($groupedItemsByStore as $groupLines) {
                $createdOrders[] = $this->createOrderForPreparedLine(
                    $schemaFlags,
                    $user,
                    $validated,
                    $groupLines,
                    $deferCall,
                    $isPendingPayment,
                    $paymentMethod,
                    $referrerId
                );
            }

            foreach ($createdOrders as $createdOrder) {
                // Only award tickets immediately for online payments (bank transfers, etc.)
                // For CASH/COD orders, tickets are awarded when admin marks order as delivered (markDelivered)
                if ($isPendingPayment) {
                    $finalTotal = (float) ($createdOrder->final_amount ?? $createdOrder->total_amount ?? $createdOrder->total ?? 0);
                    $this->awardTicketsForOrder($user, $createdOrder, $finalTotal);
                }
            }

            $this->consumeUserCartItemsByPurchasedLines($user, $cart);

            DB::commit();
            $shouldNotifyAdmin = !$isPendingPayment && strtolower($shippingMethod) !== 'later';
            if ($shouldNotifyAdmin) {
                foreach ($createdOrders as $createdOrder) {
                    event(new NewOrderPlaced($createdOrder));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Đặt hàng thành công!',
                'order_id' => $createdOrders[0]->id,
                'order_ids' => array_map(static fn(Order $createdOrder): string => (string) $createdOrder->id, $createdOrders),
                'defer_call' => $deferCall,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @param array<string, bool> $schemaFlags
     * @param array<int, array<string, mixed>> $line
     */
    protected function createOrderForPreparedLine(
        array $schemaFlags,
        ?User $user,
        array $validated,
        array $line,
        bool $deferCall,
        bool $isPendingPayment,
        string $paymentMethod,
        ?int $referrerId
    ): Order {
        if (empty($line)) {
            throw ValidationException::withMessages([
                'cart' => ['Không tìm thấy sản phẩm hợp lệ để tạo đơn hàng.'],
            ]);
        }

        $groupSubtotal = 0.0;
        $groupDiscountAmount = 0.0;
        $groupVatAmount = 0.0;
        $lineStoreId = null;

        foreach ($line as $preparedLine) {
            $groupSubtotal += (float) ($preparedLine['subtotal'] ?? 0);
            $groupDiscountAmount += (float) ($preparedLine['discount_amount'] ?? 0);
            $groupVatAmount += (float) ($preparedLine['vat_amount'] ?? 0);

            $candidateStoreId = trim((string) ($preparedLine['store_id'] ?? ''));
            if ($lineStoreId === null && $candidateStoreId !== '') {
                $lineStoreId = $candidateStoreId;
            }
        }

        $finalTotal = max(0, round($groupSubtotal - $groupDiscountAmount + $groupVatAmount, 2));

        $orderPayload = [];

        if ($schemaFlags['hasOrderId'] ?? false) {
            $orderPayload['id'] = (string) Str::uuid();
        }

        if ($schemaFlags['hasOrderCode'] ?? false) {
            $orderPayload['order_code'] = $this->generateOrderCode();
        }

        if ($schemaFlags['hasOrderUserId'] ?? false) {
            $orderPayload['user_id'] = $user?->id;
        }

        if ($schemaFlags['hasOrderName'] ?? false) {
            $orderPayload['name'] = $validated['name'];
        }

        if ($schemaFlags['hasOrderPhone'] ?? false) {
            $orderPayload['phone'] = $validated['phone'];
        }

        if ($schemaFlags['hasOrderAddress'] ?? false) {
            $orderPayload['address'] = $validated['address'] ?? '';
        }

        if ($schemaFlags['hasOrderDeliveryPhone'] ?? false) {
            $orderPayload['delivery_phone'] = $validated['phone'];
        }

        if ($schemaFlags['hasOrderDeliveryAddress'] ?? false) {
            $orderPayload['delivery_address'] = $validated['address'] ?? '';
        }

        if ($schemaFlags['hasOrderPaymentMethod'] ?? false) {
            $orderPayload['payment_method'] = $validated['payment_method'];
        }

        if ($schemaFlags['hasOrderStatusPayment'] ?? false) {
            // For CASH/COD payments, set status_payment to 'pending' until admin confirms delivery
            // Only set to 'paid' for actual bank transfers or other online payments
            $paymentMethodNorm = strtoupper(trim($paymentMethod));
            if (in_array($paymentMethodNorm, ['CASH', 'COD'], true)) {
                $orderPayload['status_payment'] = 'pending';
            } else {
                $orderPayload['status_payment'] = $isPendingPayment ? 'pending' : 'paid';
            }
        }

        if ($schemaFlags['hasOrderTotal'] ?? false) {
            $orderPayload['total'] = $finalTotal;
        }

        if ($schemaFlags['hasOrderTotalAmount'] ?? false) {
            $orderPayload['total_amount'] = $groupSubtotal;
        }

        if ($schemaFlags['hasOrderFinalAmount'] ?? false) {
            $orderPayload['final_amount'] = $finalTotal;
        }

        if (Schema::hasColumn('orders', 'discount_amount')) {
            $orderPayload['discount_amount'] = $groupDiscountAmount;
        }

        if (Schema::hasColumn('orders', 'vat_amount')) {
            $orderPayload['vat_amount'] = $groupVatAmount;
        }

        if ($schemaFlags['hasOrderStatus'] ?? false) {
            if ($isPendingPayment && !in_array($paymentMethod, ['CASH', 'COD'], true)) {
                $orderPayload['status'] = 'pending_payment';
            } else {
                $orderPayload['status'] = $deferCall ? 'paid_pending_call' : 'pending';
            }
        }

        if ($schemaFlags['hasOrderParentOrderId'] ?? false) {
            $orderPayload['parent_order_id'] = null;
        }

        if ($schemaFlags['hasOrderReferrerId'] ?? false) {
            $orderPayload['referrer_id'] = $referrerId;
        }

        if ($schemaFlags['hasOrderShippingMethod'] ?? false) {
            $orderPayload['shipping_method'] = $validated['shipping_method'] ?? $validated['delivery_method'] ?? null;
        }

        if ($schemaFlags['hasOrderDeliveryNote'] ?? false) {
            $orderPayload['delivery_note'] = $validated['note'] ?? null;
        }

        if ($schemaFlags['hasOrderStoreId'] ?? false) {
            $orderPayload['store_id'] = $lineStoreId ?: ($validated['store_id'] ?? null);
        }

        if (($schemaFlags['hasOrderTotal'] ?? false) && !array_key_exists('total', $orderPayload)) {
            $orderPayload['total'] = $finalTotal;
        }

        $order = Order::query()->create($orderPayload);

        foreach ($line as $preparedLine) {
            $product = $preparedLine['product'];
            $variant = $preparedLine['variant'] ?? null;
            $salePrice = (float) ($preparedLine['sale_price'] ?? 0);
            $subtotal = (float) ($preparedLine['subtotal'] ?? 0);
            $quantity = max(1, (int) ($preparedLine['quantity'] ?? 1));
            $productName = (string) ($preparedLine['product_name'] ?? $product->name);
            $lineReferrerId = $referrerId ?: (
                !empty($preparedLine['affiliate_referrer_id'])
                    ? (int) $preparedLine['affiliate_referrer_id']
                    : null
            );

            $orderItemPayload = [];

            if ($schemaFlags['hasOrderItemId'] ?? false) {
                $orderItemPayload['id'] = (string) Str::uuid();
            }

            if ($schemaFlags['hasOrderItemOrderId'] ?? false) {
                $orderItemPayload['order_id'] = $order->id;
            }

            if ($schemaFlags['hasOrderItemProductId'] ?? false) {
                $orderItemPayload['product_id'] = $product->id;
            }

            if ($schemaFlags['hasOrderItemVariantId'] ?? false) {
                $orderItemPayload['variant_id'] = $variant?->id;
            }

            if ($schemaFlags['hasOrderItemProductName'] ?? false) {
                $orderItemPayload['product_name'] = $productName;
            }

            if ($schemaFlags['hasOrderItemItemName'] ?? false) {
                $orderItemPayload['item_name'] = $productName;
            }

            if ($schemaFlags['hasOrderItemThumbnail'] ?? false) {
                $orderItemPayload['thumbnail'] = $product->thumbnail;
            }

            if ($schemaFlags['hasOrderItemPrice'] ?? false) {
                $orderItemPayload['price'] = $salePrice;
            }

            if ($schemaFlags['hasOrderItemUnitPrice'] ?? false) {
                $orderItemPayload['unit_price'] = $salePrice;
            }

            if ($schemaFlags['hasOrderItemQuantity'] ?? false) {
                $orderItemPayload['quantity'] = $quantity;
            }

            if ($schemaFlags['hasOrderItemCalledQuantity'] ?? false) {
                $orderItemPayload['called_quantity'] = $deferCall ? 0 : $quantity;
            }

            if ($schemaFlags['hasOrderItemTotalPrice'] ?? false) {
                $orderItemPayload['total_price'] = $subtotal;
            }

            if ($schemaFlags['hasOrderItemReferrerId'] ?? false) {
                $orderItemPayload['referrer_id'] = $lineReferrerId;
            }

            $rewardPerUnit = (int) ($variant?->ticket_reward_quantity ?? $product->ticket_reward_quantity ?? 0);
            $orderItemPayload['ticket_reward_quantity'] = $rewardPerUnit * $quantity;

            if ($schemaFlags['hasOrderItemCommissionAmount'] ?? false) {
                $orderItemPayload['commission_amount'] = 0;
            }

            if ($schemaFlags['hasOrderItemCommissionRateMode'] ?? false) {
                $orderItemPayload['commission_rate_mode'] = $preparedLine['commission_rate_mode'] ?? 'percent';
            }

            $orderItem = OrderItem::query()->create($orderItemPayload);

            $combos = ProductCombo::where('product_id', $product->id)->get();
            foreach ($combos as $combo) {
                if ($quantity >= $combo->buy_quantity) {
                    $bonusQuantity = floor($quantity / $combo->buy_quantity) * $combo->bonus_quantity;
                    $bonusProduct = $this->applyApprovedProductScope(Product::query())
                        ->whereKey($combo->bonus_product_id)
                        ->first();

                    if ($bonusProduct) {
                        $giftOrderItemPayload = [];

                        if ($schemaFlags['hasOrderItemId'] ?? false) {
                            $giftOrderItemPayload['id'] = (string) Str::uuid();
                        }

                        if ($schemaFlags['hasOrderItemOrderId'] ?? false) {
                            $giftOrderItemPayload['order_id'] = $order->id;
                        }

                        if ($schemaFlags['hasOrderItemProductId'] ?? false) {
                            $giftOrderItemPayload['product_id'] = $bonusProduct->id;
                        }

                        if ($schemaFlags['hasOrderItemVariantId'] ?? false) {
                            $giftOrderItemPayload['variant_id'] = null;
                        }

                        if ($schemaFlags['hasOrderItemProductName'] ?? false) {
                            $giftOrderItemPayload['product_name'] = $bonusProduct->name . ' (Tặng)';
                        }

                        if ($schemaFlags['hasOrderItemItemName'] ?? false) {
                            $giftOrderItemPayload['item_name'] = $bonusProduct->name . ' (Tặng)';
                        }

                        if ($schemaFlags['hasOrderItemThumbnail'] ?? false) {
                            $giftOrderItemPayload['thumbnail'] = $bonusProduct->thumbnail;
                        }

                        if ($schemaFlags['hasOrderItemPrice'] ?? false) {
                            $giftOrderItemPayload['price'] = 0;
                        }

                        if ($schemaFlags['hasOrderItemUnitPrice'] ?? false) {
                            $giftOrderItemPayload['unit_price'] = 0;
                        }

                        if ($schemaFlags['hasOrderItemQuantity'] ?? false) {
                            $giftOrderItemPayload['quantity'] = $bonusQuantity;
                        }

                        if ($schemaFlags['hasOrderItemTotalPrice'] ?? false) {
                            $giftOrderItemPayload['total_price'] = 0;
                        }

                        if ($schemaFlags['hasOrderItemReferrerId'] ?? false) {
                            $giftOrderItemPayload['referrer_id'] = null;
                        }

                        if ($schemaFlags['hasOrderItemCommissionAmount'] ?? false) {
                            $giftOrderItemPayload['commission_amount'] = 0;
                        }

                        if ($schemaFlags['hasOrderItemCommissionRateMode'] ?? false) {
                            $giftOrderItemPayload['commission_rate_mode'] = 'percent';
                        }

                        OrderItem::query()->create($giftOrderItemPayload);
                    }
                }
            }

            if (! $isPendingPayment) {
                $this->deductStockByReference(
                    (int) $product->id,
                    $variant ? (int) $variant->id : null,
                    $quantity
                );
            }

            if ($lineReferrerId) {
                $commissionRate = 0;
                $commissionRateMode = $preparedLine['commission_rate_mode'] ?? 'percent';

                if ($variant && isset($variant->commission_rate) && $variant->commission_rate !== null) {
                    $commissionRate = (float) $variant->commission_rate;
                } else {
                    $commissionRate = (float) ($product->commission_rate ?? 0);
                }

                if ($commissionRateMode === 'fixed') {
                    $commissionAmount = $commissionRate;
                } else {
                    $commissionAmount = $salePrice * $quantity * ($commissionRate / 100);
                }

                CommissionUser::query()->firstOrCreate(
                    [
                        'order_item_id' => $orderItem->id,
                    ],
                    [
                        'user_id'       => $lineReferrerId,
                        'level'         => 1,
                        'amount'        => $commissionAmount,
                        'status'        => 'pending',
                    ],
                );

                if (($schemaFlags['hasOrderItemCommissionAmount'] ?? false)) {
                    $orderItem->commission_amount = $commissionAmount;
                    $orderItem->save();
                }
            }
        }

        app(\App\Services\AffiliateCommissionService::class)->syncOrderCommissions($order->fresh(['items.product', 'items.variant']));
        $this->precheckStoreCreditForPendingOrder($order->fresh() ?? $order);

        return $order;
    }

    protected function precheckStoreCreditForPendingOrder(Order $order): void
    {
        try {
            app(\App\Services\StoreCreditService::class)->precheckPendingOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Store credit precheck failed for pending order.', [
                'order_id' => (string) ($order->id ?? ''),
                'store_id' => (string) ($order->store_id ?? ''),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, float|int> $weights
     * @return array<int, float>
     */
    protected function allocateAmountByWeight(float $amount, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $amountCents = (int) round($amount * 100);
        $weightTotal = array_sum(array_map(static fn($value) => (float) $value, $weights));

        if ($amountCents <= 0 || $weightTotal <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $allocated = [];
        $usedCents = 0;

        foreach ($weights as $index => $weight) {
            $weightValue = (float) $weight;
            if ($index === $count - 1) {
                $shareCents = max(0, $amountCents - $usedCents);
            } else {
                $shareCents = (int) floor(($amountCents * $weightValue) / $weightTotal);
                $usedCents += $shareCents;
            }

            $allocated[] = round($shareCents / 100, 2);
        }

        return $allocated;
    }

    /**
     * @param array<int, array<string, mixed>> $purchasedLines
     */
    protected function consumeUserCartItemsByPurchasedLines(?User $user, array $purchasedLines): void
    {
        if (!$user || !Schema::hasTable('user_cart_items')) {
            return;
        }

        $hasUserId = Schema::hasColumn('user_cart_items', 'user_id');
        $hasProductId = Schema::hasColumn('user_cart_items', 'product_id');
        $hasQuantity = Schema::hasColumn('user_cart_items', 'quantity');

        if (!$hasUserId || !$hasProductId || !$hasQuantity) {
            return;
        }

        $hasVariantId = Schema::hasColumn('user_cart_items', 'variant_id');
        $userId = (int) $user->id;

        $normalizedLines = collect($purchasedLines)
            ->map(function (array $line) use ($hasVariantId): array {
                $productId = (int) ($line['product_id'] ?? $line['id'] ?? 0);
                $quantity = max(0, (int) ($line['quantity'] ?? 0));

                $variantId = 0;
                if ($hasVariantId) {
                    $variantId = isset($line['variant_id']) ? max(0, (int) $line['variant_id']) : 0;
                }

                return [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                ];
            })
            ->filter(static function (array $line): bool {
                return $line['product_id'] > 0 && $line['quantity'] > 0;
            })
            ->values();

        if ($normalizedLines->isEmpty()) {
            return;
        }

        $aggregatedLines = [];
        foreach ($normalizedLines as $line) {
            $lineKey = (int) $line['product_id'] . ':' . (int) $line['variant_id'];

            if (!isset($aggregatedLines[$lineKey])) {
                $aggregatedLines[$lineKey] = [
                    'product_id' => (int) $line['product_id'],
                    'variant_id' => (int) $line['variant_id'],
                    'quantity' => (int) $line['quantity'],
                ];
                continue;
            }

            $aggregatedLines[$lineKey]['quantity'] += (int) $line['quantity'];
        }

        $productIds = collect($aggregatedLines)
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        $cartRows = UserCartItem::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->lockForUpdate()
            ->get();

        if ($cartRows->isEmpty()) {
            return;
        }

        $rowsByKey = [];
        foreach ($cartRows as $cartRow) {
            $rowProductId = (int) ($cartRow->product_id ?? 0);
            $rowVariantId = $hasVariantId ? max(0, (int) ($cartRow->variant_id ?? 0)) : 0;
            $rowsByKey[$rowProductId . ':' . $rowVariantId] = $cartRow;
        }

        foreach ($aggregatedLines as $line) {
            $lineKey = (int) $line['product_id'] . ':' . (int) $line['variant_id'];

            if (!isset($rowsByKey[$lineKey])) {
                continue;
            }

            $cartRow = $rowsByKey[$lineKey];
            $remainingQuantity = (int) ($cartRow->quantity ?? 0) - (int) $line['quantity'];

            if ($remainingQuantity <= 0) {
                $cartRow->delete();
                continue;
            }

            $cartRow->quantity = $remainingQuantity;
            $cartRow->save();
        }
    }

    protected function awardTicketsForOrder(?User $user, Order $order, $finalTotal): void
    {
        app(TicketRewardService::class)->awardForCompletedOrder(
            $user,
            $order,
            (float) $finalTotal
        );
    }

    protected function generateTicketCode(): string
    {
        do {
            $code = 'TK' . now()->format('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (Ticket::query()->where('ticket_code', $code)->exists());

        return $code;
    }

    protected function generateOrderCode(): string
    {
        do {
            $code = 'OD' . now()->format('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        } while (Order::query()->where('order_code', $code)->exists());

        return $code;
    }


    private function getDownlines($userId, $maxDepth = 3)
    {
        $downlines = collect();
        $currentLevel = [$userId];

        for ($i = 0; $i < $maxDepth; $i++) {
            $nextLevel = User::whereIn('referrer_id', $currentLevel)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('orders')
                        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                        ->join('products', 'order_items.product_id', '=', 'products.id')
                        ->whereColumn('orders.user_id', 'users.id')
                        ->where('orders.status', 'completed')
                        ->where('products.category_id', 2)
                        ->where('products.sale_price', '>=', 1000000);
                })
                ->pluck('id');

            if ($nextLevel->isEmpty()) break;

            $downlines = $downlines->merge($nextLevel);
            $currentLevel = $nextLevel->all();
        }

        return $downlines->unique()->values();
    }

    private function hasBoughtCombo($userId)
    {
        return DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.user_id', $userId)
            ->where('orders.status', 'completed')
            ->where('products.category_id', 2)
            ->where('products.sale_price', '>=', 1000000)
            ->exists();
    }

    private function hasBoughtSingleProduct($userId)
    {
        return DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.user_id', $userId)
            ->where('orders.status', 'completed')
            ->where('products.category_id', 2)
            ->where('products.sale_price', '>=', 1000000)
            ->exists();
    }

    public function callback(Request $request)
    {
        $vnp_HashSecret = '2J57J7TFGX8K25CXTREYFWFQGG9OXKR0';
        $orderId = $request->input('vnp_TxnRef');
        $order = $orderId ? Order::find($orderId) : null;
        $amount = (float) ($request->input('vnp_Amount') ? ((int) $request->input('vnp_Amount')) / 100 : 0);
        $inputData = $request->all();
        $vnp_SecureHash = $inputData['vnp_SecureHash'] ?? null;
        unset($inputData['vnp_SecureHash']);

        ksort($inputData);

        $hashData = '';
        foreach ($inputData as $key => $value) {
            $hashData .= urlencode($key) . "=" . urlencode($value) . '&';
        }
        $hashData = rtrim($hashData, '&');
        $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        $queryParams = [
            'success' => 'false',
            'message' => 'Lỗi xác thực VNPay',
        ];

        if ($secureHash === $vnp_SecureHash) {
            if (trim($request->input('vnp_ResponseCode')) === '00') {
                if ($order && $order->total == $amount) {
                    if ($order->status_payment !== 'paid') {
                        $order->update([
                            'status_payment' => 'paid',
                            'payment_method' => 'VNPAY',
                        ]);
                    }
                    $queryParams = [
                        'success' => 'true',
                        'message' => 'Thanh toán thành công!',
                        'order_id' => $order->id,
                    ];
                } else {
                    $queryParams['message'] = 'Số tiền không khớp hoặc đơn hàng không còn hợp lệ.';
                }
            } else {
                $queryParams['message'] = 'Thanh toán thất bại!';
            }
        }
    }

    public function checkout(Request $request)
    {
        try {
            $vnp_TmnCode = '3XN2ER8H'; // Thay bằng mã của bạn
            $vnp_HashSecret = '2J57J7TFGX8K25CXTREYFWFQGG9OXKR0'; // Thay bằng mã của bạn
            $vnp_Url = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';
            // $vnp_Url = 'https://pay.vnpay.vn/vpcpay.html';
            $vnp_Returnurl = route('vnpay.return');
            $vnp_IpAddr = $request->ip();
            $vnp_Locale = $request->input('vnp_Locale', 'vn');

            $vnp_TxnRef = $request->id;
            $vnp_OrderInfo = "Thanh toán hóa đơn dịch vụ";
            $vnp_Amount = $request->total * 100; // Tính theo đơn vị VND
            $inputData = [
                "vnp_TmnCode" => $vnp_TmnCode,
                "vnp_Amount" => $vnp_Amount,
                "vnp_Command" => "pay",
                "vnp_CreateDate" => now()->format('YmdHis'),
                "vnp_CurrCode" => "VND",
                "vnp_IpAddr" => $vnp_IpAddr,
                "vnp_Locale" => $vnp_Locale,
                "vnp_OrderInfo" => $vnp_OrderInfo,
                "vnp_OrderType" => "billpayment",
                "vnp_ReturnUrl" => $vnp_Returnurl,
                "vnp_TxnRef" => $vnp_TxnRef,
            ];

            ksort($inputData);

            // 1. Tạo hashData (KHÔNG ENCODE)
            $hashData = '';
            foreach ($inputData as $key => $value) {
                $hashData .= $key . '=' . $value . '&';
            }
            $hashData = rtrim($hashData, '&');

            // 2. Tạo query (CÓ ENCODE)
            $query = http_build_query($inputData);

            // 3. Tạo mã chữ ký
            $vnp_SecureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

            // 4. Tạo URL cuối cùng
            $vnp_Url = $vnp_Url . '?' . $query . '&vnp_SecureHash=' . $vnp_SecureHash;

            // Return the payment URL
            return response()->json([
                'success' => true,
                'payment_url' => $vnp_Url
            ]);
        } catch (\Exception $e) {
            // DB::rollBack();
            // Log::error('Đặt hàng thất bại: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function agencyApi(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $userSale = Order::where('referrer_id', $user->id)->sum('total');
        // $userSale = CommissionUser::where('user_id', $user->id)->where('agency_rights',true)->sum('amount');

        // $commission_pending = DB::table('order_items')
        //     ->join('orders', 'order_items.order_id', '=', 'orders.id')
        //     ->where('order_items.referrer_id', $user->id)
        //     ->whereNotIn('orders.status', ['completed', 'cancelled'])
        //     ->sum('order_items.commission_amount');

        // $commission_completed = DB::table('order_items')
        //     ->join('orders', 'order_items.order_id', '=', 'orders.id')
        //     ->where('order_items.referrer_id', $user->id)
        //     ->where('orders.status', 'completed')
        //     ->sum('order_items.commission_amount');

        $commission_pending = CommissionUser::where('status', 'pending')->where('user_id', $user->id)->sum('amount');
        $commission_completed = CommissionUser::where('status', 'paid')->where('user_id', $user->id)->sum('amount');

        $count_order_completed = Order::where('referrer_id', $user->id)
            ->where('status', 'completed')
            ->count();
        // Lấy tổng referrals cấp dưới F1 + F2
        $allReferrals = collect();
        $currentLevelUsers = collect([$user]);

        $depth = 2;
        for ($i = 0; $i < $depth; $i++) {
            $currentLevelUserIds = $currentLevelUsers->pluck('id');
            $nextLevelUsers = User::whereIn('referrer_id', $currentLevelUserIds)->get();

            $allReferrals = $allReferrals->merge($nextLevelUsers);
            $currentLevelUsers = $nextLevelUsers;

            if ($currentLevelUsers->isEmpty()) break;
        }

        // Thêm bản thân vào danh sách
        $allReferrals = $allReferrals->push($user)->unique('id');

        // Đếm số lượng
        $count_user_referrer = $allReferrals->count();

        // Lấy ID để tính doanh số
        $teamUserIds = $allReferrals->pluck('id');

        $teamSales = Order::where(function ($query) use ($teamUserIds) {
            $query->whereIn('user_id', $teamUserIds)
                ->orWhereIn('referrer_id', $teamUserIds);
        })->sum('total');

        // $teamSales = Order::whereIn('referrer_id', $teamUserIds)->sum('total');
        // Đếm số lượng
        $count_user_referrer = $allReferrals->count();
        // $count_user_referrer = Order::whereNotNull('referrer_id')
        //     ->distinct('referrer_id')
        //     ->count('referrer_id');
        $banners = banner::where('position', 1)->where('status', 1)->get();
        return response()->json([
            'user' => $user,
            'teamSales' => $teamSales,
            'userSale' => $userSale,
            'commission_pending' => $commission_pending,
            'commission_completed' => $commission_completed,
            'count_order_completed' => $count_order_completed,
            'count_user_referrer' => $count_user_referrer,
            'banners' => $banners,
        ]);
    }

    public function topCommissionEarners(Request $request)
    {
        $leaderboard = Cache::remember('commission_top10', now()->addDays(3), function () {
            return User::query()
                ->select([
                    'users.id as user_id',
                    'users.name',
                    'users.full_name',
                    'users.avatar',
                    'users.phone',
                    'users.email',
                    DB::raw('SUM(commission_users.amount) as total_income'),
                ])
                ->join('commission_users', 'commission_users.user_id', '=', 'users.id')
                ->where('commission_users.status', '=', 'paid')
                ->groupBy('users.id', 'users.name', 'users.full_name', 'users.avatar', 'users.phone', 'users.email')
                ->orderByDesc('total_income')
                ->limit(10)
                ->get()
                ->map(function ($row) {
                    return [
                        'user_id' => $row->user_id,
                        'name' => $row->full_name ?: $row->name,
                        'avatar' => $row->avatar,
                        'phone' => $row->phone,
                        'email' => $row->email,
                        'total_income' => (int) $row->total_income,
                    ];
                });
        });

        return response()->json([
            'success' => true,
            'data' => $leaderboard,
        ]);
    }

    public function commissionHistory(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $status = strtolower((string) $request->query('status', 'all'));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $commissionService = app(\App\Services\AffiliateCommissionService::class);
        if ($commissionService->hasCommissionTable()) {
            return response()->json([
                'success' => true,
                'data' => $commissionService->paginateAffiliateCommissionHistory((int) $user->id, $status, $page, $perPage),
            ]);
        }

        $query = CommissionUser::query()
            ->select([
                'commission_users.id as commission_id',
                'commission_users.amount',
                'commission_users.level',
                'commission_users.status',
                'commission_users.created_at as commission_created_at',
                'order_items.id as order_item_id',
                'order_items.product_id',
                'order_items.item_name as product_name',
                'order_items.quantity',
                'order_items.unit_price',
                'order_items.total_price',
                'orders.id as order_id',
                'orders.order_code',
                'orders.status as order_status',
                'orders.created_at as order_created_at',
                'users.id as buyer_id',
                DB::raw("COALESCE(users.full_name, users.name, '') as buyer_name"),
            ])
            ->leftJoin('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->where('commission_users.user_id', $user->id)
            ->when(in_array($status, ['pending', 'paid', 'cancel'], true), function ($query) use ($status) {
                $query->where('commission_users.status', $status);
            })
            ->orderByDesc('commission_users.created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $historyItems = collect($paginator->items())->map(function ($commission) {
            return [
                'commission_id' => $commission->commission_id,
                'amount' => (float) $commission->amount,
                'level' => (int) $commission->level,
                'status' => $commission->status,
                'created_at' => optional($commission->commission_created_at)->toDateTimeString(),
                'order_item_id' => $commission->order_item_id,
                'product_id' => $commission->product_id,
                'product_name' => $commission->product_name,
                'quantity' => $commission->quantity,
                'unit_price' => isset($commission->unit_price) ? (float) $commission->unit_price : null,
                'total_price' => isset($commission->total_price) ? (float) $commission->total_price : null,
                'order_id' => $commission->order_id,
                'order_code' => $commission->order_code,
                'order_status' => $commission->order_status,
                'order_created_at' => optional($commission->order_created_at)->toDateTimeString(),
                'buyer_id' => $commission->buyer_id,
                'buyer_name' => $commission->buyer_name,
            ];
        });

        $grouped = $historyItems->groupBy('order_id')->map(function ($rows, $orderId) {
            return [
                'order_id' => $orderId,
                'order_code' => $rows->first()['order_code'] ?? null,
                'order_status' => $rows->first()['order_status'] ?? null,
                'order_created_at' => $rows->first()['order_created_at'] ?? null,
                'buyer_id' => $rows->first()['buyer_id'] ?? null,
                'buyer_name' => $rows->first()['buyer_name'] ?? null,
                'commissions' => $rows->map(function ($row) {
                    return array_filter($row, function ($value, $key) {
                        return ! in_array($key, ['order_id', 'order_code', 'order_status', 'order_created_at', 'buyer_id', 'buyer_name'], true);
                    }, ARRAY_FILTER_USE_BOTH);
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'history' => $grouped,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function commissionSummary(Request $request)
    {
        return $this->commissionDashboard($request);
    }

    public function commissionDashboard(Request $request)
    {
        $user = $this->resolveCommissionUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $commissionService = app(\App\Services\AffiliateCommissionService::class);
        if ($commissionService->hasCommissionTable()) {
            return response()->json([
                'success' => true,
                'data' => $commissionService->getDashboardSummary((int) $user->id),
            ]);
        }

        $commissionPending = (float) CommissionUser::where('user_id', $user->id)
            ->where('status', 'pending')
            ->sum('amount');

        $commissionWithdrawn = (float) WithdrawRequest::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'completed'])
            ->sum('amount');

        $availableBalance = (float) ($user->balance ?? 0);
        $referralCount = User::where('referrer_id', $user->id)->count();
        $referralOrderCount = Order::where('referrer_id', $user->id)->count();
        $referralSales = (float) Order::where('referrer_id', $user->id)
            ->whereNotNull('user_id')
            ->sum(DB::raw('COALESCE(final_amount, total_amount, total, 0)'));

        return response()->json([
            'success' => true,
            'data' => [
                'commission_pending' => $commissionPending,
                'commission_withdrawn' => $commissionWithdrawn,
                'available_balance' => $availableBalance,
                'commission_receivable' => $availableBalance,
                'referral_count' => $referralCount,
                'referral_order_count' => $referralOrderCount,
                'referral_sales' => $referralSales,
            ],
        ]);
    }

    public function commissionProfile(Request $request)
    {
        $user = $this->resolveCommissionUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $bankAccounts = BankAccount::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($bankAccount) {
                return [
                    'id' => $bankAccount->id,
                    'account_name' => $bankAccount->account_name,
                    'account_number' => $bankAccount->account_number,
                    'bank_name' => $bankAccount->bank_name,
                    'image' => $bankAccount->image,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'full_name' => $user->full_name ?: $user->name,
                'avatar' => $user->avatar ?? null,
                'phone' => $user->phone ?? null,
                'email' => $user->email ?? null,
                'balance' => (float) ($user->balance ?? 0),
                'bank_accounts' => $bankAccounts,
            ],
        ]);
    }

    public function commissionReferrals(Request $request)
    {
        $user = $this->resolveCommissionUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('page_size', $request->query('per_page', 20))));
        $from = $request->query('from');
        $to = $request->query('to');

        $query = User::query()
            ->where('users.referrer_id', $user->id)
            ->leftJoin('orders', 'orders.user_id', '=', 'users.id')
            ->when($from, function ($query) use ($from) {
                $query->where('orders.created_at', '>=', Carbon::parse($from)->startOfDay());
            })
            ->when($to, function ($query) use ($to) {
                $query->where('orders.created_at', '<=', Carbon::parse($to)->endOfDay());
            })
            ->select([
                'users.id',
                'users.name',
                'users.full_name',
                'users.avatar',
                'users.phone',
                'users.email',
                'users.created_at',
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(COALESCE(orders.final_amount, orders.total_amount, orders.total, 0)), 0) as total_order_value'),
                DB::raw('MAX(orders.created_at) as last_order_at'),
            ])
            ->groupBy('users.id', 'users.name', 'users.full_name', 'users.avatar', 'users.phone', 'users.email', 'users.created_at')
            ->orderByDesc('users.created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($row) {
            return [
                'id' => $row->id,
                'full_name' => $row->full_name ?: $row->name,
                'avatar' => $row->avatar ?? null,
                'phone' => $this->maskPhone($row->phone ?? null),
                'email' => $row->email ?? null,
                'joined_at' => optional($row->created_at)->toIso8601String(),
                'total_orders' => (int) ($row->total_orders ?? 0),
                'total_order_value' => (float) ($row->total_order_value ?? 0),
                'last_order_at' => optional($row->last_order_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function commissionReferralOrders(Request $request)
    {
        $user = $this->resolveCommissionUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('page_size', $request->query('per_page', 20))));
        $from = $request->query('from');
        $to = $request->query('to');

        $query = Order::query()
            ->where(function ($query) use ($user) {
                $query
                    ->whereExists(function ($buyerQuery) use ($user) {
                        $buyerQuery
                            ->selectRaw('1')
                            ->from('users as direct_referral_users')
                            ->whereColumn('direct_referral_users.id', 'orders.user_id')
                            ->where('direct_referral_users.referrer_id', (int) $user->id);
                    })
                    ->orWhere(function ($guestQuery) use ($user) {
                        $guestQuery
                            ->whereNull('orders.user_id')
                            ->where('orders.referrer_id', $user->id);
                    });
            })
            ->when($from, function ($query) use ($from) {
                $query->where('orders.created_at', '>=', Carbon::parse($from)->startOfDay());
            })
            ->when($to, function ($query) use ($to) {
                $query->where('orders.created_at', '<=', Carbon::parse($to)->endOfDay());
            })
            ->leftJoin('users as buyer', 'buyer.id', '=', 'orders.user_id')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('commission_users', function ($join) use ($user) {
                $join->on('commission_users.order_item_id', '=', 'order_items.id')
                    ->where('commission_users.user_id', '=', $user->id);
            })
            ->select([
                'orders.id as order_id',
                'orders.order_code',
                'orders.status',
                'orders.created_at as order_created_at',
                DB::raw('MAX(buyer.id) as buyer_id'),
                DB::raw('MAX(COALESCE(buyer.full_name, buyer.name, "")) as buyer_name'),
                DB::raw('MAX(buyer.phone) as buyer_phone'),
                DB::raw('MAX(buyer.avatar) as buyer_avatar'),
                DB::raw('MAX(COALESCE(orders.final_amount, orders.total_amount, orders.total, 0)) as total_amount'),
                DB::raw('SUM(COALESCE(commission_users.amount, 0)) as commission_amount'),
                DB::raw('COUNT(DISTINCT order_items.id) as item_count'),
            ])
            ->groupBy('orders.id', 'orders.order_code', 'orders.status', 'orders.created_at')
            ->orderByDesc('orders.created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($row) {
            return [
                'order_id' => $row->order_id,
                'order_code' => $row->order_code,
                'status' => $row->status,
                'order_created_at' => optional($row->order_created_at)->toIso8601String(),
                'buyer' => [
                    'id' => $row->buyer_id,
                    'name' => $row->buyer_name ?: null,
                    'avatar' => $row->buyer_avatar ?? null,
                    'phone' => $this->maskPhone($row->buyer_phone ?? null),
                ],
                'total_amount' => (float) ($row->total_amount ?? 0),
                'commission_amount' => (float) ($row->commission_amount ?? 0),
                'item_count' => (int) ($row->item_count ?? 0),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function commissionDailyChart(CommissionDailyChartRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::today()->subDays(29)->startOfDay();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::today()->endOfDay();

        $commissionService = app(\App\Services\AffiliateCommissionService::class);
        if ($commissionService->hasCommissionTable()) {
            return response()->json([
                'success' => true,
                'data' => $commissionService->commissionChart((int) $user->id, $start, $end),
            ]);
        }

        $commissionRows = CommissionUser::query()
            ->selectRaw('DATE(commission_users.created_at) as date')
            ->selectRaw('SUM(COALESCE(commission_users.amount, 0)) as commission')
            ->selectRaw('SUM(COALESCE(order_items.total_price, COALESCE(order_items.unit_price, 0) * COALESCE(order_items.quantity, 0), 0)) as revenue')
            ->selectRaw('COUNT(DISTINCT orders.id) as total_orders')
            ->selectRaw('COUNT(DISTINCT orders.user_id) as buyer_count')
            ->leftJoin('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('commission_users.user_id', $user->id)
            ->whereBetween('commission_users.created_at', [$start, $end])
            ->groupBy(DB::raw('DATE(commission_users.created_at)'))
            ->get()
            ->keyBy('date');

        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'commission_rows' => $commissionRows->values(),
            ],
        ]);

        $rows = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateString = $cursor->toDateString();
            $commission = $commissionRows->get($dateString);

            $rows[] = [
                'date' => $dateString,
                'revenue' => $commission ? (float) $commission->revenue : 0.0,
                'total_orders' => $commission ? (int) $commission->total_orders : 0,
                'commission' => $commission ? (float) $commission->commission : 0.0,
                'buyer_count' => $commission ? (int) $commission->buyer_count : 0,
            ];

            $cursor->addDay();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'rows' => $rows,
            ],
        ]);
    }

    public function commissionPayouts(Request $request)
    {
        $user = $this->resolveCommissionUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $data = app(\App\Services\AffiliatePayoutService::class)->paginateAffiliatePayouts((int) $user->id, $page, $perPage);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function commissionPayoutDetail(Request $request, int $batchId)
    {
        $user = $this->resolveCommissionUser($request);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền truy cập',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => app(\App\Services\AffiliatePayoutService::class)->getAffiliatePayoutDetail((int) $user->id, $batchId),
        ]);
    }

    public function cancel(Request $request, Order $order)
    {
        $customer = $this->resolveApiUser($request);

        if (!$customer || (string) $order->user_id !== (string) $customer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền hủy đơn hàng này.',
            ], 403);
        }

        $currentStatus = strtolower(trim((string) ($order->status ?? '')));
        if ($currentStatus !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể hủy đơn hàng khi đang ở trạng thái pending.',
                'data' => [
                    'current_status' => $currentStatus,
                ],
            ], 400);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($reason === '') {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng nhập lý do hủy đơn.',
            ], 422);
        }

        $cancelledAt = now();
        $customerName = trim((string) ($order->name ?? $customer->name ?? 'Khách hàng'));

        DB::beginTransaction();
        try {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $currentStatus = strtolower(trim((string) ($order->status ?? '')));
            if ($currentStatus !== 'pending') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Chỉ có thể hủy đơn hàng khi đang ở trạng thái pending.',
                    'data' => [
                        'current_status' => $currentStatus,
                    ],
                ], 400);
            }

            app(OrderStockRestorationService::class)->restoreForCancellation($order, $currentStatus);

            // Cập nhật trạng thái đơn
            $oldStatus = (string) ($order->status ?? 'pending');
            $order->status = 'canceled';
            if (Schema::hasColumn($order->getTable(), 'cancel_reason')) {
                $reasonCode = strtoupper(trim((string) ($request->input('reason_code') ?? '')));
                if ($reasonCode !== '') {
                    $order->cancel_reason = '[' . $reasonCode . '] - ' . $reason;
                } else {
                    $order->cancel_reason = $reason;
                }
            }
            if (Schema::hasColumn($order->getTable(), 'cancelled_at')) {
                $order->cancelled_at = $cancelledAt;
            }
            if (Schema::hasColumn($order->getTable(), 'cancelled_by')) {
                $order->cancelled_by = $customer->id;
            }
            if (Schema::hasColumn($order->getTable(), 'updated_at')) {
                $order->updated_at = $cancelledAt;
            }

            if ($order->payment_method === 'VNPAY' && $order->status_payment === 'paid') {
                $refundedUser = User::find($order->user_id);
                if ($refundedUser) {
                    $refundedUser->balance += $order->total;
                    $refundedUser->save();

                    $order->status_payment = 'refunded';
                }
            }

            $order->save();

            if (Schema::hasTable('order_status_history')) {
                $historyPayload = [];

                if (Schema::hasColumn('order_status_history', 'id')) {
                    $historyPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('order_status_history', 'order_id')) {
                    $historyPayload['order_id'] = (string) $order->id;
                }
                if (Schema::hasColumn('order_status_history', 'old_status')) {
                    $historyPayload['old_status'] = $oldStatus;
                }
                if (Schema::hasColumn('order_status_history', 'new_status')) {
                    $historyPayload['new_status'] = 'canceled';
                }
                if (Schema::hasColumn('order_status_history', 'changed_by')) {
                    $historyPayload['changed_by'] = $customer->id;
                }
                if (Schema::hasColumn('order_status_history', 'changed_by_role')) {
                    $historyPayload['changed_by_role'] = 'customer';
                }
                if (Schema::hasColumn('order_status_history', 'reason')) {
                    $historyPayload['reason'] = $reason;
                }
                if (Schema::hasColumn('order_status_history', 'created_at')) {
                    $historyPayload['created_at'] = $cancelledAt;
                }
                if (Schema::hasColumn('order_status_history', 'updated_at')) {
                    $historyPayload['updated_at'] = $cancelledAt;
                }

                if (!empty($historyPayload)) {
                    DB::table('order_status_history')->insert($historyPayload);
                }
            }

            $orderCode = trim((string) ($order->order_code ?? '')) ?: null;

            if (Schema::hasTable('store_admin_notifications') && !empty($order->store_id)) {
                StoreAdminNotification::query()->create([
                    'id' => (string) Str::uuid(),
                    'store_id' => (string) $order->store_id,
                    'order_id' => (string) $order->id,
                    'order_code' => $orderCode,
                    'type' => 'order_canceled',
                    'title' => 'Khách hàng hủy đơn',
                    'message' => 'Đơn #' . ($orderCode ?? (string) $order->id) . ' vừa bị khách hàng hủy. Lý do: ' . $reason,
                    'data' => [
                        'store_id' => (string) $order->store_id,
                        'order_id' => (string) $order->id,
                        'order_code' => $orderCode,
                        'reason' => $reason,
                        'customer_name' => $customerName,
                        'canceled_at' => $cancelledAt->toIso8601String(),
                        'status' => 'canceled',
                    ],
                    'is_read' => false,
                    'read_at' => null,
                ]);
            }

            DB::commit();

            try {
                event(new OrderStatusUpdated(
                    (string) $order->id,
                    $oldStatus,
                    'canceled',
                    (string) ($order->store_id ?? ''),
                    [
                        'id' => (string) $order->id,
                        'order_code' => $orderCode,
                        'status' => 'canceled',
                        'cancel_reason' => $reason,
                        'cancelled_at' => $cancelledAt->toIso8601String(),
                        'cancelled_by' => $customer->id,
                    ]
                ));
            } catch (\Throwable $broadcastError) {
                Log::warning('Order status update broadcast failed after customer cancellation.', [
                    'order_id' => (string) $order->id,
                    'store_id' => (string) ($order->store_id ?? ''),
                    'error' => $broadcastError->getMessage(),
                ]);
            }

            try {
                event(new CustomerCanceledOrderEvent(
                    (string) ($order->store_id ?? ''),
                    (string) $order->id,
                    $orderCode,
                    $reason,
                    $customerName,
                    $cancelledAt->toIso8601String()
                ));
            } catch (\Throwable $broadcastError) {
                Log::warning('Customer canceled order broadcast failed.', [
                    'order_id' => (string) $order->id,
                    'store_id' => (string) ($order->store_id ?? ''),
                    'error' => $broadcastError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Đơn hàng đã bị hủy thành công và trả lại tồn kho.',
                'data' => [
                    'id' => (string) $order->id,
                    'order_code' => $order->order_code ?? null,
                    'status' => 'canceled',
                    'cancel_reason' => $reason,
                    'cancelled_at' => $cancelledAt->toIso8601String(),
                    'cancelled_by' => $customer->id,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Hủy đơn không thành công, vui lòng thử lại.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendMessage(Request $request)
    {
        $accessToken = env('ZALO_OA_ACCESS_TOKEN');
        $miniappToken = $request->input('miniapp_messsage_token');
        $userId = $request->input('user_id');

        $response = Http::withHeaders([
            'access_token' => $accessToken,
            'miniapp_messsage_token' => $miniappToken,
            'Content-Type' => 'application/json',
        ])->post('https://openapi.zalo.me/v3.0/oa/message/cs/miniapp', [
            'recipient' => [
                'user_id' => $userId,
            ],
            'message' => [
                'template_type' => 'FB0001',
                'template_data' => [
                    'customer_name' => 'Tặng Nguyễn',
                    'queue_number' => '12',
                    'note' => 'Khách hàng thanh toán bằng thẻ',
                    'buttons' => [
                        [
                            'title' => 'Chi tiết đơn hàng',
                            'image_icon' => 'https://stc-zmp.zadn.vn/oa/basket.png',
                            'url' => 'https://zalo.me/s/194839900003483517/',
                        ],
                        [
                            'title' => 'Đánh giá',
                            'image_icon' => 'https://stc-zmp.zadn.vn/oa/star.png',
                            'url' => 'https://zalo.me/s/194839900003483517/',
                        ],
                    ],
                ],
            ],
        ]);

        return response()->json($response->json(), $response->status());
    }
    public function updateStatusPayment(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Payment status updates must use a verified payment callback.',
            'errors' => [[
                'code' => 'MANUAL_PAYMENT_STATUS_UPDATE_DISABLED',
                'message' => 'Manual payment status update is disabled.',
            ]],
        ], 403);

        $validated = $request->validate([
            'order_id' => 'required',
            'transaction_id' => 'nullable|string',
            'payment_method' => 'nullable|string',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        $resolvedPaymentMethod = isset($validated['payment_method'])
            ? (string) $validated['payment_method']
            : (string) ($order->payment_method ?? 'BANK_TRANSFER');

        $this->settleOrderAsPaid(
            $order,
            $resolvedPaymentMethod,
            isset($validated['transaction_id']) ? (string) $validated['transaction_id'] : null
        );

        return response()->json(['message' => 'Cập nhật trạng thái thành công']);
    }

    public function indexOrder(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json(['message' => 'Không có quyền truy cập'], 401);
        }

        $status = strtolower((string) $request->query('status', 'all'));
        $parentOrderId = trim((string) $request->query('parent_order_id', ''));

        $statusGroups = [
            'pending' => ['pending', 'pending_payment', 'paid_pending_call', 'partially_called', 'call_completed'],
            'preparing' => ['confirmed', 'approved', 'preparing', 'packed', 'ready', 'shipped'],
            'delivered' => ['delivered', 'completed'],
            // Backward-compatible keys used by old frontend tabs.
            'shipped' => ['shipped'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];

        // If fetching child orders, first verify user owns the parent order
        if ($parentOrderId !== '') {
            $parentOrder = Order::where('id', $parentOrderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$parentOrder) {
                return response()->json([], 200, [], JSON_UNESCAPED_UNICODE);
            }
        }

        // Build query
        $relations = ['items.product.store:id,name', 'store:id,name,address', 'user:id,name,full_name,phone'];
        if ($this->hasDatabaseTable('order_returns')) {
            $relations['returns'] = fn ($query) => $query
                ->select([
                    'id',
                    'order_id',
                    'status',
                    'return_method',
                    'return_code',
                    'refund_amount_estimated',
                    'refund_amount_final',
                    'created_at',
                ])
                ->orderByDesc('created_at');
        }

        $query = Order::with($relations);

        // Filter by user_id if NOT fetching child orders by parent_order_id
        if ($parentOrderId === '') {
            $query->where('user_id', $user->id);
        } else {
            // Fetch child orders (user already verified as owner of parent)
            $query->where('parent_order_id', $parentOrderId);
        }

        // Apply status filter if provided
        if ($status !== '' && $status !== 'all') {
            if (array_key_exists($status, $statusGroups)) {
                $query->whereIn('status', $statusGroups[$status]);
            } else {
                $query->where('status', $status);
            }
        }

        $orders = $query->orderByDesc('created_at')->get();

        $orders->each(function (Order $order): void {
            $this->normalizeOrderSnapshot($order);
        });

        return response()->json($orders->toArray(), 200, [], JSON_UNESCAPED_UNICODE);
    }


    // Lấy chi tiết một mặt đơn hàng có thể thấy
    public function showOrder($id, Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json(['message' => 'Không có quyền truy cập'], 401);
        }

        $order = Order::with(['items', 'store:id,name,address'])
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);
        }

        $order->setAttribute('store_name', (string) ($order->store?->name ?? ''));

        return response()->json($order->toArray(), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function authenticatedUser(Request $request)
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->isActiveForAuth()) {
            return response()->json([
                'message' => 'Tai khoan dang bi khoa hoac khong con hoat dong.',
            ], 403);
        }

        return $user;
    }

    private function serializeDrawRoomPayload(Room $room): array
    {
        $participantRows = RoomTicket::query()
            ->select('user_id', DB::raw('COUNT(*) as ticket_count'), DB::raw('MIN(joined_at) as first_joined_at'))
            ->where('room_id', $room->id)
            ->where('is_refunded', false)
            ->groupBy('user_id')
            ->orderBy('first_joined_at')
            ->get();

        $users = User::query()
            ->whereIn('id', $participantRows->pluck('user_id')->unique()->all())
            ->get()
            ->keyBy(fn(User $user) => (string) $user->id);

        $participants = $participantRows->map(function ($row) use ($users) {
            $userId = (string) $row->user_id;
            $user = $users->get($userId);

            $displayName = trim((string) ($user?->name ?? $user?->full_name ?? ''));
            if ($displayName === '') {
                $displayName = 'Người chơi ' . substr($userId, 0, 6);
            }

            $avatarUrl = trim((string) ($user?->avatar_url ?? $user?->avatar ?? ''));
            if ($avatarUrl === '') {
                $avatarUrl = 'https://i.pravatar.cc/150?u=' . urlencode($userId);
            }

            return [
                'userId' => $userId,
                'displayName' => $displayName,
                'avatarUrl' => $avatarUrl,
                'ticketCount' => (int) $row->ticket_count,
                'joinedAt' => optional($row->first_joined_at)->toIso8601String() ?: now()->toIso8601String(),
            ];
        })->values()->all();

        return [
            'id' => (string) $room->id,
            'name' => sprintf('Phòng %02d hôm nay', (int) $room->room_number),
            'description' => 'Phòng quay hôm nay, mỗi phòng cần 1000 vé để mở kết quả.',
            'maxTickets' => max(1, (int) ($room->required_votes ?: 1000)),
            'currentTickets' => (int) $room->current_votes,
            'prizeDescription' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
            'prizeValue' => (float) ($room->prize_value ?: 18000000),
            'status' => strtolower((string) $room->status),
            'participants' => $participants,
            'createdAt' => optional($room->created_at)->toIso8601String(),
            'openAt' => optional($room->open_at)->toIso8601String(),
            'closeAt' => optional($room->close_at)->toIso8601String(),
            'scheduledDrawAt' => optional($room->draw_started_at ?? $room->created_at)
                ->setTimezone('Asia/Ho_Chi_Minh')
                ->toIso8601String(),
        ];
    }

    protected function serializeNotificationPayload(Notification $notification): array
    {
        $title = (string) ($notification->getAttribute('title') ?: 'Thông báo');
        $body = (string) ($notification->getAttribute('body') ?: '');
        $type = (string) ($notification->getAttribute('type') ?: 'system');
        $isRead = (bool) $notification->getAttribute('is_read');
        $actionUrl = $notification->getAttribute('action_url');

        $createdAt = $notification->created_at
            ? $notification->created_at->toIso8601String()
            : now()->toIso8601String();

        return [
            'id' => (string) $notification->id,
            'user_id' => $notification->getAttribute('user_id'),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'message' => (string) ($notification->message ?? $body),
            'is_read' => $isRead,
            'created_at' => $createdAt,
            'action_url' => $actionUrl,
        ];
    }
}
