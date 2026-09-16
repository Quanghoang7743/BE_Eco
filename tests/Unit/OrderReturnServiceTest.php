<?php

use App\Events\AffiliateWalletUpdated;
use App\Events\MoneyWalletUpdated;
use App\Events\OrderReturnUpdated;
use App\Http\Controllers\Api\AdminOrderReturnController;
use App\Http\Controllers\Api\ClientOrderController;
use App\Http\Controllers\Api\CustomerMoneyWalletController;
use App\Http\Controllers\Api\CustomerOrderReturnController;
use App\Http\Controllers\Api\StoreAdminOrderReturnController;
use App\Http\Controllers\ComplaintController;
use App\Models\MoneyWalletAccount;
use App\Models\AffiliateCommission;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Store;
use App\Models\User;
use App\Services\MoneyWalletService;
use App\Services\AffiliateCommissionService;
use App\Services\ComplaintResolutionService;
use App\Services\ComplaintStoreLockService;
use App\Services\OrderReturnService;
use App\Services\StoreCreditService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderReturnServiceTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        config()->set('ecoluck.ticket_unit_price', 25000);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'store_liability_ledger_entries',
            'financial_reconciliation_offsets',
            'financial_reconciliations',
            'store_ticket_transactions',
            'money_wallet_ledger_entries',
            'money_wallet_accounts',
            'money_wallet_withdrawal_requests',
            'affiliate_wallet_logs',
            'affiliate_commissions',
            'commission_users',
            'store_admin_notifications',
            'complaint_action_logs',
            'complaint_store_locks',
            'complaint_ticket_locks',
            'ticket_ledger',
            'user_balances',
            'tickets',
            'complaints',
            'order_return_items',
            'order_returns',
            'order_items',
            'orders',
            'stores',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_client_cannot_request_return_after_72_hours(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['delivered_at' => now()->subHours(73)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_WINDOW_EXPIRED');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item));
    }

    public function test_client_cannot_return_blocked_items(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $item->forceFill(['return_blocked_at' => now(), 'return_blocked_by_ticket_id' => 'ticket-1'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRODUCT_RETURN_FORFEITED_BY_TICKET_USE');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item));
    }

    public function test_client_cannot_return_more_than_purchased_quantity(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_QUANTITY_EXCEEDED');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item, 2));
    }

    public function test_client_cannot_return_item_from_another_order(): void
    {
        [$user, $order] = $this->makeDeliveredOrder();
        [, , $otherItem] = $this->makeDeliveredOrder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_ITEM_NOT_IN_ORDER');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($otherItem));
    }

    public function test_client_cannot_split_same_item_to_exceed_quantity_in_one_request(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);

        $payload = $this->payloadFor($item);
        $payload['items'][] = [
            'order_item_id' => (string) $item->id,
            'quantity' => 1,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_QUANTITY_EXCEEDED');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $payload);
    }

    public function test_create_return_request_creates_store_admin_notification(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder();

        $return = app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item));

        $notification = DB::table('store_admin_notifications')
            ->where('store_id', (string) $store->id)
            ->where('type', 'return_request')
            ->first();

        $this->assertNotNull($notification);
        $this->assertNull($notification->order_id);
        $this->assertNull($notification->order_code);
        $this->assertSame('Yeu cau hoan hang moi', $notification->title);
        $this->assertStringContainsString('#'.$order->order_code, $notification->message);
        $this->assertSame(0, (int) $notification->is_read);

        $data = json_decode((string) $notification->data, true);
        $this->assertSame((string) $return->id, $data['order_return_id']);
        $this->assertSame((string) $return->return_code, $data['return_code']);
        $this->assertSame((string) $order->id, $data['order_id']);
        $this->assertSame((string) $order->order_code, $data['order_code']);
        $this->assertSame('/admin_store/returns?id='.rawurlencode((string) $return->id), $data['action_url']);
        $this->assertSame(100000.0, (float) $data['amount']);
    }

    public function test_disputed_and_platform_reviewing_returns_hold_return_quantity(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->rejectReturn($user, $return, 'Need evidence');
        $service->disputeReturn($user, $return, ['dispute_reason' => 'Wrong rejection']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_QUANTITY_EXCEEDED');

        $service->createReturnRequest($user, $order, $this->payloadFor($item));
    }

    public function test_dispute_return_is_idempotent_after_platform_review_started(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->rejectReturn($user, $return, 'Need evidence');
        $return = $service->disputeReturn($user, $return, ['dispute_reason' => 'Wrong rejection']);

        $again = $service->disputeReturn($user, $return, ['dispute_reason' => 'Clicked again']);

        $this->assertSame(OrderReturn::STATUS_PLATFORM_REVIEWING, $again->status);
        $this->assertSame('Wrong rejection', $again->dispute_reason);
    }

    public function test_rejected_return_cannot_be_disputed_after_quantity_is_reused(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);
        $service = app(OrderReturnService::class);
        $rejectedReturn = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $service->rejectReturn($user, $rejectedReturn, 'Need more evidence');
        $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_QUANTITY_EXCEEDED');

        $service->disputeReturn($user, $rejectedReturn, ['dispute_reason' => 'Shop rejected incorrectly']);
    }

    public function test_unsupported_refund_method_is_rejected_before_request_creation(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $payload = $this->payloadFor($item);
        $payload['refund_method'] = 'bank_transfer';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNSUPPORTED_REFUND_METHOD');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $payload);
    }

    public function test_high_risk_return_reason_requires_customer_evidence(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $payload = $this->payloadFor($item);
        $payload['reason_code'] = 'DAMAGED_ON_DELIVERY';
        unset($payload['images'], $payload['video']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_EVIDENCE_REQUIRED');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $payload);
    }

    public function test_customer_ship_requires_shipping_receipt_images(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $request = Request::create('/api/customer/order-returns/'.$return->id.'/ship', 'POST', [
            'tracking_code' => 'SPX_MISSING_RECEIPT',
            'carrier_name' => 'SPX',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->ship($request, (string) $return->id);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $payload['errors'][0]['code']);
        $this->assertArrayHasKey('shipping_receipt_images', $payload['errors'][0]['details']);
    }

    public function test_shop_approve_waits_for_customer_delivery_evidence_before_returning(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $approved = $service->approveReturn(
            $user,
            $return,
            'GHN123456',
            'GHN',
            (string) $store->id,
            'shipping'
        );

        $this->assertSame(OrderReturn::STATUS_APPROVED, $approved->status);
        $this->assertSame('GHN123456', $approved->tracking_code);
        $this->assertSame('GHN', $approved->carrier_name);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_RETURN_STATE');

        $service->receiveReturn($user, $approved, true, [], (string) $store->id);
    }

    public function test_customer_return_upload_accepts_legacy_image_field(): void
    {
        Storage::fake('public');
        $user = $this->makeUser('user');
        $request = Request::create('/api/customer/order-returns/upload', 'POST', [], [], [
            'image' => UploadedFile::fake()->image('proof.jpg', 100, 100),
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->upload($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringStartsWith('uploads/returns/'.$user->id.'/', $payload['data']['path']);
        Storage::disk('public')->assertExists($payload['data']['path']);
    }

    public function test_store_return_upload_scopes_evidence_to_store(): void
    {
        Storage::fake('public');
        [$user, , , $store] = $this->makeDeliveredOrder();
        $request = Request::create('/api/store-admin/order-returns/upload?store_id='.$store->id, 'POST', [], [], [
            'file' => UploadedFile::fake()->image('shop-proof.jpg', 100, 100),
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(StoreAdminOrderReturnController::class)->upload($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringStartsWith('uploads/shop_returns/'.$store->id.'/', $payload['data']['path']);
        Storage::disk('public')->assertExists($payload['data']['path']);
    }

    public function test_customer_ship_saves_shipping_receipt_images(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $receiptImages = [
            'uploads/returns/'.$user->id.'/shipping-receipt-1.jpg',
            'uploads/returns/'.$user->id.'/shipping-receipt-2.webp',
        ];
        $request = Request::create('/api/customer/order-returns/'.$return->id.'/ship', 'POST', [
            'tracking_code' => 'SPX_WITH_RECEIPT',
            'carrier_name' => 'SPX',
            'shipping_receipt_images' => $receiptImages,
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->ship($request, (string) $return->id);
        $payload = $response->getData(true);
        $return->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(OrderReturn::STATUS_RETURNING, $payload['data']['status']);
        $this->assertSame($receiptImages, $payload['data']['shipping_receipt_images']);
        $this->assertSame($receiptImages, $return->shipping_receipt_images);
    }

    public function test_customer_at_store_arrival_moves_approved_return_to_returning(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['shipping_method' => 'pickup']);
        $service = app(OrderReturnService::class);
        $payload = $this->payloadFor($item);
        $payload['return_method'] = 'at_store';
        $return = $service->createReturnRequest($user, $order, $payload);
        $return->forceFill(['status' => OrderReturn::STATUS_APPROVED])->save();

        $request = Request::create('/api/customer/order-returns/'.$return->id.'/arrive-at-store', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->arriveAtStore($request, (string) $return->id);
        $payload = $response->getData(true);
        $return->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(OrderReturn::STATUS_RETURNING, $payload['data']['status']);
        $this->assertSame(OrderReturn::STATUS_RETURNING, $return->status);
    }

    public function test_customer_at_store_arrival_rejects_shipping_return(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);

        $request = Request::create('/api/customer/order-returns/'.$return->id.'/arrive-at-store', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->arriveAtStore($request, (string) $return->id);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('RETURN_METHOD_AT_STORE_NOT_ALLOWED', $payload['errors'][0]['code']);
        $this->assertSame(OrderReturn::STATUS_APPROVED, OrderReturn::query()->findOrFail($return->id)->status);
    }

    public function test_refund_is_idempotent_and_uses_platform_advance_when_store_wallet_is_negative(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX123', 'SPX'));
        $return = $service->receiveReturn($user, $return, true, [
            'shop_received_note' => 'OK',
        ]);

    public function test_rejected_return_cannot_be_disputed_after_quantity_is_reused(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);
        $service = app(OrderReturnService::class);
        $rejectedReturn = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $service->rejectReturn($user, $rejectedReturn, 'Need more evidence');
        $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_QUANTITY_EXCEEDED');

        $service->disputeReturn($user, $rejectedReturn, ['dispute_reason' => 'Shop rejected incorrectly']);
    }

    public function test_unsupported_refund_method_is_rejected_before_request_creation(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $payload = $this->payloadFor($item);
        $payload['refund_method'] = 'bank_transfer';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNSUPPORTED_REFUND_METHOD');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $payload);
    }

    public function test_high_risk_return_reason_requires_customer_evidence(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $payload = $this->payloadFor($item);
        $payload['reason_code'] = 'DAMAGED_ON_DELIVERY';
        unset($payload['images'], $payload['video']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_EVIDENCE_REQUIRED');

        app(OrderReturnService::class)->createReturnRequest($user, $order, $payload);
    }

    public function test_customer_ship_requires_shipping_receipt_images(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $request = Request::create('/api/customer/order-returns/'.$return->id.'/ship', 'POST', [
            'tracking_code' => 'SPX_MISSING_RECEIPT',
            'carrier_name' => 'SPX',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->ship($request, (string) $return->id);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $payload['errors'][0]['code']);
        $this->assertArrayHasKey('shipping_receipt_images', $payload['errors'][0]['details']);
    }

    public function test_shop_approve_waits_for_customer_delivery_evidence_before_returning(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $approved = $service->approveReturn(
            $user,
            $return,
            'GHN123456',
            'GHN',
            (string) $store->id,
            'shipping'
        );

        $this->assertSame(OrderReturn::STATUS_APPROVED, $approved->status);
        $this->assertSame('GHN123456', $approved->tracking_code);
        $this->assertSame('GHN', $approved->carrier_name);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_RETURN_STATE');

        $service->receiveReturn($user, $approved, true, [], (string) $store->id);
    }

    public function test_customer_return_upload_accepts_legacy_image_field(): void
    {
        Storage::fake('public');
        $user = $this->makeUser('user');
        $request = Request::create('/api/customer/order-returns/upload', 'POST', [], [], [
            'image' => UploadedFile::fake()->image('proof.jpg', 100, 100),
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->upload($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringStartsWith('uploads/returns/'.$user->id.'/', $payload['data']['path']);
        Storage::disk('public')->assertExists($payload['data']['path']);
    }

    public function test_store_return_upload_scopes_evidence_to_store(): void
    {
        Storage::fake('public');
        [$user, , , $store] = $this->makeDeliveredOrder();
        $request = Request::create('/api/store-admin/order-returns/upload?store_id='.$store->id, 'POST', [], [], [
            'file' => UploadedFile::fake()->image('shop-proof.jpg', 100, 100),
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(StoreAdminOrderReturnController::class)->upload($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringStartsWith('uploads/shop_returns/'.$store->id.'/', $payload['data']['path']);
        Storage::disk('public')->assertExists($payload['data']['path']);
    }

    public function test_customer_ship_saves_shipping_receipt_images(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $receiptImages = [
            'uploads/returns/'.$user->id.'/shipping-receipt-1.jpg',
            'uploads/returns/'.$user->id.'/shipping-receipt-2.webp',
        ];
        $request = Request::create('/api/customer/order-returns/'.$return->id.'/ship', 'POST', [
            'tracking_code' => 'SPX_WITH_RECEIPT',
            'carrier_name' => 'SPX',
            'shipping_receipt_images' => $receiptImages,
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->ship($request, (string) $return->id);
        $payload = $response->getData(true);
        $return->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(OrderReturn::STATUS_RETURNING, $payload['data']['status']);
        $this->assertSame($receiptImages, $payload['data']['shipping_receipt_images']);
        $this->assertSame($receiptImages, $return->shipping_receipt_images);
    }

    public function test_customer_at_store_arrival_moves_approved_return_to_returning(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['shipping_method' => 'pickup']);
        $service = app(OrderReturnService::class);
        $payload = $this->payloadFor($item);
        $payload['return_method'] = 'at_store';
        $return = $service->createReturnRequest($user, $order, $payload);
        $return->forceFill(['status' => OrderReturn::STATUS_APPROVED])->save();

        $request = Request::create('/api/customer/order-returns/'.$return->id.'/arrive-at-store', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->arriveAtStore($request, (string) $return->id);
        $payload = $response->getData(true);
        $return->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(OrderReturn::STATUS_RETURNING, $payload['data']['status']);
        $this->assertSame(OrderReturn::STATUS_RETURNING, $return->status);
    }

    public function test_customer_at_store_arrival_rejects_shipping_return(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);

        $request = Request::create('/api/customer/order-returns/'.$return->id.'/arrive-at-store', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerOrderReturnController::class)->arriveAtStore($request, (string) $return->id);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('RETURN_METHOD_AT_STORE_NOT_ALLOWED', $payload['errors'][0]['code']);
        $this->assertSame(OrderReturn::STATUS_APPROVED, OrderReturn::query()->findOrFail($return->id)->status);
    }

    public function test_refund_is_idempotent_and_uses_platform_advance_when_store_wallet_is_negative(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX123', 'SPX'));
        $return = $service->receiveReturn($user, $return, true, [
            'shop_received_note' => 'OK',
        ]);

        $second = $service->processRefund($return, $user);
        $wallet = MoneyWalletAccount::query()
            ->where('owner_type', 'user')
            ->where('owner_id', (string) $user->id)
            ->firstOrFail();

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $second->status);
        $this->assertSame(100000.0, (float) $wallet->available_balance);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $return->id)->count());
        $this->assertSame(1, DB::table('store_liability_ledger_entries')->where('source_id', (string) $return->id)->where('direction', 'debt_increase')->count());
        $this->assertSame(0.0, (float) Store::query()->findOrFail($store->id)->wallet_balance);
        $this->assertSame(100000.0, (float) Store::query()->findOrFail($store->id)->ticket_debt);
    }

    public function test_store_wallet_settlement_for_at_store_return_keeps_customer_return_method(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['shipping_method' => 'pickup']);
        $service = app(OrderReturnService::class);
        $payload = $this->payloadFor($item);
        $payload['return_method'] = 'at_store';
        $return = $service->createReturnRequest($user, $order, $payload);

        $approved = $service->approveReturn($user, $return, null, null, (string) $store->id, null, 'wallet');

        $this->assertSame(OrderReturn::STATUS_APPROVED, $approved->status);
        $this->assertSame('at_store', $approved->return_method);
        $this->assertNull($approved->tracking_code);
        $this->assertNull($approved->carrier_name);
        $this->assertNull($approved->refund_funding_source);

        $arrived = $service->markAtStoreReturnArrived($user, $approved);
        $this->assertSame(OrderReturn::STATUS_RETURNING, $arrived->status);

        $refunded = $service->receiveReturn($user, $arrived, true, [], (string) $store->id);

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $refunded->status);
        $this->assertSame('at_store', $refunded->return_method);
        $this->assertNotSame('manual_at_store', $refunded->refund_funding_source);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $return->id)->count());
    }

    public function test_refund_recalculates_amount_from_order_items_before_crediting_wallet(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_RECALC'));

        $return->forceFill(['refund_amount_estimated' => 1])->save();
        $return->items()->update([
            'unit_price_snapshot' => 1,
            'gross_amount' => 1,
            'refund_amount' => 1,
        ]);

        $return = $service->receiveReturn($user, $return, true, []);
        $wallet = MoneyWalletAccount::query()
            ->where('owner_type', 'user')
            ->where('owner_id', (string) $user->id)
            ->firstOrFail();

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $return->status);
        $this->assertSame(100000.0, (float) $return->refund_amount_final);
        $this->assertSame(100000.0, (float) $wallet->available_balance);
        $this->assertSame(100000.0, (float) $return->items()->firstOrFail()->refund_amount);
    }

    public function test_return_request_cancels_pending_affiliate_history_but_keeps_available_balance_from_user_table(): void
    {
        Event::fake([AffiliateWalletUpdated::class]);

        [$user, $order, $item] = $this->makeDeliveredOrder();
        $affiliate = $this->makeUser('affiliate');
        DB::table('users')->where('id', $affiliate->id)->update(['balance' => 12345]);
        $this->seedAffiliateCommission($affiliate, $user, $order, $item, AffiliateCommission::STATUS_WAITING_RETURN_PERIOD, 2618);

        app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item));

        $commission = AffiliateCommission::query()->where('order_item_id', (string) $item->id)->firstOrFail();
        $summary = app(AffiliateCommissionService::class)->getDashboardSummary((int) $affiliate->id);

        $this->assertSame(AffiliateCommission::STATUS_CANCELLED, $commission->status);
        $this->assertSame(0.0, $summary['commission_pending']);
        $this->assertSame(0.0, $summary['pending_balance']);
        $this->assertSame(0.0, $summary['total_income']);
        $this->assertSame(0.0, $summary['commission_withdrawn']);
        $this->assertSame(12345.0, $summary['available_balance']);
        $this->assertSame('cancel', DB::table('commission_users')->where('order_item_id', (string) $item->id)->value('status'));

        Event::assertDispatched(AffiliateWalletUpdated::class, function (AffiliateWalletUpdated $event) use ($affiliate): bool {
            return $event->userId === (int) $affiliate->id
                && $event->action === 'affiliate_commission_cancelled'
                && (float) ($event->payload['summary']['commission_pending'] ?? -1) === 0.0
                && (float) ($event->payload['summary']['available_balance'] ?? -1) === 12345.0
                && array_key_exists('ledger_entry', $event->payload)
                && $event->payload['ledger_entry'] === null;
        });
    }

    public function test_refund_cancels_pending_affiliate_commission_immediately(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $affiliate = $this->makeUser('affiliate');
        $this->seedAffiliateCommission($affiliate, $user, $order, $item, AffiliateCommission::STATUS_WAITING_RETURN_PERIOD, 2618);

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_AFF_PENDING'));
        $service->receiveReturn($user, $return, true, []);

        $commission = AffiliateCommission::query()->where('order_item_id', (string) $item->id)->firstOrFail();
        $summary = app(AffiliateCommissionService::class)->getDashboardSummary((int) $affiliate->id);

        $this->assertSame(AffiliateCommission::STATUS_CANCELLED, $commission->status);
        $this->assertSame(0.0, (float) $commission->approved_amount);
        $this->assertSame(0.0, (float) $commission->payout_amount);
        $this->assertSame(0.0, $summary['commission_pending']);
        $this->assertSame(0.0, $summary['pending_balance']);
        $this->assertSame('cancel', DB::table('commission_users')->where('order_item_id', (string) $item->id)->value('status'));
    }

    public function test_refund_restores_store_wallet_affiliate_deduction_immediately(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 200000]);
        $affiliate = $this->makeUser('affiliate');
        $commission = $this->seedAffiliateCommission($affiliate, $user, $order, $item, AffiliateCommission::STATUS_WAITING_RETURN_PERIOD, 2618);

        app(StoreCreditService::class)->deductAffiliateCommissionForCommission(
            $commission->fresh(['order', 'orderItem']),
            2618,
        );

        $this->assertSame(197382.0, (float) Store::query()->findOrFail($store->id)->wallet_balance);
        $this->assertSame(-2618.0, (float) DB::table('store_ticket_transactions')
            ->where('event_type', 'AFFILIATE_COMMISSION_DEDUCTION')
            ->where('source_type', 'AFFILIATE_COMMISSION')
            ->where('source_id', (string) $commission->id)
            ->value('wallet_delta'));

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $this->assertSame(200000.0, (float) Store::query()->findOrFail($store->id)->wallet_balance);
        $this->assertSame(2618.0, (float) DB::table('store_ticket_transactions')
            ->where('event_type', 'AFFILIATE_COMMISSION_REFUND')
            ->where('source_type', 'AFFILIATE_COMMISSION')
            ->where('source_id', (string) $commission->id)
            ->value('wallet_delta'));

        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_AFF_STORE_REFUND'));
        $service->receiveReturn($user, $return, true, []);

        $this->assertSame(100000.0, (float) Store::query()->findOrFail($store->id)->wallet_balance);
        $this->assertSame(2618.0, (float) DB::table('store_ticket_transactions')
            ->where('event_type', 'AFFILIATE_COMMISSION_REFUND')
            ->where('source_type', 'AFFILIATE_COMMISSION')
            ->where('source_id', (string) $commission->id)
            ->value('wallet_delta'));
    }

    public function test_refund_deducts_paid_affiliate_commission_wallet_immediately(): void
    {
        Event::fake([AffiliateWalletUpdated::class]);

        [$user, $order, $item] = $this->makeDeliveredOrder();
        $affiliate = $this->makeUser('affiliate');
        DB::table('users')->where('id', $affiliate->id)->update(['balance' => 2618]);
        $this->seedAffiliateCommission($affiliate, $user, $order, $item, AffiliateCommission::STATUS_PAID, 2618, seedLegacy: false);

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_AFF_PAID'));
        $service->receiveReturn($user, $return, true, []);

        $commission = AffiliateCommission::query()->where('order_item_id', (string) $item->id)->firstOrFail();
        $summary = app(AffiliateCommissionService::class)->getDashboardSummary((int) $affiliate->id);
        $walletLog = DB::table('affiliate_wallet_logs')
            ->where('reference_id', (string) $commission->id)
            ->where('action_type', 'affiliate_commission_deduction')
            ->first();

        $this->assertSame(AffiliateCommission::STATUS_CLAWBACK, $commission->status);
        $this->assertSame(0.0, (float) DB::table('users')->where('id', $affiliate->id)->value('balance'));
        $this->assertSame(0.0, $summary['available_balance']);
        $this->assertSame(0.0, $summary['total_income']);
        $this->assertNotNull($walletLog);
        $this->assertSame(-2618, (int) $walletLog->amount_change);
        $this->assertSame(2618, (int) $walletLog->balance_before);
        $this->assertSame(0, (int) $walletLog->balance_after);

        Event::assertDispatched(AffiliateWalletUpdated::class, function (AffiliateWalletUpdated $event) use ($affiliate): bool {
            return $event->userId === (int) $affiliate->id
                && $event->action === 'affiliate_commission_deduction'
                && ($event->payload['summary']['available_balance'] ?? null) === 0.0;
        });
    }

    public function test_refund_does_not_double_deduct_paid_affiliate_commission_when_legacy_was_reversed(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $affiliate = $this->makeUser('affiliate');
        DB::table('users')->where('id', $affiliate->id)->update(['balance' => 2618]);
        $this->seedAffiliateCommission($affiliate, $user, $order, $item, AffiliateCommission::STATUS_PAID, 2618);

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $this->assertSame(0.0, (float) DB::table('users')->where('id', $affiliate->id)->value('balance'));
        $this->assertSame('cancel', DB::table('commission_users')->where('order_item_id', (string) $item->id)->value('status'));

        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_AFF_LEGACY_PAID'));
        $service->receiveReturn($user, $return, true, []);

        $commission = AffiliateCommission::query()->where('order_item_id', (string) $item->id)->firstOrFail();
        $walletLog = DB::table('affiliate_wallet_logs')
            ->where('reference_id', (string) $commission->id)
            ->where('action_type', 'affiliate_commission_deduction')
            ->first();

        $this->assertSame(AffiliateCommission::STATUS_CLAWBACK, $commission->status);
        $this->assertSame(0.0, (float) DB::table('users')->where('id', $affiliate->id)->value('balance'));
        $this->assertNotNull($walletLog);
        $this->assertSame(-2618, (int) $walletLog->amount_change);
    }

    public function test_refund_rechecks_quantity_against_other_open_returns(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 1]);
        $service = app(OrderReturnService::class);
        $staleReturn = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $service->rejectReturn($user, $staleReturn, 'Need more evidence');
        $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $staleReturn->forceFill(['status' => OrderReturn::STATUS_RECEIVED])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_QUANTITY_EXCEEDED');

        $service->processRefund($staleReturn, $user);
    }

    public function test_refund_rechecks_ticket_return_waiver_before_crediting_wallet(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_BLOCKED'));

        $item->forceFill([
            'return_blocked_at' => now(),
            'return_blocked_by_ticket_id' => 'ticket-after-return',
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRODUCT_RETURN_FORFEITED_BY_TICKET_USE');

        try {
            $service->receiveReturn($user, $return, true, []);
        } finally {
            $this->assertSame(0, DB::table('money_wallet_ledger_entries')
                ->where('source_type', 'order_return')
                ->where('source_id', (string) $return->id)
                ->count());
        }
    }

    public function test_refund_credit_rejects_existing_source_with_different_idempotency_key(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $walletService = app(MoneyWalletService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $walletService->creditRefund($user, $return, 100000, 'refund:manual-duplicate-source');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DUPLICATE_REFUND_SOURCE');

        $walletService->creditRefund($user, $return, 100000, 'refund:manual-duplicate-source-2');
    }

    public function test_platform_reviewing_return_cannot_be_refunded_without_admin_resolution_transition(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return->forceFill(['status' => OrderReturn::STATUS_PLATFORM_REVIEWING])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_RETURN_STATE');

        $service->processRefund($return, $user);
    }

    public function test_full_order_refund_marks_order_refunded(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);

        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_FULL'));
        $service->receiveReturn($user, $return, true, []);

        $this->assertSame('refunded', Order::query()->findOrFail($order->id)->status);
    }

    public function test_partial_order_refund_keeps_order_delivered(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['quantity' => 2]);
        $service = app(OrderReturnService::class);

        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item, 1));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_PARTIAL'));
        $service->receiveReturn($user, $return, true, []);

        $this->assertSame('delivered', Order::query()->findOrFail($order->id)->status);
    }

    public function test_customer_order_list_exposes_latest_return_summary(): void
    {
        $user = $this->makeUser('return-list-user');
        $order = Order::query()->create([
            'id' => 'order-return-list',
            'order_code' => 'ORDER-RETURN-LIST',
            'user_id' => $user->id,
            'store_id' => '',
            'status' => 'delivered',
            'total_amount' => 100000,
            'final_amount' => 100000,
            'delivered_at' => now(),
        ]);
        $return = OrderReturn::query()->create([
            'return_code' => 'RET-LIST-1',
            'order_id' => (string) $order->id,
            'user_id' => (string) $user->id,
            'store_id' => '',
            'status' => OrderReturn::STATUS_REFUNDED,
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason' => 'Customer returned item.',
            'refund_amount_estimated' => 100000,
            'refund_amount_final' => 100000,
            'refund_currency' => 'VND',
        ]);

        $request = Request::create('/api/orders', 'GET');
        $request->setUserResolver(fn () => $user);
        $response = app(ClientOrderController::class)->indexOrder($request);
        $payload = $response->getData(true);
        $listedOrder = collect($payload)->firstWhere('id', (string) $order->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($listedOrder);
        $this->assertTrue($listedOrder['return_summary']['has_return']);
        $this->assertNull($listedOrder['return_summary']['active_return_status']);
        $this->assertSame(OrderReturn::STATUS_REFUNDED, $listedOrder['return_summary']['latest_return_status']);
        $this->assertSame((string) $return->id, $listedOrder['return_summary']['latest_return_id']);
    }

    public function test_refund_offsets_pending_settlement_receivable_before_platform_advance(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        DB::table('financial_reconciliations')->insert([
            'id' => 'recon-'.uniqid(),
            'store_id' => $store->id,
            'period' => 'daily',
            'period_date' => now()->toDateString(),
            'status' => 'pending_shop',
            'net_amount' => 60000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX456'));
        $return = $service->receiveReturn($user, $return, true, []);

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $return->status);
        $this->assertSame('platform_advance', $return->refund_funding_source);
        $this->assertSame(40000.0, (float) $return->platform_advanced_amount);
        $this->assertSame(60000.0, (float) ($return->funding_breakdown['pending_settlement_offset'] ?? 0));
        $this->assertSame(40000.0, (float) ($return->funding_breakdown['platform_advance'] ?? 0));
        $this->assertSame(1, DB::table('store_liability_ledger_entries')
            ->where('source_id', (string) $return->id)
            ->where('funding_source', 'pending_settlement_receivable')
            ->count());
        $this->assertSame(1, DB::table('financial_reconciliation_offsets')
            ->where('order_return_id', (string) $return->id)
            ->where('amount', 60000)
            ->count());
        $this->assertSame(1, DB::table('store_liability_ledger_entries')
            ->where('source_id', (string) $return->id)
            ->where('funding_source', 'platform_advance')
            ->where('direction', 'debt_increase')
            ->count());
        $this->assertSame(0.0, (float) DB::table('financial_reconciliations')->where('store_id', $store->id)->value('net_amount'));
        $this->assertSame(40000.0, (float) Store::query()->findOrFail($store->id)->ticket_debt);
    }

    public function test_refund_offsets_waiting_payment_settlement_and_ignores_paid_settlement(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        DB::table('financial_reconciliations')->insert([
            [
                'id' => 'recon-paid-'.uniqid(),
                'store_id' => $store->id,
                'period' => 'daily',
                'period_date' => now()->subDay()->toDateString(),
                'status' => 'waiting_payment',
                'net_amount' => 50000,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'recon-waiting-'.uniqid(),
                'store_id' => $store->id,
                'period' => 'daily',
                'period_date' => now()->toDateString(),
                'status' => 'waiting_payment',
                'net_amount' => 70000,
                'paid_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_WAITING'));
        $return = $service->receiveReturn($user, $return, true, []);

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $return->status);
        $this->assertSame('platform_advance', $return->refund_funding_source);
        $this->assertSame(30000.0, (float) $return->platform_advanced_amount);
        $this->assertSame(50000.0, (float) DB::table('financial_reconciliations')->where('status', 'waiting_payment')->whereNotNull('paid_at')->value('net_amount'));
        $this->assertSame(0.0, (float) DB::table('financial_reconciliations')->where('status', 'waiting_payment')->whereNull('paid_at')->value('net_amount'));
        $this->assertSame(30000.0, (float) Store::query()->findOrFail($store->id)->ticket_debt);
    }

    public function test_refund_funding_resolver_is_idempotent(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 100000]);
        $service = app(OrderReturnService::class);
        $resolver = app(\App\Services\RefundFundingResolver::class);

        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        
        $decision1 = $resolver->applyFundingDecision($return, 100000);
        $storeRefreshed1 = Store::query()->findOrFail($store->id);
        
        $this->assertSame(0.0, (float) $storeRefreshed1->wallet_balance);
        $this->assertSame('store_wallet_balance', $decision1['funding_source']);
        $this->assertSame(100000.0, $decision1['wallet_debit']);

        $decision2 = $resolver->applyFundingDecision($return, 100000);
        $storeRefreshed2 = Store::query()->findOrFail($store->id);

        $this->assertSame(0.0, (float) $storeRefreshed2->wallet_balance);
        $this->assertSame('store_wallet_balance', $decision2['funding_source']);
        $this->assertSame(100000.0, $decision2['wallet_debit']);
        
        $this->assertSame(1, DB::table('store_liability_ledger_entries')->where('source_id', (string) $return->id)->count());
    }

    public function test_refund_drift_adjustment_on_final_return(): void
    {
        $user = $this->makeUser('user');
        $store = Store::query()->create([
            'id' => 'store-drift-test',
            'name' => 'Store Drift Test',
            'wallet_balance' => 1000000,
        ]);

        $order = Order::query()->create([
            'id' => 'order-drift-test',
            'order_code' => 'ORD-DRIFT-123',
            'user_id' => $user->id,
            'store_id' => $store->id,
            'status' => 'delivered',
            'total_amount' => 300000,
            'discount_amount' => 50000,
            'final_amount' => 250000,
            'delivered_at' => now(),
        ]);

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = OrderItem::query()->create([
                'id' => 'item-drift-'.$i,
                'order_id' => $order->id,
                'product_id' => 100 + $i,
                'product_name' => 'Item '.$i,
                'quantity' => 1,
                'unit_price' => 100000,
                'total_price' => 100000,
            ]);
        }

        $service = app(OrderReturnService::class);

        $ret1 = $service->createReturnRequest($user, $order, [
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason' => 'Return 1',
            'items' => [['order_item_id' => 'item-drift-1', 'quantity' => 1]]
        ]);
        $this->assertEquals(83333.33, (float) $ret1->refund_amount_estimated);
        $ret1 = $service->approveReturn($user, $ret1);
        $ret1 = $service->shipReturn($user, $ret1, $this->shipPayload('SPX_D1'));
        $ret1 = $service->receiveReturn($user, $ret1, true, []);
        $this->assertEquals(83333.33, (float) $ret1->refund_amount_final);

        $ret2 = $service->createReturnRequest($user, $order, [
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason' => 'Return 2',
            'items' => [['order_item_id' => 'item-drift-2', 'quantity' => 1]]
        ]);
        $this->assertEquals(83333.33, (float) $ret2->refund_amount_estimated);
        $ret2 = $service->approveReturn($user, $ret2);
        $ret2 = $service->shipReturn($user, $ret2, $this->shipPayload('SPX_D2'));
        $ret2 = $service->receiveReturn($user, $ret2, true, []);
        $this->assertEquals(83333.33, (float) $ret2->refund_amount_final);

        $ret3 = $service->createReturnRequest($user, $order, [
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason' => 'Return 3',
            'items' => [['order_item_id' => 'item-drift-3', 'quantity' => 1]]
        ]);
        
        $this->assertEquals(83333.34, (float) $ret3->refund_amount_estimated);
        
        $ret3 = $service->approveReturn($user, $ret3);
        $ret3 = $service->shipReturn($user, $ret3, $this->shipPayload('SPX_D3'));
        $ret3 = $service->receiveReturn($user, $ret3, true, []);
        $this->assertEquals(83333.34, (float) $ret3->refund_amount_final);

        $totalRefunded = (float) OrderReturn::query()
            ->where('order_id', $order->id)
            ->sum('refund_amount_final');
        $this->assertEquals(250000.0, $totalRefunded);
    }

    public function test_final_return_uses_total_amount_when_final_amount_is_zero(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $order->forceFill([
            'total_amount' => 100000,
            'final_amount' => 0,
            'discount_amount' => 0,
        ])->save();

        $return = app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item));

        $this->assertSame(100000.0, (float) $return->refund_amount_estimated);
    }

    public function test_final_return_caps_refund_to_order_final_amount_when_discount_snapshot_is_missing(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $item2 = OrderItem::query()->create([
            'id' => 'item-final-cap-2',
            'order_id' => $order->id,
            'product_name' => 'Test product 2',
            'item_name' => 'Test product 2',
            'quantity' => 1,
            'unit_price' => 100000,
            'price' => 100000,
            'total_price' => 100000,
        ]);
        $order->forceFill([
            'total_amount' => 200000,
            'final_amount' => 150000,
            'discount_amount' => 0,
        ])->save();

        $service = app(OrderReturnService::class);
        $first = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $first = $service->approveReturn($user, $first);
        $first = $service->shipReturn($user, $first, $this->shipPayload('SPX_CAP_1'));
        $service->receiveReturn($user, $first, true, []);

        $second = $service->createReturnRequest($user, $order, $this->payloadFor($item2));
        $this->assertSame(50000.0, (float) $second->refund_amount_estimated);

        $second = $service->approveReturn($user, $second);
        $second = $service->shipReturn($user, $second, $this->shipPayload('SPX_CAP_2'));
        $second = $service->receiveReturn($user, $second, true, []);

        $totalRefunded = (float) OrderReturn::query()
            ->where('order_id', $order->id)
            ->sum('refund_amount_final');
        $this->assertSame(150000.0, $totalRefunded);
    }

    public function test_auto_close_expired_rejected_and_disputed_returns_after_72_hours(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['quantity' => 3]);
        $service = app(OrderReturnService::class);
        $item2 = OrderItem::query()->create([
            'id' => 'item-auto-close-2',
            'order_id' => $order->id,
            'product_name' => 'Test product 2',
            'item_name' => 'Test product 2',
            'quantity' => 1,
            'unit_price' => 100000,
            'price' => 100000,
            'total_price' => 100000,
        ]);
        $item3 = OrderItem::query()->create([
            'id' => 'item-auto-close-3',
            'order_id' => $order->id,
            'product_name' => 'Test product 3',
            'item_name' => 'Test product 3',
            'quantity' => 1,
            'unit_price' => 100000,
            'price' => 100000,
            'total_price' => 100000,
        ]);

        $ret1 = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $ret1 = $service->rejectReturn($user, $ret1, 'Need evidence');
        $ret1->forceFill(['updated_at' => now()->subHours(73)])->save();

        $ret2 = $service->createReturnRequest($user, $order, $this->payloadFor($item2));
        $ret2 = $service->approveReturn($user, $ret2);
        $ret2 = $service->shipReturn($user, $ret2, $this->shipPayload('SPX_D2'));
        $ret2 = $service->receiveReturn($user, $ret2, false, ['images' => ['uploads/shop_returns/'.$store->id.'/check.jpg']]);
        $ret2->forceFill(['updated_at' => now()->subHours(73)])->save();

        $ret3 = $service->createReturnRequest($user, $order, $this->payloadFor($item3));
        $ret3 = $service->rejectReturn($user, $ret3, 'Need evidence');
        $ret3->forceFill(['updated_at' => now()->subHours(71)])->save();

        $closedCount = $service->autoCloseExpiredCustomerDisputes();

        $this->assertSame(2, $closedCount);
        $this->assertSame(OrderReturn::STATUS_CLOSED, OrderReturn::query()->findOrFail($ret1->id)->status);
        $this->assertSame(OrderReturn::STATUS_CLOSED, OrderReturn::query()->findOrFail($ret2->id)->status);
        $this->assertSame(OrderReturn::STATUS_REJECTED, OrderReturn::query()->findOrFail($ret3->id)->status);
    }

    public function test_store_scoped_return_action_rejects_other_store(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_STORE_FORBIDDEN');

        $service->approveReturn($user, $return, null, null, 'store-other');
    }

    public function test_store_wallet_settlement_for_at_store_return_keeps_customer_return_method(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['shipping_method' => 'pickup']);
        $service = app(OrderReturnService::class);
        $payload = $this->payloadFor($item);
        $payload['return_method'] = 'at_store';
        $return = $service->createReturnRequest($user, $order, $payload);

        $approved = $service->approveReturn($user, $return, null, null, (string) $store->id, null, 'wallet');

        $this->assertSame(OrderReturn::STATUS_RETURNING, $approved->status);
        $this->assertSame('at_store', $approved->return_method);
        $this->assertNull($approved->tracking_code);
        $this->assertNull($approved->carrier_name);
        $this->assertNull($approved->refund_funding_source);

        $refunded = $service->receiveReturn($user, $approved, true, [], (string) $store->id);

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $refunded->status);
        $this->assertSame('at_store', $refunded->return_method);
        $this->assertNotSame('manual_at_store', $refunded->refund_funding_source);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $return->id)->count());
    }

    public function test_manual_at_store_settlement_skips_customer_wallet_credit(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['shipping_method' => 'pickup']);
        $service = app(OrderReturnService::class);
        $payload = $this->payloadFor($item);
        $payload['return_method'] = 'at_store';
        $return = $service->createReturnRequest($user, $order, $payload);

        $approved = $service->approveReturn($user, $return, null, null, (string) $store->id, null, 'manual_at_store');
        $arrived = $service->markAtStoreReturnArrived($user, $approved);
        $refunded = $service->receiveReturn($user, $arrived, true, [], (string) $store->id);

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $refunded->status);
        $this->assertSame('manual_at_store', $refunded->refund_funding_source);
        $this->assertSame(0, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $return->id)->count());
    }

    public function test_shipping_return_rejects_manual_at_store_settlement(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder(['shipping_method' => 'pickup']);
        $service = app(OrderReturnService::class);
        $payload = $this->payloadFor($item);
        $payload['return_method'] = 'shipping';
        $return = $service->createReturnRequest($user, $order, $payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURN_SETTLEMENT_METHOD_NOT_ALLOWED');

        $service->approveReturn($user, $return, null, null, (string) $store->id, null, 'manual_at_store');
    }

    public function test_shop_receive_dispute_requires_evidence(): void
    {
        [$user, $order, $item, $store] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return, null, null, (string) $store->id);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_NO_EVIDENCE'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHOP_RECEIVE_DISPUTE_EVIDENCE_REQUIRED');

        $service->receiveReturn($user, $return, false, [], $store->id);
    }

    public function test_withdrawal_mark_paid_releases_locked_balance_without_refunding_available_balance(): void
    {
        $user = $this->makeUser('user');
        $admin = $this->makeUser('admin');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();

        $service = app(OrderReturnService::class);
        $withdrawal = $service->createWithdrawal($user, [
            'amount' => 60000,
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
        ]);
        $paid = $service->markWithdrawalPaid($withdrawal, $admin);
        $wallet->refresh();

        $this->assertSame('paid', $paid->status);
        $this->assertSame(40000.0, (float) $wallet->available_balance);
        $this->assertSame(0.0, (float) $wallet->locked_balance);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $withdrawal->id)->where('entry_type', 'withdrawal_lock')->count());
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $withdrawal->id)->where('entry_type', 'withdrawal_paid')->count());
    }

    public function test_withdrawal_reject_unlocks_balance_to_available(): void
    {
        $user = $this->makeUser('user');
        $admin = $this->makeUser('admin');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();

        $service = app(OrderReturnService::class);
        $withdrawal = $service->createWithdrawal($user, [
            'amount' => 60000,
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
        ]);
        $rejected = $service->cancelWithdrawal($withdrawal, $admin, 'rejected', 'Invalid bank account');
        $wallet->refresh();

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame(100000.0, (float) $wallet->available_balance);
        $this->assertSame(0.0, (float) $wallet->locked_balance);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $withdrawal->id)->where('entry_type', 'withdrawal_rejected')->count());
    }

    public function test_withdrawal_retry_with_same_idempotency_key_locks_balance_once(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();

        $payload = [
            'amount' => 60000,
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
            'idempotency_key' => 'withdrawal-request-1',
        ];
        $service = app(OrderReturnService::class);

        $first = $service->createWithdrawal($user, $payload);
        $second = $service->createWithdrawal($user, $payload);
        $wallet->refresh();

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(40000.0, (float) $wallet->available_balance);
        $this->assertSame(60000.0, (float) $wallet->locked_balance);
        $this->assertSame(1, DB::table('money_wallet_withdrawal_requests')->where('idempotency_key', 'withdrawal-request-1')->count());
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $first->id)->where('entry_type', 'withdrawal_lock')->count());
    }

    public function test_withdrawal_retry_without_idempotency_key_reuses_active_request_after_time_passes(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 150000, 'locked_balance' => 0])->save();

        $payload = [
            'amount' => 60000,
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
        ];
        $service = app(OrderReturnService::class);

        $first = $service->createWithdrawal($user, $payload);
        \Carbon\Carbon::setTestNow(now()->addMinutes(2));
        try {
            $second = $service->createWithdrawal($user, $payload);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
        $wallet->refresh();

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(90000.0, (float) $wallet->available_balance);
        $this->assertSame(60000.0, (float) $wallet->locked_balance);
        $this->assertSame(1, DB::table('money_wallet_withdrawal_requests')->count());
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')
            ->where('source_id', (string) $first->id)
            ->where('entry_type', 'withdrawal_lock')
            ->count());
    }

    public function test_customer_withdrawal_rejects_malformed_bank_payload(): void
    {
        $user = $this->makeUser('user');
        $request = Request::create('/api/customer/money-wallet/withdrawals', 'POST', [
            'amount' => 10000000000000,
            'bank_id' => ['array-is-not-valid'],
            'bank_code' => 'BANK CODE!',
            'account_number' => '../123456',
            'account_name' => "NGUYEN\nVAN A",
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(CustomerMoneyWalletController::class)->withdraw($request);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $payload['errors'][0]['code']);
        $this->assertArrayHasKey('amount', $payload['errors'][0]['details']);
        $this->assertArrayHasKey('bank_id', $payload['errors'][0]['details']);
        $this->assertArrayHasKey('bank_code', $payload['errors'][0]['details']);
        $this->assertArrayHasKey('account_number', $payload['errors'][0]['details']);
        $this->assertArrayHasKey('account_name', $payload['errors'][0]['details']);
    }

    public function test_withdrawal_service_rejects_non_scalar_bank_payload(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WITHDRAWAL_BANK_ID_INVALID');

        app(OrderReturnService::class)->createWithdrawal($user, [
            'amount' => 60000,
            'bank_id' => ['bad'],
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
        ]);
    }

    public function test_withdrawal_mark_paid_retry_is_idempotent_after_final_state(): void
    {
        $user = $this->makeUser('user');
        $admin = $this->makeUser('admin');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();

        $service = app(OrderReturnService::class);
        $withdrawal = $service->createWithdrawal($user, [
            'amount' => 60000,
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
        ]);

        $first = $service->markWithdrawalPaid($withdrawal, $admin);
        $second = $service->markWithdrawalPaid($withdrawal, $admin);
        $wallet->refresh();

        $this->assertSame((string) $first->paid_ledger_id, (string) $second->paid_ledger_id);
        $this->assertSame(40000.0, (float) $wallet->available_balance);
        $this->assertSame(0.0, (float) $wallet->locked_balance);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $withdrawal->id)->where('entry_type', 'withdrawal_paid')->count());
    }

    public function test_withdrawal_cancel_retry_is_idempotent_after_final_state(): void
    {
        $user = $this->makeUser('user');
        $admin = $this->makeUser('admin');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();

        $service = app(OrderReturnService::class);
        $withdrawal = $service->createWithdrawal($user, [
            'amount' => 60000,
            'account_number' => '123456789',
            'account_name' => 'NGUYEN VAN A',
        ]);

        $first = $service->cancelWithdrawal($withdrawal, $admin, 'rejected', 'Invalid bank account');
        $second = $service->cancelWithdrawal($withdrawal, $admin, 'rejected', 'Invalid bank account');
        $wallet->refresh();

        $this->assertSame('rejected', $first->status);
        $this->assertSame('rejected', $second->status);
        $this->assertSame(100000.0, (float) $wallet->available_balance);
        $this->assertSame(0.0, (float) $wallet->locked_balance);
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')->where('source_id', (string) $withdrawal->id)->where('entry_type', 'withdrawal_rejected')->count());
    }

    public function test_customer_wallet_ledger_response_does_not_expose_internal_ids_or_idempotency_keys(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();
        DB::table('money_wallet_ledger_entries')->insert([
            'id' => 'ledger-public-shape',
            'wallet_account_id' => (string) $wallet->id,
            'direction' => 'credit',
            'amount' => 100000,
            'balance_before' => 0,
            'balance_after' => 100000,
            'entry_type' => 'manual_adjustment',
            'source_type' => 'manual_adjustment',
            'source_id' => 'internal-source-id',
            'idempotency_key' => 'internal-idempotency-key',
            'created_by' => 'admin-id',
            'metadata' => json_encode(['safe' => true]),
            'created_at' => now(),
        ]);

        $request = Request::create('/api/customer/money-wallet/ledger', 'GET');
        $request->setUserResolver(fn () => $user);
        $response = app(CustomerMoneyWalletController::class)->ledger($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('wallet_account_id', $payload['data'][0]);
        $this->assertArrayNotHasKey('idempotency_key', $payload['data'][0]);
        $this->assertArrayNotHasKey('source_id', $payload['data'][0]);
        $this->assertArrayNotHasKey('created_by', $payload['data'][0]);
    }

    public function test_customer_return_payload_does_not_expose_store_funding_details(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_PRIVATE'));
        $return = $service->receiveReturn($user, $return, true, []);

        $payload = $this->serializeReturnWith(CustomerOrderReturnController::class, $return);

        $this->assertArrayNotHasKey('refund_funding_source', $payload);
        $this->assertArrayNotHasKey('platform_advanced_amount', $payload);
    }

    public function test_rejected_return_remains_active_in_order_detail_for_customer_dispute(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $service->rejectReturn($user, $return, 'Need evidence');

        [$summary, $activeReturn] = $this->buildOrderReturnSummary($order->fresh());

        $this->assertSame(OrderReturn::STATUS_REJECTED, $summary['active_return_status']);
        $this->assertSame(OrderReturn::STATUS_REJECTED, $activeReturn['status']);
        $this->assertSame('Need evidence', $activeReturn['rejection_reason']);
        $this->assertArrayNotHasKey('refund_funding_source', $activeReturn);
        $this->assertArrayNotHasKey('platform_advanced_amount', $activeReturn);
    }

    public function test_order_return_broadcast_payload_does_not_expose_store_funding_details(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX_EVENT'));
        $return = $service->receiveReturn($user, $return, true, []);

        $payload = OrderReturnUpdated::fromReturn($return, 'refunded')->broadcastWith();

        $this->assertArrayNotHasKey('refund_funding_source', $payload);
        $this->assertArrayNotHasKey('platform_advanced_amount', $payload);
    }

    public function test_store_and_admin_return_payloads_include_evidence_for_review(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder(['store_wallet_balance' => 0]);
        $payload = $this->payloadFor($item);
        $payload['images'] = ['uploads/returns/'.$user->id.'/proof.jpg'];
        $payload['video'] = 'uploads/returns/'.$user->id.'/proof.mp4';

        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $payload);
        $return->forceFill([
            'tracking_code' => 'SPX_REVIEW',
            'carrier_name' => 'SPX',
            'shipping_receipt_images' => ['uploads/returns/'.$user->id.'/shipping.jpg'],
            'shop_received_images' => ['uploads/shop_returns/'.$order->store_id.'/check.jpg'],
            'shop_received_video' => 'uploads/shop_returns/'.$order->store_id.'/check.mp4',
            'shop_received_note' => 'Package checked',
            'dispute_reason' => 'Evidence mismatch',
            'dispute_evidence_images' => ['uploads/returns/'.$user->id.'/dispute.jpg'],
        ])->save();
        $return->refresh();

        $storePayload = $this->serializeReturnWith(StoreAdminOrderReturnController::class, $return);
        $adminPayload = $this->serializeReturnWith(AdminOrderReturnController::class, $return);

        foreach ([$storePayload, $adminPayload] as $serialized) {
            $this->assertSame(['uploads/returns/'.$user->id.'/proof.jpg'], $serialized['images']);
            $this->assertSame('uploads/returns/'.$user->id.'/proof.mp4', $serialized['video']);
            $this->assertSame('SPX_REVIEW', $serialized['tracking_code']);
            $this->assertSame('SPX', $serialized['carrier_name']);
            $this->assertSame(['uploads/returns/'.$user->id.'/shipping.jpg'], $serialized['shipping_receipt_images']);
            $this->assertSame(['uploads/shop_returns/'.$order->store_id.'/check.jpg'], $serialized['shop_received_images']);
            $this->assertSame('uploads/shop_returns/'.$order->store_id.'/check.mp4', $serialized['shop_received_video']);
            $this->assertSame('Package checked', $serialized['shop_received_note']);
            $this->assertSame('Evidence mismatch', $serialized['dispute_reason']);
            $this->assertSame(['uploads/returns/'.$user->id.'/dispute.jpg'], $serialized['dispute_evidence_images']);
        }
    }

    public function test_return_and_wallet_updates_are_broadcast_in_realtime(): void
    {
        Event::fake([OrderReturnUpdated::class, MoneyWalletUpdated::class]);

        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return = $service->approveReturn($user, $return);
        $return = $service->shipReturn($user, $return, $this->shipPayload('SPX789'));
        $service->receiveReturn($user, $return, true, []);

        Event::assertDispatched(OrderReturnUpdated::class, fn (OrderReturnUpdated $event): bool => $event->action === 'created');
        Event::assertDispatched(OrderReturnUpdated::class, fn (OrderReturnUpdated $event): bool => $event->action === 'refunded');
        Event::assertDispatched(MoneyWalletUpdated::class, fn (MoneyWalletUpdated $event): bool => $event->action === 'refund_credit');
    }

    public function test_order_return_broadcast_channels_are_private(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $return = app(OrderReturnService::class)->createReturnRequest($user, $order, $this->payloadFor($item));

        $channels = OrderReturnUpdated::fromReturn($return, 'created')->broadcastOn();

        $this->assertCount(3, $channels);
        foreach ($channels as $channel) {
            $this->assertInstanceOf(PrivateChannel::class, $channel);
        }
    }

    public function test_auto_approve_pending_returns_after_deadline(): void
    {
        [$user, $order, $item] = $this->makeDeliveredOrder();
        $service = app(OrderReturnService::class);
        $return = $service->createReturnRequest($user, $order, $this->payloadFor($item));
        $return->forceFill(['auto_approve_at' => now()->subMinute()])->save();

        $this->assertSame(1, $service->autoApprovePendingReturns());
        $this->assertSame(OrderReturn::STATUS_APPROVED, OrderReturn::query()->findOrFail($return->id)->status);
    }

    public function test_money_wallet_reconciliation_command_passes_clean_wallets(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();
        DB::table('money_wallet_ledger_entries')->insert([
            'id' => 'ledger-'.uniqid(),
            'wallet_account_id' => (string) $wallet->id,
            'direction' => 'credit',
            'amount' => 100000,
            'balance_before' => 0,
            'balance_after' => 100000,
            'entry_type' => 'manual_adjustment',
            'source_type' => 'manual_adjustment',
            'source_id' => 'manual-1',
            'idempotency_key' => 'manual:clean-wallet',
            'created_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('ecoluck:reconcile-money-wallets', ['--fail-on-drift' => true]));
    }

    public function test_money_wallet_reconciliation_command_fails_on_balance_drift(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 120000, 'locked_balance' => 0])->save();
        DB::table('money_wallet_ledger_entries')->insert([
            'id' => 'ledger-'.uniqid(),
            'wallet_account_id' => (string) $wallet->id,
            'direction' => 'credit',
            'amount' => 100000,
            'balance_before' => 0,
            'balance_after' => 100000,
            'entry_type' => 'manual_adjustment',
            'source_type' => 'manual_adjustment',
            'source_id' => 'manual-2',
            'idempotency_key' => 'manual:drift-wallet',
            'created_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('ecoluck:reconcile-money-wallets', ['--fail-on-drift' => true]));
    }

    public function test_money_wallet_reconciliation_command_fails_on_broken_ledger_chain_even_when_latest_balance_matches(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 100000, 'locked_balance' => 0])->save();
        DB::table('money_wallet_ledger_entries')->insert([
            [
                'id' => 'ledger-chain-1',
                'wallet_account_id' => (string) $wallet->id,
                'direction' => 'credit',
                'amount' => 50000,
                'balance_before' => 0,
                'balance_after' => 60000,
                'entry_type' => 'manual_adjustment',
                'source_type' => 'manual_adjustment',
                'source_id' => 'manual-chain-1',
                'idempotency_key' => 'manual:chain-1',
                'created_at' => now(),
            ],
            [
                'id' => 'ledger-chain-2',
                'wallet_account_id' => (string) $wallet->id,
                'direction' => 'credit',
                'amount' => 50000,
                'balance_before' => 50000,
                'balance_after' => 100000,
                'entry_type' => 'manual_adjustment',
                'source_type' => 'manual_adjustment',
                'source_id' => 'manual-chain-2',
                'idempotency_key' => 'manual:chain-2',
                'created_at' => now()->addSecond(),
            ],
        ]);

        $this->assertSame(1, Artisan::call('ecoluck:reconcile-money-wallets', ['--fail-on-drift' => true]));
    }

    public function test_money_wallet_reconciliation_command_fails_on_duplicate_refund_source(): void
    {
        $user = $this->makeUser('user');
        $wallet = app(MoneyWalletService::class)->accountFor('user', (string) $user->id);
        $wallet->forceFill(['available_balance' => 200000, 'locked_balance' => 0])->save();

        foreach ([1, 2] as $index) {
            DB::table('money_wallet_ledger_entries')->insert([
                'id' => 'ledger-duplicate-refund-'.$index,
                'wallet_account_id' => (string) $wallet->id,
                'direction' => 'credit',
                'amount' => 100000,
                'balance_before' => ($index - 1) * 100000,
                'balance_after' => $index * 100000,
                'entry_type' => 'refund_credit',
                'source_type' => 'order_return',
                'source_id' => 'return-duplicate',
                'idempotency_key' => 'refund:return-duplicate:'.$index,
                'created_at' => now()->addSeconds($index),
            ]);
        }

        $this->assertSame(1, Artisan::call('ecoluck:reconcile-money-wallets', ['--fail-on-drift' => true]));
    }

    public function test_admin_complaint_return_creation_is_idempotent_and_covers_the_whole_remaining_order(): void
    {
        [$customer, $order, $item] = $this->makeDeliveredOrder(['quantity' => 2]);
        $admin = $this->makeUser('super_admin');
        $priorReturn = OrderReturn::query()->create([
            'id' => 'prior-return-'.uniqid(),
            'return_code' => 'RET-PRIOR-'.uniqid(),
            'order_id' => (string) $order->id,
            'user_id' => (string) $customer->id,
            'store_id' => (string) $order->store_id,
            'status' => OrderReturn::STATUS_REFUNDED,
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason' => 'Prior partial refund',
            'refund_amount_final' => 50000,
        ]);
        OrderReturnItem::query()->create([
            'order_return_id' => (string) $priorReturn->id,
            'order_item_id' => (string) $item->id,
            'quantity' => 1,
            'unit_price_snapshot' => 100000,
            'gross_amount' => 100000,
            'discount_allocated' => 0,
            'refund_amount' => 50000,
        ]);
        $complaint = $this->makeComplaint($customer, $order);

        $service = app(ComplaintResolutionService::class);
        $first = $service->createReturnForStore($complaint, $admin);
        $second = $service->createReturnForStore($complaint->fresh(), $admin);

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(OrderReturn::STATUS_PENDING, $first->status);
        $this->assertSame(1, OrderReturn::query()->where('complaint_id', $complaint->id)->count());
        $this->assertDatabaseHas('order_return_items', [
            'order_return_id' => (string) $first->id,
            'order_item_id' => (string) $item->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('complaints', [
            'id' => (string) $complaint->id,
            'status' => 'awaiting_store_action',
            'resolution_type' => 'return_requested',
        ]);
    }

    public function test_force_refund_is_idempotent_and_credits_customer_once(): void
    {
        [$customer, $order, $item, $store] = $this->makeDeliveredOrder([
            'store_wallet_balance' => 100000,
            'ticket_reward_quantity' => 2,
        ]);
        $admin = $this->makeUser('super_admin');
        $complaint = $this->makeComplaint($customer, $order);
        $ticketId = 'ticket-force-refund-'.uniqid();
        DB::table('tickets')->insert([
            'id' => $ticketId,
            'ticket_code' => 'FORCE-REFUND-'.uniqid(),
            'user_id' => (string) $customer->id,
            'status' => 'LOCKED',
            'source_type' => 'ORDER_REWARD',
            'source_id' => (string) $order->id,
            'source_order_item_id' => (string) $item->id,
            'locked_at' => now(),
            'locked_by' => 'complaint:'.(string) $complaint->id,
            'lock_expires_at' => now()->addHours(72),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('complaint_ticket_locks')->insert([
            'id' => 'lock-force-refund-'.uniqid(),
            'complaint_id' => (string) $complaint->id,
            'ticket_id' => $ticketId,
            'locked_from_status' => 'AVAILABLE',
            'created_at' => now(),
        ]);

        $service = app(ComplaintResolutionService::class);
        $first = $service->forceRefund($complaint, $admin, 'Admin chap nhan hoan toan bo don.');
        $second = $service->forceRefund($complaint->fresh(), $admin, 'Retry request.');

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(OrderReturn::STATUS_REFUNDED, $second->status);
        $this->assertSame(1, OrderReturn::query()->where('complaint_id', $complaint->id)->count());
        $this->assertSame(1, DB::table('money_wallet_ledger_entries')
            ->where('source_type', 'order_return')
            ->where('source_id', (string) $first->id)
            ->where('entry_type', 'refund_credit')
            ->count());
        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => 'CANCELLED',
            'locked_by' => null,
        ]);
        $this->assertDatabaseHas('complaint_ticket_locks', [
            'complaint_id' => (string) $complaint->id,
            'ticket_id' => $ticketId,
            'release_reason' => 'refund_processed',
        ]);
        $storeTicketLedger = DB::table('store_ticket_transactions')
            ->where('source_type', 'order_return')
            ->where('source_id', (string) $first->id)
            ->where('event_type', 'RETURN_TICKET_REFUND')
            ->first();
        $this->assertNotNull($storeTicketLedger);
        $this->assertSame(1, DB::table('store_ticket_transactions')
            ->where('source_type', 'order_return')
            ->where('source_id', (string) $first->id)
            ->where('event_type', 'RETURN_TICKET_REFUND')
            ->count());
        $this->assertSame(50000.0, (float) $storeTicketLedger->amount_vnd);
        $this->assertSame(50000.0, (float) $storeTicketLedger->wallet_delta);
        $this->assertSame(0.0, (float) $storeTicketLedger->debt_delta);
        $this->assertSame(50000.0, (float) Store::query()->findOrFail($store->id)->wallet_balance);
        $this->assertDatabaseHas('complaints', [
            'id' => (string) $complaint->id,
            'status' => 'closed',
            'resolution_type' => 'forced_refund',
        ]);
    }

    public function test_releasing_complaint_lock_does_not_unlock_unrelated_store_debt(): void
    {
        [$customer, $order, , $store] = $this->makeDeliveredOrder();
        $admin = $this->makeUser('super_admin');
        $complaint = $this->makeComplaint($customer, $order);
        $store->forceFill([
            'ticket_debt' => 50000,
            'is_debt_locked' => true,
            'debt_lock_reason' => 'return_platform_advance',
        ])->save();

        $lockService = app(ComplaintStoreLockService::class);
        $lockService->lock($complaint, $admin);
        $lockService->release($complaint, 'complaint_resolved', $admin);

        $store->refresh();
        $this->assertTrue((bool) $store->is_debt_locked);
        $this->assertSame('return_platform_advance', $store->debt_lock_reason);
        $this->assertDatabaseHas('complaint_store_locks', [
            'complaint_id' => (string) $complaint->id,
            'store_id' => (string) $store->id,
            'release_reason' => 'complaint_resolved',
        ]);
    }

    public function test_sale_cannot_execute_super_admin_complaint_resolution(): void
    {
        [$customer, $order] = $this->makeDeliveredOrder();
        $sale = $this->makeUser('sale');
        $complaint = $this->makeComplaint($customer, $order);
        $this->actingAs($sale);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        app(ComplaintController::class)->forceRefundOrLockStore(
            Request::create('/admin/complaints/'.$complaint->id.'/force-action', 'POST', [
                'action' => 'lock_store',
                'reason' => 'Sale must not perform this action.',
            ]),
            $complaint
        );
    }

    public function test_assignment_rejects_non_sale_user(): void
    {
        [$customer, $order] = $this->makeDeliveredOrder();
        $admin = $this->makeUser('super_admin');
        $complaint = $this->makeComplaint($customer, $order);
        $this->actingAs($admin);

        $this->expectException(ValidationException::class);

        app(ComplaintController::class)->assign(
            Request::create('/admin/complaints/'.$complaint->id.'/assign', 'PATCH', [
                'assigned_to' => (string) $customer->id,
            ]),
            $complaint
        );
    }

    public function test_store_accepts_pending_complaint_return_without_marking_goods_received(): void
    {
        [$customer, $order] = $this->makeDeliveredOrder();
        $admin = $this->makeUser('super_admin');
        $complaint = $this->makeComplaint($customer, $order);
        $service = app(ComplaintResolutionService::class);
        $pending = $service->createReturnForStore($complaint, $admin);

        $accepted = $service->acceptStoreAction($complaint->fresh(), $customer, (string) $order->store_id);
        $retry = $service->acceptStoreAction($complaint->fresh(), $customer, (string) $order->store_id);

        $this->assertSame((string) $pending->id, (string) $accepted->id);
        $this->assertSame(OrderReturn::STATUS_APPROVED, $accepted->status);
        $this->assertSame(OrderReturn::STATUS_APPROVED, $retry->status);
        $this->assertSame('awaiting_store_action', (string) $complaint->fresh()->status);
    }

    public function test_store_accepting_complaint_lock_processes_refund_and_only_then_unlocks(): void
    {
        [$customer, $order, , $store] = $this->makeDeliveredOrder(['store_wallet_balance' => 100000]);
        $admin = $this->makeUser('super_admin');
        $complaint = $this->makeComplaint($customer, $order);
        $service = app(ComplaintResolutionService::class);
        $service->lockStore($complaint, $admin, 'Cho Store chap nhan hoan tien.');

        $this->assertTrue((bool) $store->fresh()->is_debt_locked);
        $return = $service->acceptStoreAction($complaint->fresh(), $customer, (string) $store->id);

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $return->status);
        $this->assertSame('closed', (string) $complaint->fresh()->status);
        $this->assertFalse((bool) $store->fresh()->is_debt_locked);
        $this->assertDatabaseHas('complaint_store_locks', [
            'complaint_id' => (string) $complaint->id,
            'release_reason' => 'refund_processed',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->string('role')->default('user');
            $table->decimal('balance', 15, 2)->default(0);
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('owner_id')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('approved');
            $table->boolean('is_open')->default(true);
            $table->decimal('wallet_balance', 15, 2)->default(0);
            $table->decimal('ticket_debt', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(200000);
            $table->unsignedInteger('credit_version')->default(0);
            $table->boolean('is_debt_locked')->default(false);
            $table->timestamp('debt_locked_at')->nullable();
            $table->string('debt_lock_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_code')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->string('store_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status_payment')->nullable();
            $table->string('status')->default('delivered');
            $table->string('shipping_method')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->integer('sla_timeout_minutes')->default(15);
            $table->boolean('sla_warning_sent')->default(false);
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('item_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->decimal('commission_amount', 15, 2)->nullable();
            $table->string('commission_rate_mode')->nullable();
            $table->unsignedInteger('ticket_reward_quantity')->default(0);
            $table->string('ticket_type_code', 50)->nullable();
            $table->timestamp('return_blocked_at')->nullable();
            $table->string('return_blocked_by_ticket_id')->nullable();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('ticket_code')->unique();
            $table->string('user_id')->nullable()->index();
            $table->string('status')->default('AVAILABLE')->index();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable()->index();
            $table->string('source_order_item_id')->nullable()->index();
            $table->string('ticket_type_code', 50)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by')->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('order_item_id')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('order_item_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('seller_id')->nullable();
            $table->string('commission_type')->default('percent');
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->decimal('commission_base_amount', 15, 2)->default(0);
            $table->decimal('pending_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->default(0);
            $table->decimal('tax_withholding_amount', 15, 2)->default(0);
            $table->decimal('payout_amount', 15, 2)->default(0);
            $table->string('status')->default(AffiliateCommission::STATUS_PENDING);
            $table->text('reason_cancelled')->nullable();
            $table->text('held_reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('payout_batch_id')->nullable();
            $table->unsignedBigInteger('source_assignment_id')->nullable();
            $table->json('policy_snapshot_json')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_wallet_logs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id')->nullable();
            $table->string('action_type');
            $table->integer('amount_change');
            $table->integer('balance_before')->nullable();
            $table->integer('balance_after')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('order_returns', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('complaint_id')->nullable()->unique();
            $table->string('return_code')->unique();
            $table->string('order_id')->index();
            $table->string('user_id')->index();
            $table->string('store_id')->index();
            $table->string('status')->index();
            $table->string('return_type');
            $table->string('refund_method')->nullable();
            $table->string('return_method')->nullable();
            $table->string('reason_code')->nullable();
            $table->text('reason');
            $table->json('images')->nullable();
            $table->string('video')->nullable();
            $table->decimal('refund_amount_estimated', 15, 2)->default(0);
            $table->decimal('refund_amount_final', 15, 2)->default(0);
            $table->string('refund_currency')->default('VND');
            $table->string('refund_funding_source')->nullable();
            $table->json('funding_breakdown')->nullable();
            $table->decimal('platform_advanced_amount', 15, 2)->default(0);
            $table->string('refund_idempotency_key')->nullable()->unique();
            $table->string('customer_wallet_ledger_id')->nullable();
            $table->string('store_liability_ledger_id')->nullable();
            $table->timestamp('refund_processed_at')->nullable();
            $table->string('refund_processed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('tracking_code')->nullable();
            $table->string('carrier_name')->nullable();
            $table->json('shipping_receipt_images')->nullable();
            $table->json('shop_received_images')->nullable();
            $table->string('shop_received_video')->nullable();
            $table->text('shop_received_note')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->json('dispute_evidence_images')->nullable();
            $table->string('admin_resolution')->nullable();
            $table->text('admin_resolution_note')->nullable();
            $table->timestamp('auto_approve_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_return_id')->index();
            $table->string('order_item_id')->index();
            $table->integer('quantity');
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('discount_allocated', 15, 2)->default(0);
            $table->decimal('refund_amount', 15, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('store_admin_notifications', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id', 64)->index();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('order_code', 100)->nullable();
            $table->string('type', 80)->default('order_new')->index();
            $table->string('title', 191);
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'order_id', 'type']);
        });

        Schema::create('complaints', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('complaint_code')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('store_id');
            $table->string('order_id');
            $table->string('complaint_type')->nullable();
            $table->string('severity')->default('normal');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('new');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('resolution_type')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('complaint_ticket_locks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('complaint_id')->index();
            $table->string('ticket_id')->index();
            $table->string('locked_from_status')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->string('released_by')->nullable();
        });

        Schema::create('complaint_store_locks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('complaint_id')->unique();
            $table->string('store_id')->index();
            $table->string('locked_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->string('released_by')->nullable();
        });

        Schema::create('complaint_action_logs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('complaint_id')->index();
            $table->string('actor_id')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('money_wallet_accounts', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('owner_type')->index();
            $table->string('owner_id')->index();
            $table->string('currency')->default('VND');
            $table->decimal('available_balance', 15, 2)->default(0);
            $table->decimal('locked_balance', 15, 2)->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'currency']);
        });

        Schema::create('money_wallet_ledger_entries', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('wallet_account_id')->index();
            $table->string('direction');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('entry_type')->index();
            $table->string('source_type')->index();
            $table->string('source_id')->index();
            $table->string('idempotency_key')->unique();
            $table->string('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('money_wallet_withdrawal_requests', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id', 64)->index();
            $table->string('wallet_account_id')->index();
            $table->decimal('amount', 15, 2);
            $table->string('status', 30)->index();
            $table->string('bank_id', 64)->nullable();
            $table->string('bank_code', 30)->nullable();
            $table->string('bank_name', 255)->nullable();
            $table->string('account_number', 50);
            $table->string('account_name', 255);
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('locked_ledger_id')->nullable();
            $table->string('paid_ledger_id')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });

        Schema::create('store_ticket_transactions', function (Blueprint $table): void {
            $table->string('transaction_id')->primary();
            $table->string('order_id')->nullable();
            $table->string('store_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('tickets_consumed')->default(0);
            $table->decimal('unit_price_at_time', 15, 2)->default(0);
            $table->decimal('total_debit_amount', 15, 2)->default(0);
            $table->decimal('amount_vnd', 15, 2)->default(0);
            $table->decimal('wallet_delta', 15, 2)->default(0);
            $table->decimal('debt_delta', 15, 2)->default(0);
            $table->decimal('wallet_balance_after', 15, 2)->nullable();
            $table->decimal('ticket_debt_after', 15, 2)->nullable();
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('reversal_of_transaction_id')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 160)->nullable();
            $table->string('event_type', 50)->default('ORDER_DELIVERED');
            $table->string('ticket_calc_type', 30)->nullable();
            $table->decimal('ticket_value_snapshot', 15, 4)->nullable();
            $table->string('ticket_type_code', 50)->nullable();
            $table->string('ticket_type_name', 120)->nullable();
            $table->unsignedInteger('ticket_quantity')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });

        Schema::create('store_liability_ledger_entries', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id')->index();
            $table->string('direction');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->string('entry_type')->index();
            $table->string('funding_source');
            $table->string('source_type')->index();
            $table->string('source_id')->index();
            $table->string('idempotency_key')->unique();
            $table->string('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('financial_reconciliations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id')->index();
            $table->string('period')->nullable();
            $table->date('period_date')->nullable();
            $table->string('status')->default('pending_shop');
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('financial_reconciliation_offsets', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('financial_reconciliation_id')->index();
            $table->string('store_id')->index();
            $table->string('order_return_id')->index();
            $table->decimal('amount', 15, 2);
            $table->string('source_type')->default('order_return');
            $table->string('source_id')->index();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('user_balances', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id')->unique();
            $table->integer('available_balance')->default(0);
            $table->integer('locked_balance')->default(0);
            $table->integer('used_balance')->default(0);
            $table->integer('expired_balance')->default(0);
            $table->integer('total_lifetime_purchased')->default(0);
            $table->integer('total_lifetime_used')->default(0);
            $table->timestamp('last_updated')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create('ticket_ledger', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('ticket_id')->nullable()->index();
            $table->string('user_id')->nullable()->index();
            $table->string('action_type', 50)->index();
            $table->integer('amount_change');
            $table->integer('balance_before')->nullable();
            $table->integer('balance_after')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_return_reward_ticket_lifecycle(): void
    {
        // 1. Create a delivered order where the item has 2 reward tickets
        [$user, $order, $item, $store] = $this->makeDeliveredOrder([
            'quantity' => 1,
            'ticket_reward_quantity' => 2,
            'ticket_type_code' => 'LUCKY_TICKET',
        ]);

        // Setup user balance
        DB::table('user_balances')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'available_balance' => 2,
            'locked_balance' => 0,
            'used_balance' => 0,
            'expired_balance' => 0,
            'total_lifetime_purchased' => 2,
            'total_lifetime_used' => 0,
            'last_updated' => now(),
            'version' => 1,
        ]);

        // Insert tickets
        $ticket1Id = 't1-' . uniqid();
        $ticket2Id = 't2-' . uniqid();
        DB::table('tickets')->insert([
            [
                'id' => $ticket1Id,
                'ticket_code' => 'TC-1-' . uniqid(),
                'user_id' => $user->id,
                'status' => 'AVAILABLE',
                'source_type' => 'ORDER_REWARD',
                'source_id' => $order->id,
                'source_order_item_id' => $item->id,
                'ticket_type_code' => 'LUCKY_TICKET',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $ticket2Id,
                'ticket_code' => 'TC-2-' . uniqid(),
                'user_id' => $user->id,
                'status' => 'AVAILABLE',
                'source_type' => 'ORDER_REWARD',
                'source_id' => $order->id,
                'source_order_item_id' => $item->id,
                'ticket_type_code' => 'LUCKY_TICKET',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        $service = app(OrderReturnService::class);

        // -- STEP A: Create Return Request (should lock tickets and deduct balance) --
        $payload = $this->payloadFor($item, 1);
        $return = $service->createReturnRequest($user, $order, $payload);

        // Verify ticket status in DB
        $this->assertDatabaseHas('tickets', ['id' => $ticket1Id, 'status' => 'LOCKED', 'locked_by' => 'return:' . $return->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket2Id, 'status' => 'LOCKED', 'locked_by' => 'return:' . $return->id]);

        // Verify user balance
        $this->assertDatabaseHas('user_balances', ['user_id' => $user->id, 'available_balance' => 0]);

        // Verify ticket ledger entries
        $this->assertDatabaseHas('ticket_ledger', [
            'ticket_id' => $ticket1Id,
            'user_id' => $user->id,
            'action_type' => 'LOCKED',
            'amount_change' => -1,
            'reference_type' => 'ORDER_RETURN',
            'reference_id' => $return->id,
        ]);
        $this->assertDatabaseHas('ticket_ledger', [
            'ticket_id' => $ticket2Id,
            'user_id' => $user->id,
            'action_type' => 'LOCKED',
            'amount_change' => -1,
            'reference_type' => 'ORDER_RETURN',
            'reference_id' => $return->id,
        ]);

        // -- STEP B: Reject Return Request (should release tickets and restore balance) --
        $storeAdmin = $this->makeUser('store_admin');
        $service->rejectReturn($storeAdmin, $return, 'Rejecting for testing', $store->id);

        // Verify tickets released
        $this->assertDatabaseHas('tickets', ['id' => $ticket1Id, 'status' => 'AVAILABLE', 'locked_by' => null]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket2Id, 'status' => 'AVAILABLE', 'locked_by' => null]);

        // Verify user balance restored
        $this->assertDatabaseHas('user_balances', ['user_id' => $user->id, 'available_balance' => 2]);

        // Verify release ledger entries
        $this->assertDatabaseHas('ticket_ledger', [
            'ticket_id' => $ticket1Id,
            'user_id' => $user->id,
            'action_type' => 'RELEASED',
            'amount_change' => 1,
            'reference_type' => 'ORDER_RETURN',
            'reference_id' => $return->id,
        ]);

        // -- STEP C: Dispute Return (should lock tickets again and deduct balance again) --
        $service->disputeReturn($user, $return, [
            'dispute_reason' => 'Customer disagrees with rejection',
        ]);

        // Verify tickets locked again
        $this->assertDatabaseHas('tickets', ['id' => $ticket1Id, 'status' => 'LOCKED', 'locked_by' => 'return:' . $return->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket2Id, 'status' => 'LOCKED', 'locked_by' => 'return:' . $return->id]);
        $this->assertDatabaseHas('user_balances', ['user_id' => $user->id, 'available_balance' => 0]);

        // -- STEP D: Process Refund (should revoke tickets permanently and update ledger action to REVOKED) --
        $admin = $this->makeUser('admin');
        
        $return = $service->resolveDispute($admin, $return, [
            'resolution' => 'approve_refund',
            'admin_resolution_note' => 'Approve refund after dispute',
        ]);

        // Verify tickets are CANCELLED (permanently revoked)
        $this->assertDatabaseHas('tickets', ['id' => $ticket1Id, 'status' => 'CANCELLED', 'locked_by' => null]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket2Id, 'status' => 'CANCELLED', 'locked_by' => null]);

        // User balance remains 0 (since it was already deducted when locked)
        $this->assertDatabaseHas('user_balances', ['user_id' => $user->id, 'available_balance' => 0]);

        // The LOCKED ledger entries should have been updated to REVOKED
        $this->assertDatabaseHas('ticket_ledger', [
            'ticket_id' => $ticket1Id,
            'user_id' => $user->id,
            'action_type' => 'REVOKED',
            'reference_type' => 'ORDER_RETURN',
            'reference_id' => $return->id,
        ]);
        $this->assertDatabaseHas('ticket_ledger', [
            'ticket_id' => $ticket2Id,
            'user_id' => $user->id,
            'action_type' => 'REVOKED',
            'reference_type' => 'ORDER_RETURN',
            'reference_id' => $return->id,
        ]);

        // Verify there is no LOCKED action left in ledger for this return
        $this->assertDatabaseMissing('ticket_ledger', [
            'reference_type' => 'ORDER_RETURN',
            'reference_id' => $return->id,
            'action_type' => 'LOCKED',
        ]);
    }

    private function makeDeliveredOrder(array $overrides = []): array
    {
        $user = $this->makeUser('user');

        $store = Store::query()->create([
            'id' => 'store-'.uniqid(),
            'owner_id' => (string) $user->id,
            'name' => 'Store',
            'status' => 'approved',
            'is_open' => true,
            'wallet_balance' => $overrides['store_wallet_balance'] ?? 0,
        ]);

        $order = Order::query()->create([
            'id' => 'order-'.uniqid(),
            'order_code' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
            'store_id' => $store->id,
            'status' => 'delivered',
            'payment_method' => 'MOMO',
            'status_payment' => 'paid',
            'shipping_method' => $overrides['shipping_method'] ?? 'delivery',
            'total_amount' => 100000,
            'final_amount' => 100000,
            'total' => 100000,
            'discount_amount' => 0,
            'delivered_at' => $overrides['delivered_at'] ?? now()->subHour(),
        ]);

        $item = OrderItem::query()->create([
            'id' => 'item-'.uniqid(),
            'order_id' => $order->id,
            'product_name' => 'Test product',
            'item_name' => 'Test product',
            'quantity' => $overrides['quantity'] ?? 1,
            'unit_price' => 100000,
            'price' => 100000,
            'total_price' => 100000,
            'commission_amount' => $overrides['commission_amount'] ?? 0,
            'commission_rate_mode' => $overrides['commission_rate_mode'] ?? 'percent',
            'ticket_reward_quantity' => $overrides['ticket_reward_quantity'] ?? 0,
            'ticket_type_code' => $overrides['ticket_type_code'] ?? null,
        ]);

        return [$user, $order, $item, $store];
    }

    private function seedAffiliateCommission(
        User $affiliate,
        User $customer,
        Order $order,
        OrderItem $item,
        string $status,
        float $amount,
        bool $seedLegacy = true
    ): AffiliateCommission {
        if ($seedLegacy) {
            DB::table('commission_users')->insert([
                'user_id' => $affiliate->id,
                'order_item_id' => (string) $item->id,
                'level' => 1,
                'amount' => $amount,
                'status' => $status === AffiliateCommission::STATUS_PAID ? 'paid' : 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return AffiliateCommission::query()->create([
            'affiliate_id' => (int) $affiliate->id,
            'customer_id' => (int) $customer->id,
            'order_id' => (string) $order->id,
            'order_item_id' => (string) $item->id,
            'seller_id' => (string) $order->store_id,
            'commission_type' => 'percent',
            'commission_value' => 2.62,
            'commission_base_amount' => 100000,
            'pending_amount' => $amount,
            'approved_amount' => $status === AffiliateCommission::STATUS_PAID ? $amount : 0,
            'payout_amount' => $status === AffiliateCommission::STATUS_PAID ? $amount : 0,
            'status' => $status,
            'eligible_at' => $status === AffiliateCommission::STATUS_PAID ? now()->subMinute() : null,
            'approved_at' => $status === AffiliateCommission::STATUS_PAID ? now()->subMinute() : null,
            'paid_at' => $status === AffiliateCommission::STATUS_PAID ? now()->subMinute() : null,
            'policy_snapshot_json' => [
                'source_type' => 'customer_upline',
                'commission_amount' => $amount,
            ],
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::query()->create([
            'name' => $role === 'admin' ? 'Admin' : 'Customer',
            'email' => uniqid($role).'@example.test',
            'status' => 'active',
            'role' => $role,
        ]);
    }

    private function makeComplaint(User $customer, Order $order): Complaint
    {
        return Complaint::query()->create([
            'id' => 'complaint-'.uniqid(),
            'complaint_code' => 'CMP-'.uniqid(),
            'user_id' => $customer->id,
            'store_id' => (string) $order->store_id,
            'order_id' => (string) $order->id,
            'complaint_type' => 'quality_issue',
            'title' => 'Complaint test',
            'description' => 'Complaint full order test.',
            'status' => 'processing',
        ]);
    }

    private function payloadFor(OrderItem $item, int $quantity = 1): array
    {
        return [
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason_code' => 'CHANGED_MIND',
            'reason' => 'Changed mind',
            'items' => [
                [
                    'order_item_id' => (string) $item->id,
                    'quantity' => $quantity,
                ],
            ],
        ];
    }

    private function shipPayload(string $trackingCode = 'SPX_TEST', ?string $carrierName = null): array
    {
        $payload = [
            'tracking_code' => $trackingCode,
            'shipping_receipt_images' => ['uploads/returns/customer/shipping-receipt.jpg'],
        ];

        if ($carrierName !== null) {
            $payload['carrier_name'] = $carrierName;
        }

        return $payload;
    }

    private function serializeReturnWith(string $controllerClass, OrderReturn $return): array
    {
        $controller = app($controllerClass);
        $method = new ReflectionMethod($controller, 'serializeReturn');
        $method->setAccessible(true);

        return $method->invoke($controller, $return);
    }

    private function buildOrderReturnSummary(Order $order): array
    {
        $controller = app(ClientOrderController::class);
        $method = new ReflectionMethod($controller, 'buildReturnSummary');
        $method->setAccessible(true);

        return $method->invoke($controller, $order);
    }
}
