<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
use App\Models\OrderItem;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PreOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMenu;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\Voucher;
use App\Services\FlashSaleService;
use App\Services\TicketLedgerService;
use App\Services\TicketLockService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Exceptions\FlashSaleUserLimitExceededException;

class ClientOrderController extends ClientController
{
    private array $orderProductReferenceCache = [];
    private array $orderVariantReferenceCache = [];

    public function place(Request $request)
    {
        return parent::place($request);
    }

    public function preCreate(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'type' => 'required|string|in:normal,menu,ticket',
            'payload' => 'required|array',
        ]);

        $blockedStoreIds = $this->resolveBlockedOwnStoreIds(
            $user,
            $this->resolvePreCreateTargetStoreIds($validated['payload'], $validated['type'])
        );

        if (!empty($blockedStoreIds)) {
            return $this->ownStoreOrderForbiddenResponse($blockedStoreIds);
        }

        if ($validated['type'] === 'normal') {
            $this->assertCartProductsMenuAvailable(
                is_array($validated['payload']['cart'] ?? null) ? $validated['payload']['cart'] : [],
                'payload.cart'
            );
        }

        // Use the same logic as generateOrderCode but prefixed
        $preOrderCode = 'P' . substr(str_replace('-', '', (string) Str::uuid()), 0, 10);

        // Ensure uniqueness if needed, but for pre-orders a random code is usually enough
        $preOrder = PreOrder::create([
            'pre_order_code' => strtoupper($preOrderCode),
            'user_id' => $user->id,
            'amount' => $validated['amount'],
            'payload' => array_merge($validated['payload'], ['pre_order_type' => $validated['type']]),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'pre_order_code' => $preOrder->pre_order_code,
            'id' => $preOrder->id,
        ]);
    }

    protected function resolvePreCreateTargetStoreIds(array $payload, string $type): array
    {
        if (in_array($type, ['menu', 'ticket'], true)) {
            return $this->normalizeStoreIds([$payload['store_id'] ?? null]);
        }

        $cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        $productIds = collect($cart)
            ->map(static fn($item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0)
            ->filter(static fn(int $productId): bool => $productId > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($productIds)) {
            return [];
        }

        if (!Schema::hasColumn('products', 'store_id')) {
            return [];
        }

        return $this->normalizeStoreIds(
            $this->applyApprovedProductScope(Product::query())
                ->whereIn('id', $productIds)
                ->pluck('store_id')
                ->all()
        );
    }

    public function placeFromTicket(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'store_id' => 'required',
            'ticket_id' => 'required|uuid|exists:tickets,id',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
            'payment_method' => 'required|string|max:50',
            'referrer_id' => 'nullable|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required',
            'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'note' => 'nullable|string|max:500',
            'shipping_method' => 'nullable|string|max:50',
        ]);

        $blockedStoreIds = $this->resolveBlockedOwnStoreIds(
            $user,
            [$validated['store_id'] ?? null]
        );

        if (!empty($blockedStoreIds)) {
            return $this->ownStoreOrderForbiddenResponse($blockedStoreIds);
        }

        DB::beginTransaction();

        try {
            $flashSaleService = app(FlashSaleService::class);
            $ticketLockService = app(TicketLockService::class);
            $lockOwner = 'order:' . (string) $user->id . ':' . Str::random(12);
            $paymentMethod = strtoupper((string) ($validated['payment_method'] ?? ''));
            $isPendingPayment = $this->isPendingPaymentMethod($paymentMethod);
            $referrerId = $this->resolveAffiliateRecipientId(
                isset($validated['referrer_id']) ? User::resolveReferrerId($validated['referrer_id']) : null,
                (int) $user->id,
            );

            $hasOrderId = Schema::hasColumn('orders', 'id');
            $hasOrderCode = Schema::hasColumn('orders', 'order_code');
            $hasOrderUserId = Schema::hasColumn('orders', 'user_id');
            $hasOrderStoreId = Schema::hasColumn('orders', 'store_id');
            $hasOrderName = Schema::hasColumn('orders', 'name');
            $hasOrderPhone = Schema::hasColumn('orders', 'phone');
            $hasOrderAddress = Schema::hasColumn('orders', 'address');
            $hasOrderDeliveryPhone = Schema::hasColumn('orders', 'delivery_phone');
            $hasOrderDeliveryAddress = Schema::hasColumn('orders', 'delivery_address');
            $hasOrderPaymentMethod = Schema::hasColumn('orders', 'payment_method');
            $hasOrderStatusPayment = Schema::hasColumn('orders', 'status_payment');
            $hasOrderStatus = Schema::hasColumn('orders', 'status');
            $hasOrderTotal = Schema::hasColumn('orders', 'total');
            $hasOrderTotalAmount = Schema::hasColumn('orders', 'total_amount');
            $hasOrderFinalAmount = Schema::hasColumn('orders', 'final_amount');
            $hasOrderDeliveryNote = Schema::hasColumn('orders', 'delivery_note');
            $hasOrderTotalTicketsUsed = Schema::hasColumn('orders', 'total_tickets_used');
            $hasOrderParentOrderId = Schema::hasColumn('orders', 'parent_order_id');
            $hasOrderShippingMethod = Schema::hasColumn('orders', 'shipping_method');

            $hasOrderItemId = Schema::hasColumn('order_items', 'id');
            $hasOrderItemOrderId = Schema::hasColumn('order_items', 'order_id');
            $hasOrderItemProductId = Schema::hasColumn('order_items', 'product_id');
            $hasOrderItemVariantId = Schema::hasColumn('order_items', 'variant_id');
            $hasOrderItemProductName = Schema::hasColumn('order_items', 'product_name');
            $hasOrderItemThumbnail = Schema::hasColumn('order_items', 'thumbnail');
            $hasOrderItemPrice = Schema::hasColumn('order_items', 'price');
            $hasOrderItemMenuItemId = Schema::hasColumn('order_items', 'menu_item_id');
            $hasOrderItemItemName = Schema::hasColumn('order_items', 'item_name');
            $hasOrderItemQuantity = Schema::hasColumn('order_items', 'quantity');
            $hasOrderItemCalledQuantity = Schema::hasColumn('order_items', 'called_quantity');
            $hasOrderItemUnitPrice = Schema::hasColumn('order_items', 'unit_price');
            $hasOrderItemTotalPrice = Schema::hasColumn('order_items', 'total_price');
            $hasOrderItemSpecialInstructions = Schema::hasColumn('order_items', 'special_instructions');
            $hasOrderItemFlashSaleId = Schema::hasColumn('order_items', 'flash_sale_item_id');
            $hasOrderItemFlashSaleId = Schema::hasColumn('order_items', 'flash_sale_item_id');

            $deferCall = (bool) ($validated['defer_call'] ?? false);
            $shippingMethod = (string) ($validated['shipping_method'] ?? '');
            
            // Treat shipping_method: "later" as defer_call - don't notify admin until user calls
            if (strtolower($shippingMethod) === 'later') {
                $deferCall = true;
            }

            $store = Store::query()
                ->where('id', $validated['store_id'])
                ->first();

            if (!$store) {
                throw new \Exception('Cua hang khong ton tai.');
            }

            // Validate store is currently open
            $this->validateStoreOperatingStatus($store);

            $ticket = Ticket::query()
                ->where('id', $validated['ticket_id'])
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$ticket) {
                throw new \Exception('Vé không thuộc về tài khoản hiện tại.');
            }

            if (strtoupper((string) $ticket->status) !== 'AVAILABLE') {
                throw new \Exception('Vé không còn khả dụng để gọi món.');
            }

            $ticketLockService->acquire($ticket, $lockOwner, TicketLockService::DEFAULT_TTL_SECONDS);

            $orderPayload = [];

            if ($hasOrderId) {
                $orderPayload['id'] = (string) Str::uuid();
            }

            if ($hasOrderCode) {
                $orderPayload['order_code'] = $this->generateOrderCode();
            }

            if ($hasOrderUserId) {
                $orderPayload['user_id'] = (string) $user->id;
            }

            if ($hasOrderStoreId) {
                $orderPayload['store_id'] = $store->id;
            }

            if ($hasOrderName) {
                $orderPayload['name'] = $validated['name'];
            }

            if ($hasOrderPhone) {
                $orderPayload['phone'] = $validated['phone'];
            }

            if ($hasOrderAddress) {
                $orderPayload['address'] = $validated['address'] ?? '';
            }

            if ($hasOrderDeliveryPhone) {
                $orderPayload['delivery_phone'] = $validated['phone'];
            }

            if ($hasOrderDeliveryAddress) {
                $orderPayload['delivery_address'] = $validated['address'] ?? '';
            }

            if ($hasOrderPaymentMethod) {
                $orderPayload['payment_method'] = $validated['payment_method'];
            }

            if ($hasOrderStatusPayment) {
                $orderPayload['status_payment'] = $isPendingPayment ? 'pending' : 'paid';
            }

            if ($hasOrderStatus) {
                if ($isPendingPayment) {
                    $orderPayload['status'] = 'pending_payment';
                } else {
                    $orderPayload['status'] = $deferCall ? 'paid_pending_call' : 'pending';
                }
            }

            if ($hasOrderParentOrderId) {
                $orderPayload['parent_order_id'] = null;
            }

            if ($hasOrderTotal) {
                $orderPayload['total'] = 0;
            }

            if ($hasOrderTotalAmount) {
                $orderPayload['total_amount'] = 0;
            }

            if ($hasOrderFinalAmount) {
                $orderPayload['final_amount'] = 0;
            }

            if ($hasOrderDeliveryNote) {
                $orderPayload['delivery_note'] = $validated['note'] ?? null;
            }

            if ($hasOrderTotalTicketsUsed) {
                $orderPayload['total_tickets_used'] = 1;
            }

            if ($hasOrderShippingMethod) {
                $orderPayload['shipping_method'] = $validated['shipping_method'] ?? null;
            }

            if (Schema::hasColumn('orders', 'referrer_id')) {
                $orderPayload['referrer_id'] = $referrerId;
            }

            $order = Order::query()->create($orderPayload);

            $total = 0;
            $consumedCartLines = [];
            $menuItemsById = $this->loadAvailableMenuItemsForOrder($validated['items'], $store);

            foreach ($validated['items'] as $item) {
                $menuItem = $menuItemsById->get((string) $item['menu_item_id']);

                if (!$menuItem) {
                    throw new Exception('Món ăn không thuộc cửa hàng đã chọn hoặc đang tạm ngưng bán.');
                }

                $resolvedReference = $this->resolveMenuItemStockReference($menuItem);
                $mappedProductId = (int) ($resolvedReference['product_id'] ?? 0);
                $mappedVariantId = isset($resolvedReference['variant_id']) ? (int) $resolvedReference['variant_id'] : null;
                $resolvedItemName = (string) ($resolvedReference['item_name'] ?? (string) ($menuItem->item_name ?? ''));

                if (((int) ($menuItem->product_id ?? 0) > 0 || (int) ($menuItem->variant_id ?? 0) > 0) && $mappedProductId <= 0) {
                    throw new Exception('San pham gan voi mon an chua duoc duyet hoac khong kha dung.');
                }

                $quantity = (int) $item['quantity'];
                $this->assertAndMaybeDeductMappedStock(
                    $mappedProductId,
                    $mappedVariantId,
                    $quantity,
                    !$isPendingPayment
                );

                $basePrice = $this->resolveMenuItemBasePrice($menuItem, $resolvedReference);
                $unitPrice = $basePrice;
                if ($mappedProductId > 0) {
                    $pricing = $flashSaleService->consumeFlashSaleQuantity(
                        (string) $store->id,
                        $mappedProductId,
                        $mappedVariantId,
                        $quantity,
                        $basePrice,
                        (int) $user->id
                    );
                    $unitPrice = (float) ($pricing['price'] ?? $basePrice);
                }
                $lineTotal = $unitPrice * $quantity;
                $total += $lineTotal;

                if ($mappedProductId > 0 && $quantity > 0) {
                    $consumedCartLines[] = [
                        'product_id' => $mappedProductId,
                        'variant_id' => $mappedVariantId,
                        'quantity' => $quantity,
                    ];
                }

                $orderItemPayload = [];

                if ($hasOrderItemId) {
                    $orderItemPayload['id'] = (string) Str::uuid();
                }

                if ($hasOrderItemOrderId) {
                    $orderItemPayload['order_id'] = $order->id;
                }

                if ($hasOrderItemProductId) {
                    $orderItemPayload['product_id'] = $mappedProductId > 0 ? $mappedProductId : 0;
                }

                if ($hasOrderItemVariantId) {
                    $orderItemPayload['variant_id'] = $mappedVariantId;
                }

                if ($hasOrderItemProductName) {
                    $orderItemPayload['product_name'] = $resolvedItemName;
                }

                if ($hasOrderItemThumbnail) {
                    $orderItemPayload['thumbnail'] = $menuItem->image_url;
                }

                if ($hasOrderItemPrice) {
                    $orderItemPayload['price'] = $unitPrice;
                }

                if ($hasOrderItemMenuItemId) {
                    $orderItemPayload['menu_item_id'] = $menuItem->id;
                }

                if ($hasOrderItemItemName) {
                    $orderItemPayload['item_name'] = $resolvedItemName;
                }

                if ($hasOrderItemQuantity) {
                    $orderItemPayload['quantity'] = $quantity;
                }

                if ($hasOrderItemCalledQuantity) {
                    $orderItemPayload['called_quantity'] = $deferCall ? 0 : $quantity;
                }

                if ($hasOrderItemUnitPrice) {
                    $orderItemPayload['unit_price'] = $unitPrice;
                }

                if ($hasOrderItemTotalPrice) {
                    $orderItemPayload['total_price'] = $lineTotal;
                }

                if ($hasOrderItemFlashSaleId && isset($pricing['flash_sale']['flash_sale_item_id'])) {
                    $orderItemPayload['flash_sale_item_id'] = $pricing['flash_sale']['flash_sale_item_id'];
                }

                if ($hasOrderItemSpecialInstructions) {
                    $orderItemPayload['special_instructions'] = $validated['note'] ?? null;
                }

                $rewardPerUnit = $this->resolveTicketRewardQuantityFromReference($resolvedReference);
                $orderItemPayload['ticket_reward_quantity'] = $rewardPerUnit * $quantity;

                OrderItem::query()->create($orderItemPayload);
            }

            $ticketDiscount = (float) (($ticket->value_amount ?? $ticket->purchased_price) ?? config('ecoluck.ticket_unit_price', 25000));
            if ($ticketDiscount < 0) {
                $ticketDiscount = 0;
            }
            $discountAmount = min($ticketDiscount, $total);
            $finalTotal = max(0, $total - $discountAmount);

            $orderUpdatePayload = [];

            if ($hasOrderTotal) {
                $orderUpdatePayload['total'] = $finalTotal;
            }

            if ($hasOrderTotalAmount) {
                $orderUpdatePayload['total_amount'] = $total;
            }

            if ($hasOrderFinalAmount) {
                $orderUpdatePayload['final_amount'] = $finalTotal;
            }

            if (Schema::hasColumn('orders', 'discount_amount')) {
                $orderUpdatePayload['discount_amount'] = $discountAmount;
            }

            $order->update($orderUpdatePayload);
            $this->precheckStoreCreditForPendingOrder($order->fresh() ?? $order);

            $now = now();
            $ticket->status = 'USED_FOR_ORDER';

            if (Schema::hasColumn('tickets', 'used_at')) {
                $ticket->used_at = $now;
            }

            if (Schema::hasColumn('tickets', 'source_type')) {
                $ticket->source_type = 'ORDER_REDEEM';
            }

            if (Schema::hasColumn('tickets', 'source_id')) {
                $ticket->source_id = (string) $order->id;
            }

            if (Schema::hasColumn('tickets', 'locked_at')) {
                $ticket->locked_at = null;
            }

            if (Schema::hasColumn('tickets', 'locked_by')) {
                $ticket->locked_by = null;
            }

            if (Schema::hasColumn('tickets', 'lock_expires_at')) {
                $ticket->lock_expires_at = null;
            }

            $ticket->save();
            $ticketLockService->deleteLockRow((string) $ticket->id);

            $balance = UserBalance::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'available_balance' => 0,
                    'locked_balance' => 0,
                    'used_balance' => 0,
                    'expired_balance' => 0,
                    'total_lifetime_purchased' => 0,
                    'total_lifetime_used' => 0,
                    'last_updated' => $now,
                    'version' => 0,
                ]
            );

            $balanceBefore = (int) $balance->available_balance;
            $balance->available_balance = max(0, $balanceBefore - 1);
            $balance->used_balance = (int) $balance->used_balance + 1;
            $balance->total_lifetime_used = (int) $balance->total_lifetime_used + 1;
            $balance->last_updated = $now;
            $balance->version = (int) $balance->version + 1;
            $balance->save();

            $ledgerService = app(TicketLedgerService::class);
            if ($ledgerService->isEnabled()) {
                $ledgerService->record([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'action_type' => 'ORDER_USE',
                    'amount_change' => -1,
                    'balance_before' => $balanceBefore,
                    'balance_after' => (int) $balance->available_balance,
                    'reference_id' => (string) $order->id,
                    'reference_type' => 'ORDER',
                    'metadata' => [
                        'store_id' => (string) $store->id,
                        'store_name' => (string) $store->name,
                    ],
                    'created_at' => $now,
                ]);
            }

            $this->consumeUserCartItemsByPurchasedLines($user, $consumedCartLines);

            DB::commit();

            // Don't notify admin for orders with shipping_method: "later" - wait until user calls
            $shouldNotifyAdmin = !$isPendingPayment && strtolower($shippingMethod) !== 'later';
            if ($shouldNotifyAdmin) {
                event(new NewOrderPlaced($order));
            }

            return response()->json([
                'success' => true,
                'message' => 'Đặt món thành công. Cửa hàng đã nhận được thông báo.',
                'order_id' => $order->id,
                'pricing' => [
                    'total_amount' => $total,
                    'ticket_discount' => $discountAmount,
                    'final_amount' => $finalTotal,
                ],
            ]);
        } catch (FlashSaleUserLimitExceededException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Số lượng mua vượt giới hạn cho sản phẩm trong Flash Sale.',
                'errors' => [
                    [
                        'code' => 'FLASH_SALE_USER_LIMIT_EXCEEDED',
                        'message' => 'User exceeded per-item flash sale quota.',
                        'flash_sale_item_id' => (string) $e->flashSaleItemId,
                        'per_user_limit' => $e->perUserLimit,
                        'user_purchased_quantity' => $e->userPurchasedQuantity,
                        'requested_quantity' => $e->requestedQuantity,
                    ],
                ],
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function placeFromMenu(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'store_id' => 'required',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
            'payment_method' => 'required|string|max:50',
            'defer_call' => 'nullable|boolean',
            'referrer_id' => 'nullable|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required',
            'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'note' => 'nullable|string|max:500',
            'voucher' => 'nullable|string|exists:vouchers,code',
            'include_vat' => 'nullable|boolean',
            'shipping_method' => 'nullable|string|max:50',
        ]);

        $blockedStoreIds = $this->resolveBlockedOwnStoreIds(
            $user,
            [$validated['store_id'] ?? null]
        );

        if (!empty($blockedStoreIds)) {
            return $this->ownStoreOrderForbiddenResponse($blockedStoreIds);
        }

        DB::beginTransaction();

        try {
            $flashSaleService = app(FlashSaleService::class);
            $hasOrderId = Schema::hasColumn('orders', 'id');
            $hasOrderCode = Schema::hasColumn('orders', 'order_code');
            $hasOrderUserId = Schema::hasColumn('orders', 'user_id');
            $hasOrderStoreId = Schema::hasColumn('orders', 'store_id');
            $hasOrderName = Schema::hasColumn('orders', 'name');
            $hasOrderPhone = Schema::hasColumn('orders', 'phone');
            $hasOrderAddress = Schema::hasColumn('orders', 'address');
            $hasOrderDeliveryPhone = Schema::hasColumn('orders', 'delivery_phone');
            $hasOrderDeliveryAddress = Schema::hasColumn('orders', 'delivery_address');
            $hasOrderPaymentMethod = Schema::hasColumn('orders', 'payment_method');
            $hasOrderStatusPayment = Schema::hasColumn('orders', 'status_payment');
            $hasOrderStatus = Schema::hasColumn('orders', 'status');
            $hasOrderTotal = Schema::hasColumn('orders', 'total');
            $hasOrderTotalAmount = Schema::hasColumn('orders', 'total_amount');
            $hasOrderFinalAmount = Schema::hasColumn('orders', 'final_amount');
            $hasOrderDeliveryNote = Schema::hasColumn('orders', 'delivery_note');
            $hasOrderTotalTicketsUsed = Schema::hasColumn('orders', 'total_tickets_used');
            $hasOrderParentOrderId = Schema::hasColumn('orders', 'parent_order_id');
            $hasOrderShippingMethod = Schema::hasColumn('orders', 'shipping_method');

            $hasOrderItemId = Schema::hasColumn('order_items', 'id');
            $hasOrderItemOrderId = Schema::hasColumn('order_items', 'order_id');
            $hasOrderItemProductId = Schema::hasColumn('order_items', 'product_id');
            $hasOrderItemVariantId = Schema::hasColumn('order_items', 'variant_id');
            $hasOrderItemProductName = Schema::hasColumn('order_items', 'product_name');
            $hasOrderItemThumbnail = Schema::hasColumn('order_items', 'thumbnail');
            $hasOrderItemPrice = Schema::hasColumn('order_items', 'price');
            $hasOrderItemMenuItemId = Schema::hasColumn('order_items', 'menu_item_id');
            $hasOrderItemItemName = Schema::hasColumn('order_items', 'item_name');
            $hasOrderItemQuantity = Schema::hasColumn('order_items', 'quantity');
            $hasOrderItemCalledQuantity = Schema::hasColumn('order_items', 'called_quantity');
            $hasOrderItemUnitPrice = Schema::hasColumn('order_items', 'unit_price');
            $hasOrderItemTotalPrice = Schema::hasColumn('order_items', 'total_price');
            $hasOrderItemSpecialInstructions = Schema::hasColumn('order_items', 'special_instructions');

            $deferCall = (bool) ($validated['defer_call'] ?? false);
            $shippingMethod = (string) ($validated['shipping_method'] ?? '');
            
            // Treat shipping_method: "later" as defer_call - don't notify admin until user calls
            if (strtolower($shippingMethod) === 'later') {
                $deferCall = true;
            }
            
            $paymentMethod = strtoupper((string) ($validated['payment_method'] ?? ''));
            $isPendingPayment = $this->isPendingPaymentMethod($paymentMethod);
            $referrerId = $this->resolveAffiliateRecipientId(
                isset($validated['referrer_id']) ? User::resolveReferrerId($validated['referrer_id']) : null,
                (int) $user->id,
            );

            $store = Store::query()
                ->where('id', $validated['store_id'])
                ->first();

            if (!$store) {
                throw new \Exception('Cua hang khong ton tai.');
            }

            // Validate store is currently open
            $this->validateStoreOperatingStatus($store);

            $orderPayload = [];

            if ($hasOrderId) {
                $orderPayload['id'] = (string) Str::uuid();
            }

            if ($hasOrderCode) {
                $orderPayload['order_code'] = $this->generateOrderCode();
            }

            if ($hasOrderUserId) {
                $orderPayload['user_id'] = (string) $user->id;
            }

            if ($hasOrderStoreId) {
                $orderPayload['store_id'] = $store->id;
            }

            if ($hasOrderName) {
                $orderPayload['name'] = $validated['name'];
            }

            if ($hasOrderPhone) {
                $orderPayload['phone'] = $validated['phone'];
            }

            if ($hasOrderAddress) {
                $orderPayload['address'] = $validated['address'] ?? '';
            }

            if ($hasOrderDeliveryPhone) {
                $orderPayload['delivery_phone'] = $validated['phone'];
            }

            if ($hasOrderDeliveryAddress) {
                $orderPayload['delivery_address'] = $validated['address'] ?? '';
            }

            if ($hasOrderPaymentMethod) {
                $orderPayload['payment_method'] = $validated['payment_method'];
            }

            if ($hasOrderStatusPayment) {
                $orderPayload['status_payment'] = $isPendingPayment ? 'pending' : 'paid';
            }

            if ($hasOrderStatus) {
                if ($isPendingPayment) {
                    $orderPayload['status'] = 'pending_payment';
                } else {
                    $orderPayload['status'] = $deferCall ? 'paid_pending_call' : 'pending';
                }
            }

            if ($hasOrderParentOrderId) {
                $orderPayload['parent_order_id'] = null;
            }

            if ($hasOrderTotal) {
                $orderPayload['total'] = 0;
            }

            if ($hasOrderTotalAmount) {
                $orderPayload['total_amount'] = 0;
            }

            if ($hasOrderFinalAmount) {
                $orderPayload['final_amount'] = 0;
            }

            if ($hasOrderDeliveryNote) {
                $orderPayload['delivery_note'] = $validated['note'] ?? null;
            }

            if ($hasOrderTotalTicketsUsed) {
                $orderPayload['total_tickets_used'] = 0;
            }

            if ($hasOrderShippingMethod) {
                $orderPayload['shipping_method'] = $validated['shipping_method'] ?? null;
            }

            if (Schema::hasColumn('orders', 'referrer_id')) {
                $orderPayload['referrer_id'] = $referrerId;
            }

            $order = Order::query()->create($orderPayload);

            $total = 0;
            $consumedCartLines = [];
            $menuItemsById = $this->loadAvailableMenuItemsForOrder($validated['items'], $store);

            foreach ($validated['items'] as $item) {
                $menuItem = $menuItemsById->get((string) $item['menu_item_id']);

                if (!$menuItem) {
                    throw new \Exception('Món ăn không thuộc cửa hàng đã chọn hoặc đang tạm ngưng bán.');
                }

                $resolvedReference = $this->resolveMenuItemStockReference($menuItem);
                $mappedProductId = (int) ($resolvedReference['product_id'] ?? 0);
                $mappedVariantId = isset($resolvedReference['variant_id']) ? (int) $resolvedReference['variant_id'] : null;
                $resolvedItemName = (string) ($resolvedReference['item_name'] ?? (string) ($menuItem->item_name ?? ''));

                if (((int) ($menuItem->product_id ?? 0) > 0 || (int) ($menuItem->variant_id ?? 0) > 0) && $mappedProductId <= 0) {
                    throw new Exception('San pham gan voi mon an chua duoc duyet hoac khong kha dung.');
                }

                $quantity = (int) $item['quantity'];
                $this->assertAndMaybeDeductMappedStock(
                    $mappedProductId,
                    $mappedVariantId,
                    $quantity,
                    !$isPendingPayment
                );

                $basePrice = $this->resolveMenuItemBasePrice($menuItem, $resolvedReference);
                $unitPrice = $basePrice;
                if ($mappedProductId > 0) {
                    $pricing = $flashSaleService->consumeFlashSaleQuantity(
                        (string) $store->id,
                        $mappedProductId,
                        $mappedVariantId,
                        $quantity,
                        $basePrice,
                        (int) $user->id
                    );
                    $unitPrice = (float) ($pricing['price'] ?? $basePrice);
                }
                $lineTotal = $unitPrice * $quantity;
                $total += $lineTotal;

                if ($mappedProductId > 0 && $quantity > 0) {
                    $consumedCartLines[] = [
                        'product_id' => $mappedProductId,
                        'variant_id' => $mappedVariantId,
                        'quantity' => $quantity,
                    ];
                }

                $orderItemPayload = [];

                if ($hasOrderItemId) {
                    $orderItemPayload['id'] = (string) Str::uuid();
                }

                if ($hasOrderItemOrderId) {
                    $orderItemPayload['order_id'] = $order->id;
                }

                if ($hasOrderItemProductId) {
                    $orderItemPayload['product_id'] = $mappedProductId > 0 ? $mappedProductId : 0;
                }

                if ($hasOrderItemVariantId) {
                    $orderItemPayload['variant_id'] = $mappedVariantId;
                }

                if ($hasOrderItemProductName) {
                    $orderItemPayload['product_name'] = $resolvedItemName;
                }

                if ($hasOrderItemThumbnail) {
                    $orderItemPayload['thumbnail'] = $menuItem->image_url;
                }

                if ($hasOrderItemPrice) {
                    $orderItemPayload['price'] = $unitPrice;
                }

                if ($hasOrderItemMenuItemId) {
                    $orderItemPayload['menu_item_id'] = $menuItem->id;
                }

                if ($hasOrderItemItemName) {
                    $orderItemPayload['item_name'] = $resolvedItemName;
                }

                if ($hasOrderItemQuantity) {
                    $orderItemPayload['quantity'] = $quantity;
                }

                if ($hasOrderItemCalledQuantity) {
                    $orderItemPayload['called_quantity'] = $deferCall ? 0 : $quantity;
                }

                if ($hasOrderItemUnitPrice) {
                    $orderItemPayload['unit_price'] = $unitPrice;
                }

                if ($hasOrderItemTotalPrice) {
                    $orderItemPayload['total_price'] = $lineTotal;
                }

                if ($hasOrderItemSpecialInstructions) {
                    $orderItemPayload['special_instructions'] = $validated['note'] ?? null;
                }

                $rewardPerUnit = $this->resolveTicketRewardQuantityFromReference($resolvedReference);
                $orderItemPayload['ticket_reward_quantity'] = $rewardPerUnit * $quantity;

                OrderItem::query()->create($orderItemPayload);
            }

            $voucherCode = $validated['voucher'] ?? null;
            $discountAmount = 0;

            if ($voucherCode) {
                $voucher = Voucher::where('code', $voucherCode)->first();

                if (!$voucher) {
                    throw new \Exception('Mã giảm giá không tồn tại.');
                }

                if (!$voucher->is_active) {
                    throw new \Exception('Mã giảm giá hiện không hoạt động.');
                }

                if (now()->lt($voucher->start_date)) {
                    throw new \Exception('Mã giảm giá chưa bắt đầu.');
                }

                if (now()->gt($voucher->end_date)) {
                    throw new \Exception('Mã giảm giá đã hết hạn.');
                }

                if ($voucher->quantity <= $voucher->used) {
                    throw new \Exception('Mã giảm giá đã được sử dụng hết.');
                }

                if ($voucher->min_order_value && $total < $voucher->min_order_value) {
                    throw new \Exception('Đơn hàng không đủ giá trị tối thiểu để áp dụng mã giảm giá.');
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
            $finalTotal = max(0, $total - $discountAmount + $vatAmount);

            $orderUpdatePayload = [];

            if (Schema::hasColumn('orders', 'total')) {
                $orderUpdatePayload['total'] = $finalTotal;
            }

            if (Schema::hasColumn('orders', 'total_amount')) {
                $orderUpdatePayload['total_amount'] = $finalTotal;
            }

            if (Schema::hasColumn('orders', 'final_amount')) {
                $orderUpdatePayload['final_amount'] = $finalTotal;
            }

            if (Schema::hasColumn('orders', 'vat_amount')) {
                $orderUpdatePayload['vat_amount'] = $vatAmount;
            }

            if (Schema::hasColumn('orders', 'discount_amount')) {
                $orderUpdatePayload['discount_amount'] = $discountAmount;
            }

            if (Schema::hasColumn('orders', 'voucher_code')) {
                $orderUpdatePayload['voucher_code'] = $voucherCode;
            }

            if (!empty($orderUpdatePayload)) {
                $order->update($orderUpdatePayload);
            }

            $this->precheckStoreCreditForPendingOrder($order->fresh() ?? $order);

            if (!$isPendingPayment) {
                $this->awardTicketsForOrder($user, $order, $finalTotal);
            }

            $this->consumeUserCartItemsByPurchasedLines($user, $consumedCartLines);

            DB::commit();
            
            // Don't notify admin for orders with shipping_method: "later" - wait until user calls
            $shouldNotifyAdmin = !$isPendingPayment && strtolower($shippingMethod) !== 'later';
            if ($shouldNotifyAdmin) {
                event(new NewOrderPlaced($order));
            }

            return response()->json([
                'success' => true,
                'message' => 'Đặt hàng thành công!',
                'order_id' => $order->id,
                'defer_call' => $deferCall,
            ]);
        } catch (FlashSaleUserLimitExceededException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Số lượng mua vượt giới hạn cho sản phẩm trong Flash Sale.',
                'errors' => [
                    [
                        'code' => 'FLASH_SALE_USER_LIMIT_EXCEEDED',
                        'message' => 'User exceeded per-item flash sale quota.',
                        'flash_sale_item_id' => (string) $e->flashSaleItemId,
                        'per_user_limit' => $e->perUserLimit,
                        'user_purchased_quantity' => $e->userPurchasedQuantity,
                        'requested_quantity' => $e->requestedQuantity,
                    ],
                ],
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function callPrepaidOrder(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'order_id' => 'required',
            'store_id' => 'nullable|exists:stores,id',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required',
            'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'note' => 'nullable|string|max:500',
            'shipping_method' => 'nullable|string|max:50',
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $parentOrder = Order::query()
                ->where(function ($q) use ($validated) {
                    $q->where('id', $validated['order_id'])
                        ->orWhere('order_code', $validated['order_id']);
                })
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$parentOrder) {
                throw new \Exception('Không tìm thấy đơn hàng đã thanh toán.');
            }

            $isCashOrder = in_array(strtoupper((string) $parentOrder->payment_method), ['CASH', 'COD'], true);

            $allowedStatuses = ['paid_pending_call', 'partially_called'];
            if ($isCashOrder) {
                $allowedStatuses[] = 'pending_payment';
            }

            if (!in_array((string) $parentOrder->status, $allowedStatuses, true)) {
                throw new \Exception('Đơn hàng này không ở trạng thái chờ gọi món.');
            }

            if (!$isCashOrder &&
                Schema::hasColumn('orders', 'status_payment')
                && strtolower((string) ($parentOrder->status_payment ?? 'pending')) !== 'paid'
            ) {
                throw new \Exception('Đơn hàng chưa thanh toán nên chưa thể gọi món.');
            }

            $hasOrderParentOrderId = Schema::hasColumn('orders', 'parent_order_id');
            $hasOrderId = Schema::hasColumn('orders', 'id');
            $hasOrderCode = Schema::hasColumn('orders', 'order_code');
            $hasOrderUserId = Schema::hasColumn('orders', 'user_id');
            $hasOrderName = Schema::hasColumn('orders', 'name');
            $hasOrderPhone = Schema::hasColumn('orders', 'phone');
            $hasOrderAddress = Schema::hasColumn('orders', 'address');
            $hasOrderDeliveryPhone = Schema::hasColumn('orders', 'delivery_phone');
            $hasOrderDeliveryAddress = Schema::hasColumn('orders', 'delivery_address');
            $hasOrderStoreId = Schema::hasColumn('orders', 'store_id');
            $hasOrderStatus = Schema::hasColumn('orders', 'status');
            $hasOrderStatusPayment = Schema::hasColumn('orders', 'status_payment');
            $hasOrderTotal = Schema::hasColumn('orders', 'total');
            $hasOrderTotalAmount = Schema::hasColumn('orders', 'total_amount');
            $hasOrderFinalAmount = Schema::hasColumn('orders', 'final_amount');
            $hasOrderTotalTicketsUsed = Schema::hasColumn('orders', 'total_tickets_used');
            $hasOrderPaymentMethod = Schema::hasColumn('orders', 'payment_method');
            $hasOrderDeliveryNote = Schema::hasColumn('orders', 'delivery_note');
            $hasOrderShippingMethod = Schema::hasColumn('orders', 'shipping_method');

            $hasOrderItemId = Schema::hasColumn('order_items', 'id');
            $hasOrderItemOrderId = Schema::hasColumn('order_items', 'order_id');
            $hasOrderItemProductId = Schema::hasColumn('order_items', 'product_id');
            $hasOrderItemVariantId = Schema::hasColumn('order_items', 'variant_id');
            $hasOrderItemMenuItemId = Schema::hasColumn('order_items', 'menu_item_id');
            $hasOrderItemProductName = Schema::hasColumn('order_items', 'product_name');
            $hasOrderItemItemName = Schema::hasColumn('order_items', 'item_name');
            $hasOrderItemThumbnail = Schema::hasColumn('order_items', 'thumbnail');
            $hasOrderItemPrice = Schema::hasColumn('order_items', 'price');
            $hasOrderItemUnitPrice = Schema::hasColumn('order_items', 'unit_price');
            $hasOrderItemQuantity = Schema::hasColumn('order_items', 'quantity');
            $hasOrderItemCalledQuantity = Schema::hasColumn('order_items', 'called_quantity');
            $hasOrderItemTotalPrice = Schema::hasColumn('order_items', 'total_price');
            $hasOrderItemSpecialInstructions = Schema::hasColumn('order_items', 'special_instructions');
            $hasOrderItemFlashSaleId = Schema::hasColumn('order_items', 'flash_sale_item_id');
            if (!$hasOrderItemCalledQuantity) {
                throw new \Exception('Hệ thống chưa hỗ trợ gọi món nhiều lần. Vui lòng chạy migration mới nhất.');
            }

            $selectedRows = collect($validated['items'])
                ->groupBy(fn($item) => (string) $item['order_item_id'])
                ->map(fn($rows) => array_sum(array_map(fn($row) => (int) $row['quantity'], $rows->all())));

            $resolvedStoreId = $hasOrderStoreId ? ($parentOrder->store_id ?: null) : null;

            if (empty($resolvedStoreId) && !empty($validated['store_id'])) {
                $resolvedStoreId = (string) $validated['store_id'];
            }

            if (empty($resolvedStoreId)) {
                $hasStoreMenuTable = Schema::hasTable('store_menu');
                $hasStoreMenuId = $hasStoreMenuTable && Schema::hasColumn('store_menu', 'id');
                $hasStoreMenuStoreId = $hasStoreMenuTable && Schema::hasColumn('store_menu', 'store_id');
                $hasProductsTable = Schema::hasTable('products');
                $hasProductsId = $hasProductsTable && Schema::hasColumn('products', 'id');
                $hasProductsStoreId = $hasProductsTable && Schema::hasColumn('products', 'store_id');
                $candidateStoreIds = [];

                foreach ($selectedRows as $orderItemId => $callQuantity) {
                    $parentItemForStore = OrderItem::query()
                        ->where('id', $orderItemId)
                        ->where('order_id', $parentOrder->id)
                        ->first();

                    if (!$parentItemForStore) {
                        throw new \Exception('Có món gọi không thuộc đơn đã thanh toán.');
                    }

                    $candidateStoreId = null;

                    if ($hasOrderItemMenuItemId && !empty($parentItemForStore->menu_item_id) && $hasStoreMenuId && $hasStoreMenuStoreId) {
                        $candidateStoreId = DB::table('store_menu')
                            ->where('id', $parentItemForStore->menu_item_id)
                            ->value('store_id');
                    }

                    if (!$candidateStoreId && $hasOrderItemProductId && !empty($parentItemForStore->product_id) && $hasProductsId && $hasProductsStoreId) {
                        $candidateStoreId = DB::table('products')
                            ->where('id', $parentItemForStore->product_id)
                            ->value('store_id');
                    }

                    if (!empty($candidateStoreId)) {
                        $candidateStoreIds[(string) $candidateStoreId] = true;
                    }
                }

                if (count($candidateStoreIds) > 1) {
                    throw new \Exception('Đơn gọi món chứa sản phẩm từ nhiều cửa hàng, không thể xử lý tự động.');
                }

                if (count($candidateStoreIds) === 1) {
                    $resolvedStoreId = array_key_first($candidateStoreIds);
                }
            }

            if (empty($resolvedStoreId)) {
                throw new \Exception('Không xác định được cửa hàng cho đơn gọi món. Vui lòng kiểm tra lại đơn gốc.');
            }

            if ($hasOrderStoreId && empty($parentOrder->store_id)) {
                $parentOrder->store_id = $resolvedStoreId;
                $parentOrder->save();
            }

            $childOrderPayload = [];

            if ($hasOrderId) {
                $childOrderPayload['id'] = (string) Str::uuid();
            }

            if ($hasOrderCode) {
                $childOrderPayload['order_code'] = $this->generateOrderCode();
            }

            if ($hasOrderUserId) {
                $childOrderPayload['user_id'] = (string) $user->id;
            }

            if ($hasOrderName) {
                $childOrderPayload['name'] = (string) ($validated['name'] ?? $parentOrder->name);
            }

            if ($hasOrderPhone) {
                $childOrderPayload['phone'] = (string) ($validated['phone'] ?? $parentOrder->phone);
            }

            if ($hasOrderAddress) {
                $childOrderPayload['address'] = (string) ($validated['address'] ?? $parentOrder->address);
            }

            if ($hasOrderDeliveryPhone) {
                $childOrderPayload['delivery_phone'] = (string) ($validated['phone'] ?? $parentOrder->delivery_phone);
            }

            if ($hasOrderDeliveryAddress) {
                $childOrderPayload['delivery_address'] = (string) ($validated['address'] ?? $parentOrder->delivery_address);
            }

            if ($hasOrderShippingMethod) {
                $childOrderPayload['shipping_method'] = (string) ($validated['shipping_method'] ?? $parentOrder->shipping_method);
            }

            if ($hasOrderTotal) {
                $childOrderPayload['total'] = 0;
            }

            if ($hasOrderTotalAmount) {
                $childOrderPayload['total_amount'] = 0;
            }

            if ($hasOrderFinalAmount) {
                $childOrderPayload['final_amount'] = 0;
            }

            if ($hasOrderStoreId) {
                $childOrderPayload['store_id'] = $resolvedStoreId;
            }

            if ($hasOrderParentOrderId) {
                $childOrderPayload['parent_order_id'] = $parentOrder->id;
            }

            if ($hasOrderStatus) {
                $childOrderPayload['status'] = 'pending';
            }

            if ($hasOrderStatusPayment) {
                $childOrderPayload['status_payment'] = 'paid';
            }

            if ($hasOrderPaymentMethod) {
                $childOrderPayload['payment_method'] = 'PREPAID_CALL';
            }

            if ($hasOrderDeliveryNote) {
                $childOrderPayload['delivery_note'] = $validated['note'] ?? $parentOrder->delivery_note;
            }

            if ($hasOrderTotalTicketsUsed) {
                $childOrderPayload['total_tickets_used'] = 0;
            }

            $childOrder = Order::query()->create($childOrderPayload);

            $childTotal = 0;

            foreach ($selectedRows as $orderItemId => $callQuantity) {
                $parentItem = OrderItem::query()
                    ->where('id', $orderItemId)
                    ->where('order_id', $parentOrder->id)
                    ->lockForUpdate()
                    ->first();

                if (!$parentItem) {
                    throw new \Exception('Có món gọi không thuộc đơn đã thanh toán.');
                }

                $orderedQuantity = (int) ($parentItem->quantity ?? 0);
                $calledQuantity = (int) ($parentItem->called_quantity ?? 0);
                $remaining = max(0, $orderedQuantity - $calledQuantity);

                if ($callQuantity > $remaining) {
                    throw new \Exception('Số lượng gọi vượt quá phần còn lại của món đã mua.');
                }

                $unitPrice = (float) ($parentItem->unit_price ?? $parentItem->price ?? 0);
                $lineTotal = $unitPrice * (int) $callQuantity;
                $childTotal += $lineTotal;

                $childOrderItemPayload = [];

                if ($hasOrderItemId) {
                    $childOrderItemPayload['id'] = (string) Str::uuid();
                }

                if ($hasOrderItemOrderId) {
                    $childOrderItemPayload['order_id'] = $childOrder->id;
                }

                if ($hasOrderItemProductId) {
                    $childOrderItemPayload['product_id'] = $parentItem->product_id ?? 0;
                }

                if ($hasOrderItemVariantId) {
                    $childOrderItemPayload['variant_id'] = $parentItem->variant_id ?? null;
                }

                if ($hasOrderItemMenuItemId) {
                    $childOrderItemPayload['menu_item_id'] = $parentItem->menu_item_id;
                }

                if ($hasOrderItemProductName) {
                    $childOrderItemPayload['product_name'] = $parentItem->product_name;
                }

                if ($hasOrderItemItemName) {
                    $childOrderItemPayload['item_name'] = $parentItem->item_name;
                }

                if ($hasOrderItemThumbnail) {
                    $childOrderItemPayload['thumbnail'] = $parentItem->thumbnail;
                }

                if ($hasOrderItemPrice) {
                    $childOrderItemPayload['price'] = $unitPrice;
                }

                if ($hasOrderItemUnitPrice) {
                    $childOrderItemPayload['unit_price'] = $unitPrice;
                }

                if ($hasOrderItemQuantity) {
                    $childOrderItemPayload['quantity'] = (int) $callQuantity;
                }

                if ($hasOrderItemCalledQuantity) {
                    $childOrderItemPayload['called_quantity'] = (int) $callQuantity;
                }

                if ($hasOrderItemTotalPrice) {
                    $childOrderItemPayload['total_price'] = $lineTotal;
                }

                if ($hasOrderItemSpecialInstructions) {
                    $childOrderItemPayload['special_instructions'] = $validated['note'] ?? null;
                }

                if ($hasOrderItemFlashSaleId && isset($parentItem->flash_sale_item_id)) {
                    $childOrderItemPayload['flash_sale_item_id'] = $parentItem->flash_sale_item_id;
                }

                OrderItem::query()->create($childOrderItemPayload);

                $parentItem->called_quantity = $calledQuantity + (int) $callQuantity;
                $parentItem->save();
            }

            $childUpdatePayload = [];
            if ($hasOrderTotal) {
                $childUpdatePayload['total'] = $childTotal;
            }
            if ($hasOrderTotalAmount) {
                $childUpdatePayload['total_amount'] = $childTotal;
            }
            if ($hasOrderFinalAmount) {
                $childUpdatePayload['final_amount'] = $childTotal;
            }
            if (!empty($childUpdatePayload)) {
                $childOrder->update($childUpdatePayload);
            }

            $this->precheckStoreCreditForPendingOrder($childOrder->fresh() ?? $childOrder);

            $remainingAny = OrderItem::query()
                ->where('order_id', $parentOrder->id)
                ->whereRaw('COALESCE(quantity, 0) > COALESCE(called_quantity, 0)')
                ->exists();

            if ($hasOrderStatus) {
                $parentOrder->status = $remainingAny ? 'partially_called' : 'call_completed';
                $parentOrder->save();
            }

            DB::commit();
            event(new NewOrderPlaced($childOrder));

            return response()->json([
                'success' => true,
                'message' => 'Đã gửi yêu cầu gọi món cho cửa hàng.',
                'order_id' => $childOrder->id,
                'parent_order_status' => $parentOrder->status,
                'parent_order_code' => $parentOrder->order_code ?? null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function loadAvailableMenuItemsForOrder(array $items, Store $store)
    {
        $menuItemIds = collect($items)
            ->pluck('menu_item_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($menuItemIds)) {
            return collect();
        }

        $menuItemsQuery = StoreMenu::query()
            ->whereIn('id', $menuItemIds)
            ->where('store_id', $store->id)
            ->where('is_available', true);

        $this->applyPurchasableMenuItemScope($menuItemsQuery);

        $menuItems = $menuItemsQuery->get();

        $this->primeMenuItemStockReferenceCache($menuItems);

        return $menuItems->keyBy(static fn (StoreMenu $menuItem): string => (string) $menuItem->id);
    }

    private function primeMenuItemStockReferenceCache(iterable $menuItems): void
    {
        $productIds = [];
        $variantIds = [];

        foreach ($menuItems as $menuItem) {
            $productId = (int) ($menuItem->product_id ?? 0);
            $variantId = (int) ($menuItem->variant_id ?? 0);

            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
            if ($variantId > 0) {
                $variantIds[$variantId] = $variantId;
            }
        }

        if (!empty($variantIds)) {
            $variantQuery = ProductVariant::query()
                ->with('attributeValues')
                ->whereIn('id', array_values($variantIds));
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantQuery->where('enabled', true);
            }
            $variants = $variantQuery->get();

            foreach ($variants as $variant) {
                $variantId = (int) $variant->id;
                $this->orderVariantReferenceCache[$variantId] = $variant;

                $productId = (int) ($variant->product_id ?? 0);
                if ($productId > 0) {
                    $productIds[$productId] = $productId;
                }
            }
        }

        $missingProductIds = array_filter(array_values($productIds), function (int $productId): bool {
            return !array_key_exists($productId, $this->orderProductReferenceCache);
        });

        if (!empty($missingProductIds)) {
            $products = $this->applyApprovedProductScope(Product::query())
                ->whereIn('id', $missingProductIds)
                ->get();

            foreach ($products as $product) {
                $this->orderProductReferenceCache[(int) $product->id] = $product;
            }
        }
    }

    private function findProductReference(int $productId): ?Product
    {
        if ($productId <= 0) {
            return null;
        }

        if (!array_key_exists($productId, $this->orderProductReferenceCache)) {
            $this->orderProductReferenceCache[$productId] = $this->applyApprovedProductScope(Product::query())
                ->where('id', $productId)
                ->first();
        }

        return $this->orderProductReferenceCache[$productId];
    }

    private function findVariantReference(int $variantId): ?ProductVariant
    {
        if ($variantId <= 0) {
            return null;
        }

        if (!array_key_exists($variantId, $this->orderVariantReferenceCache)) {
            $variantQuery = ProductVariant::query()
                ->with('attributeValues')
                ->where('id', $variantId);
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantQuery->where('enabled', true);
            }
            $this->orderVariantReferenceCache[$variantId] = $variantQuery->first();
        }

        return $this->orderVariantReferenceCache[$variantId];
    }

    private function resolveMenuItemStockReference(StoreMenu $menuItem): array
    {
        $resolvedProductId = null;
        $resolvedVariantId = null;
        $resolvedItemName = trim((string) ($menuItem->item_name ?? ''));
        $resolvedProduct = null;
        $resolvedVariant = null;

        $menuProductId = (int) ($menuItem->product_id ?? 0);
        $menuVariantId = (int) ($menuItem->variant_id ?? 0);

        if ($menuVariantId > 0) {
            $variant = $this->findVariantReference($menuVariantId);
            if ($variant) {
                $product = $this->findProductReference((int) $variant->product_id);
                if ($product) {
                    $storeMatched = !Schema::hasColumn('products', 'store_id')
                        || ((string) ($product->store_id ?? '') !== '' && (string) $product->store_id === (string) ($menuItem->store_id ?? ''));

                    if ($storeMatched) {
                        $resolvedVariantId = (int) $variant->id;
                        $resolvedProductId = (int) $product->id;
                        $resolvedProduct = $product;
                        $resolvedVariant = $variant;
                    }
                }
            }
        }

        if ($resolvedProductId === null && $menuProductId > 0) {
            $product = $this->findProductReference($menuProductId);
            if ($product) {
                $storeMatched = !Schema::hasColumn('products', 'store_id')
                    || ((string) ($product->store_id ?? '') !== '' && (string) $product->store_id === (string) ($menuItem->store_id ?? ''));

                if ($storeMatched) {
                    $resolvedProductId = (int) $product->id;
                    $resolvedProduct = $product;
                }
            }
        }

        if ($resolvedProductId === null) {
            $fallback = $this->resolveProductAndVariantByMenuFallback($menuItem);
            $resolvedProductId = $fallback['product_id'];
            $resolvedVariantId = $fallback['variant_id'];
            $resolvedProduct = $fallback['product'];
            $resolvedVariant = $fallback['variant'];
        }

        if ($resolvedProduct) {
            $resolvedItemName = trim((string) ($resolvedProduct->name ?? $resolvedItemName));
        }

        if ($resolvedVariant) {
            $attributeValues = $resolvedVariant->relationLoaded('attributeValues')
                ? $resolvedVariant->attributeValues
                : $resolvedVariant->attributeValues()->get();
            $variantValues = $attributeValues
                ->pluck('value')
                ->map(fn($value) => trim((string) $value))
                ->filter()
                ->values()
                ->all();

            if (!empty($variantValues)) {
                $baseName = trim((string) ($resolvedProduct->name ?? $resolvedItemName));
                if ($baseName === '') {
                    $baseName = $resolvedItemName;
                }

                $resolvedItemName = $baseName . ' [' . implode(' - ', $variantValues) . ']';
            }
        }

        return [
            'product_id' => $resolvedProductId,
            'variant_id' => $resolvedVariantId,
            'item_name' => $resolvedItemName,
            'product' => $resolvedProduct,
            'variant' => $resolvedVariant,
        ];
    }

    private function resolveProductAndVariantByMenuFallback(StoreMenu $menuItem): array
    {
        $itemName = trim((string) ($menuItem->item_name ?? ''));
        if ($itemName === '') {
            return [
                'product_id' => null,
                'variant_id' => null,
                'product' => null,
                'variant' => null,
            ];
        }

        $productsQuery = $this->applyApprovedProductScope(Product::query()->where('name', $itemName));
        if (Schema::hasColumn('products', 'store_id')) {
            $productsQuery->where('store_id', (string) ($menuItem->store_id ?? ''));
        }

        $products = $productsQuery->get(['id', 'name', 'price', 'sale_price']);

        if ($products->isEmpty()) {
            return [
                'product_id' => null,
                'variant_id' => null,
                'product' => null,
                'variant' => null,
            ];
        }

        $unitPrice = (float) ($menuItem->price ?? 0);

        foreach ($products as $productRow) {
            if ($unitPrice <= 0) {
                break;
            }

            $variantQuery = ProductVariant::query()
                ->where('product_id', (int) $productRow->id);
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantQuery->where('enabled', true);
            }
            $variantRows = $variantQuery->get(['id', 'product_id', 'price', 'sale_price']);

            if ($variantRows->isEmpty()) {
                continue;
            }

            $matchedVariants = $variantRows
                ->filter(function ($variantRow) use ($unitPrice) {
                    $variantSalePrice = (float) ($variantRow->sale_price ?? 0);
                    $variantPrice = (float) ($variantRow->price ?? 0);
                    $effectivePrice = $variantSalePrice > 0 ? $variantSalePrice : $variantPrice;

                    return $effectivePrice > 0 && abs($effectivePrice - $unitPrice) < 0.01;
                })
                ->values();

            if ($matchedVariants->count() === 1) {
                $variantId = (int) $matchedVariants[0]->id;
                $variant = $this->findVariantReference($variantId);
                $product = $this->findProductReference((int) $productRow->id);

                if ($variant && $product) {
                    return [
                        'product_id' => (int) $product->id,
                        'variant_id' => (int) $variant->id,
                        'product' => $product,
                        'variant' => $variant,
                    ];
                }
            }
        }

        if ($products->count() === 1) {
            $product = $this->findProductReference((int) $products[0]->id);
            return [
                'product_id' => $product ? (int) $product->id : null,
                'variant_id' => null,
                'product' => $product,
                'variant' => null,
            ];
        }

        if ($unitPrice > 0) {
            $matchedProducts = $products
                ->filter(function ($productRow) use ($unitPrice) {
                    $salePrice = (float) ($productRow->sale_price ?? 0);
                    $price = (float) ($productRow->price ?? 0);
                    $effectivePrice = $salePrice > 0 ? $salePrice : $price;

                    return $effectivePrice > 0 && abs($effectivePrice - $unitPrice) < 0.01;
                })
                ->values();

            if ($matchedProducts->count() === 1) {
                $product = $this->findProductReference((int) $matchedProducts[0]->id);
                return [
                    'product_id' => $product ? (int) $product->id : null,
                    'variant_id' => null,
                    'product' => $product,
                    'variant' => null,
                ];
            }
        }

        return [
            'product_id' => null,
            'variant_id' => null,
            'product' => null,
            'variant' => null,
        ];
    }

    private function assertAndMaybeDeductMappedStock(int $productId, ?int $variantId, int $quantity, bool $deductNow): void
    {
        if ($quantity <= 0 || $productId <= 0) {
            return;
        }

        if (! $this->productIsApprovedForPurchase($this->findProductReference($productId))) {
            throw new Exception('San pham khong kha dung de dat hang.');
        }

        if ($variantId !== null && $variantId > 0) {
            $variantQuery = ProductVariant::query()
                ->where('id', $variantId)
                ->where('product_id', $productId)
                ->lockForUpdate();
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantQuery->where('enabled', true);
            }
            $variant = $variantQuery->first();

            if ($variant) {
                $availableStock = max(0, (int) ($variant->stock ?? 0));
                if ($quantity > $availableStock) {
                    throw new Exception('Biến thể sản phẩm không đủ tồn kho. Hiện chỉ còn ' . $availableStock . '.');
                }

                if ($deductNow) {
                    $this->deductStockByReference($productId, $variantId, $quantity);
                }

                return;
            }

            throw new Exception('Bien the san pham khong kha dung.');
        }

        $product = $this->applyApprovedProductScope(Product::query())
            ->where('id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$product) {
            throw new Exception('San pham khong kha dung de dat hang.');
        }

        $availableStock = max(0, (int) ($product->stock ?? 0));
        if ($quantity > $availableStock) {
            throw new Exception('Sản phẩm "' . (string) ($product->name ?? 'Không rõ') . '" không đủ tồn kho. Hiện chỉ còn ' . $availableStock . '.');
        }

        if ($deductNow) {
            $this->deductStockByReference($productId, null, $quantity);
        }
    }

    public function callback(Request $request)
    {
        return parent::callback($request);
    }

    public function checkout(Request $request)
    {
        return parent::checkout($request);
    }

    public function cancel(Request $request, $orderId)
    {
        $order = Order::where('id', $orderId)
            ->orWhere('order_code', $orderId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng không tồn tại.',
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        return parent::cancel($request, $order);
    }

    public function updateStatusPayment(Request $request)
    {
        return parent::updateStatusPayment($request);
    }

    public function indexOrder(Request $request)
    {
        return parent::indexOrder($request);
    }

    public function showOrderDetail($id, Request $request)
    {
        $order = Order::with(['store', 'items' => function($query) {
            $query->leftJoin('products', 'order_items.product_id', '=', 'products.id')
                  ->select('order_items.*', 'products.thumbnail as product_thumbnail');
        }])
        ->where('id', $id)
        ->orWhere('order_code', $id)
        ->first();

        if ($order) {
            $this->normalizeOrderSnapshot($order);
        }

        if (!$order) {
            $preOrder = PreOrder::where('id', $id)
                ->orWhere('pre_order_code', $id)
                ->first();

            if ($preOrder) {
                $payload = $preOrder->payload;
                $items = $this->normalizePreOrderItems($payload);

                $customerName = trim((string) ($payload['name'] ?? ''));
                $customerPhone = trim((string) ($payload['phone'] ?? ''));
                $storeName = trim((string) ($payload['store_name'] ?? ''));
                $totalAmount = (float) ($preOrder->amount ?? 0);

                return response()->json([
                    'id' => $preOrder->id,
                    'order_code' => $preOrder->pre_order_code,
                    'status' => 'pending_payment',
                    'status_payment' => 'pending',
                    'total' => $totalAmount,
                    'final_amount' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'name' => $customerName,
                    'phone' => $customerPhone,
                    'address' => $payload['address'] ?? '',
                    'payment_method' => $payload['payment_method'] ?? 'BANK',
                    'shipping_method' => $payload['shipping_method'] ?? 'pickup',
                    'created_at' => $preOrder->created_at,
                    'items' => $items,
                    'is_pre_order' => true,
                    'store_name' => $storeName !== '' ? $storeName : 'Cửa hàng Ecoluck',
                    'store' => [
                        'name' => $storeName !== '' ? $storeName : 'Cửa hàng Ecoluck',
                    ]
                ], 200, [], JSON_UNESCAPED_UNICODE);
            }

            return response()->json([
                'message' => 'Đơn hàng không tồn tại.',
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        if ((string) ($order->user_id ?? '') !== (string) $user->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $items = DB::table('order_items')
            ->where('order_id', (string) $order->id)
            ->get();

        $orderTotalAmount = (float) ($order->total_amount ?? $order->final_amount ?? $order->total ?? 0);
        $totalQuantity = (int) $items->sum(function ($item) {
            return max(1, (int) ($item->quantity ?? 1));
        });
        $fallbackUnitPrice = $totalQuantity > 0 && $orderTotalAmount > 0
            ? round($orderTotalAmount / $totalQuantity, 2)
            : 0.0;

        $order->items = $items->map(function ($item) use ($fallbackUnitPrice) {
            $quantity = max(1, (int) ($item->quantity ?? 1));
            $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);

            if ($unitPrice <= 0 && $fallbackUnitPrice > 0) {
                $unitPrice = $fallbackUnitPrice;
            }

            $totalPrice = (float) ($item->total_price ?? 0);
            if ($totalPrice <= 0) {
                $totalPrice = $unitPrice * $quantity;
            }

            $item->unit_price = $unitPrice;
            $item->price = $unitPrice;
            $item->total_price = $totalPrice;

            return $item;
        });
        $order->load('store');

        $payload = $order->toArray();
        [$payload['return_summary'], $payload['active_return']] = $this->buildReturnSummary($order);

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function buildReturnSummary(Order $order): array
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

        if (! Schema::hasTable('order_returns')) {
            return [$emptySummary, null];
        }

        $active = OrderReturn::query()
            ->with('items')
            ->where('order_id', (string) $order->id)
            ->whereNotIn('status', [OrderReturn::STATUS_REFUNDED, OrderReturn::STATUS_CLOSED])
            ->orderByDesc('created_at')
            ->first();

        $latest = OrderReturn::query()
            ->with('items')
            ->where('order_id', (string) $order->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $latest) {
            return [$emptySummary, null];
        }

        $visibleReturn = $active ?: $latest;

        return [[
            'has_return' => true,
            'active_return_id' => $active ? (string) $active->id : null,
            'active_return_status' => $active?->status,
            'active_return_method' => $active?->return_method,
            'active_refund_bank_info' => $active?->refund_bank_info,
            'latest_return_id' => (string) $latest->id,
            'latest_return_status' => $latest->status,
            'latest_return_method' => $latest->return_method,
            'latest_refund_bank_info' => $latest->refund_bank_info,
            'latest_return_code' => $latest->return_code,
            'refund_amount_estimated' => (float) $latest->refund_amount_estimated,
            'refund_amount_final' => (float) $latest->refund_amount_final,
        ], $visibleReturn ? $this->serializeOrderDetailReturn($visibleReturn) : null];
    }

    private function serializeOrderDetailReturn(OrderReturn $return): array
    {
        $return->loadMissing('items');

        return [
            'id' => (string) $return->id,
            'return_code' => $return->return_code,
            'status' => $return->status,
            'return_type' => $return->return_type,
            'refund_method' => $return->refund_method,
            'refund_bank_info' => $return->refund_bank_info,
            'return_method' => $return->return_method,
            'reason_code' => $return->reason_code,
            'reason' => $return->reason,
            'refund_amount_estimated' => (float) $return->refund_amount_estimated,
            'refund_amount_final' => (float) $return->refund_amount_final,
            'refund_currency' => $return->refund_currency,
            'auto_approve_at' => optional($return->auto_approve_at)?->toIso8601String(),
            'rejection_reason' => $return->rejection_reason,
            'tracking_code' => $return->tracking_code,
            'carrier_name' => $return->carrier_name,
            'shipping_receipt_images' => $return->shipping_receipt_images ?? [],
            'shipped_at' => in_array($return->status, ['returning', 'received', 'refund_processing', 'refunded'], true)
                ? optional($return->updated_at)?->toIso8601String()
                : null,
            'dispute_reason' => $return->dispute_reason,
            'dispute_evidence_images' => $return->dispute_evidence_images ?? [],
            'items' => $return->items->map(fn ($item): array => [
                'order_item_id' => (string) $item->order_item_id,
                'quantity' => (int) $item->quantity,
                'refund_amount' => (float) $item->refund_amount,
            ])->values()->all(),
        ];
    }

    private function resolveTicketRewardQuantityFromReference(array $resolvedReference): int
    {
        $variant = $resolvedReference['variant'] ?? null;
        if ($variant instanceof ProductVariant) {
            $rewardQuantity = (int) ($variant->ticket_reward_quantity ?? 0);
            if ($rewardQuantity > 0) {
                return $rewardQuantity;
            }
        }

        $product = $resolvedReference['product'] ?? null;
        if ($product instanceof Product) {
            return max(0, (int) ($product->ticket_reward_quantity ?? 0));
        }

        $variantId = (int) ($resolvedReference['variant_id'] ?? 0);
        if ($variantId > 0) {
            $cachedVariant = $this->findVariantReference($variantId);
            $rewardQuantity = (int) ($cachedVariant?->ticket_reward_quantity ?? 0);
            if ($rewardQuantity > 0) {
                return $rewardQuantity;
            }
        }

        $productId = (int) ($resolvedReference['product_id'] ?? 0);
        if ($productId > 0) {
            $cachedProduct = $this->findProductReference($productId);
            return max(0, (int) ($cachedProduct?->ticket_reward_quantity ?? 0));
        }

        return 0;
    }

    private function resolveMenuItemBasePrice(StoreMenu $menuItem, ?array $resolvedReference = null): float
    {
        $menuPrice = (float) ($menuItem->price ?? 0);
        if ($menuPrice > 0) {
            return $menuPrice;
        }

        $resolvedReference ??= $this->resolveMenuItemStockReference($menuItem);
        $resolvedVariantId = (int) ($resolvedReference['variant_id'] ?? 0);
        $resolvedProductId = (int) ($resolvedReference['product_id'] ?? 0);

        if ($resolvedVariantId > 0) {
            $variant = $resolvedReference['variant'] ?? $this->findVariantReference($resolvedVariantId);
            if ($variant) {
                $variantSalePrice = (float) ($variant->sale_price ?? 0);
                $variantPrice = (float) ($variant->price ?? 0);
                $effectiveVariantPrice = $variantSalePrice > 0 ? $variantSalePrice : $variantPrice;

                if ($effectiveVariantPrice > 0) {
                    return $effectiveVariantPrice;
                }
            }
        }

        if ($resolvedProductId > 0) {
            $product = $resolvedReference['product'] ?? $this->findProductReference($resolvedProductId);
            if ($product) {
                $productSalePrice = (float) ($product->sale_price ?? 0);
                $productPrice = (float) ($product->price ?? 0);
                $effectiveProductPrice = $productSalePrice > 0 ? $productSalePrice : $productPrice;

                if ($effectiveProductPrice > 0) {
                    return $effectiveProductPrice;
                }
            }
        }

        $fallback = $this->resolveProductAndVariantByMenuFallback($menuItem);

        if (!empty($fallback['variant'])) {
            $variant = $fallback['variant'];
            $variantSalePrice = (float) ($variant->sale_price ?? 0);
            $variantPrice = (float) ($variant->price ?? 0);
            $effectiveVariantPrice = $variantSalePrice > 0 ? $variantSalePrice : $variantPrice;

            if ($effectiveVariantPrice > 0) {
                return $effectiveVariantPrice;
            }
        }

        if (!empty($fallback['product'])) {
            $product = $fallback['product'];
            $productSalePrice = (float) ($product->sale_price ?? 0);
            $productPrice = (float) ($product->price ?? 0);
            $effectiveProductPrice = $productSalePrice > 0 ? $productSalePrice : $productPrice;

            if ($effectiveProductPrice > 0) {
                return $effectiveProductPrice;
            }
        }

        return 0.0;
    }

    public function updateAddress(Request $request, $orderId)
    {
        $order = Order::where('id', $orderId)
            ->orWhere('order_code', $orderId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng không tồn tại.',
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $customer = $this->resolveApiUser($request);

        if (!$customer || (string) $order->user_id !== (string) $customer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền chỉnh sửa đơn hàng này.',
            ], 403);
        }

        $currentStatus = strtolower(trim((string) ($order->status ?? '')));
        if (!in_array($currentStatus, ['pending', 'pending_payment'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể chỉnh sửa địa chỉ khi đơn hàng chưa được xác nhận.',
            ], 400);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:500'],
        ]);

        DB::beginTransaction();
        try {
            $order->name = $validated['name'];
            $order->phone = $validated['phone'];
            $order->address = $validated['address'];

            if (Schema::hasColumn('orders', 'delivery_phone')) {
                $order->delivery_phone = $validated['phone'];
            }
            if (Schema::hasColumn('orders', 'delivery_address')) {
                $order->delivery_address = $validated['address'];
            }

            $order->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật địa chỉ nhận hàng thành công.',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating order address: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi cập nhật địa chỉ.',
            ], 500);
        }
    }
}
