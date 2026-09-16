<?php

use App\Http\Controllers\Api\ClientCatalogController;
use App\Http\Controllers\Api\ClientOrderController;
use App\Http\Controllers\Api\StoreAdminAuthController;
use App\Http\Controllers\Api\StoreAdminFinanceController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreCreditService;
use App\Services\StoreFinanceWithdrawalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StoreCreditRuntimeTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        config()->set('broadcasting.default', 'null');
        config()->set('ecoluck.ticket_unit_price', 25000);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Event::fake();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('store_menu');
        Schema::dropIfExists('store_ticket_transactions');
        Schema::dropIfExists('store_wallet_transactions');
        Schema::dropIfExists('store_liability_ledger_entries');
        Schema::dropIfExists('stores');

        parent::tearDown();
    }

    public function test_capacity_snapshot_keeps_locked_store_locked_until_ticket_debt_is_clear_even_when_capacity_is_available(): void
    {
        $this->insertStore([
            'id' => 'store-locked',
            'credit_limit' => 200000,
            'ticket_debt' => 100000,
            'wallet_balance' => 0,
            'is_debt_locked' => true,
        ]);

        $snapshot = app(StoreCreditService::class)
            ->buildCapacitySnapshot(Store::query()->findOrFail('store-locked'), 25000);

        $this->assertSame(100000.0, $snapshot['display_remaining_capacity']);
        $this->assertTrue($snapshot['is_debt_locked']);
        $this->assertSame('locked', $snapshot['credit_status']);
        $this->assertTrue((bool) DB::table('stores')->where('id', 'store-locked')->value('is_debt_locked'));
    }

    public function test_wallet_deposit_greater_than_ticket_debt_unlocks_store_and_leaves_surplus_in_wallet(): void
    {
        $this->insertStore([
            'id' => 'store-paid',
            'credit_limit' => 200000,
            'ticket_debt' => 100000,
            'wallet_balance' => 0,
            'is_debt_locked' => true,
        ]);
        DB::table('store_liability_ledger_entries')->insert([
            'id' => 'liability-advance-1',
            'store_id' => 'store-paid',
            'direction' => 'debt_increase',
            'amount' => 100000,
            'balance_before' => 0,
            'balance_after' => 100000,
            'entry_type' => 'customer_refund',
            'funding_source' => 'platform_advance',
            'source_type' => 'order_return',
            'source_id' => 'return-1',
            'idempotency_key' => 'refund:return-1:store_liability',
            'created_at' => now(),
        ]);

        $result = app(StoreCreditService::class)->recordWalletDeposit('store-paid', 150000, 'DEP-PAID-001');
        $store = DB::table('stores')->where('id', 'store-paid')->first();

        $this->assertTrue($result['applied']);
        $this->assertSame(100000.0, $result['debt_repayment']);
        $this->assertSame(50000.0, $result['wallet_deposit']);
        $this->assertSame(0.0, (float) $store->ticket_debt);
        $this->assertSame(50000.0, (float) $store->wallet_balance);
        $this->assertFalse((bool) $store->is_debt_locked);
        $this->assertSame(2, DB::table('store_ticket_transactions')->where('source_id', 'DEP-PAID-001')->count());
        $this->assertSame(1, DB::table('store_liability_ledger_entries')
            ->where('store_id', 'store-paid')
            ->where('direction', 'debt_decrease')
            ->where('funding_source', 'manual_collection')
            ->count());
    }

    public function test_admin_manual_topup_repay_debt_and_auto_unlock(): void
    {
        $this->insertStore([
            'id' => 'store-topup-test',
            'credit_limit' => 200000,
            'ticket_debt' => 300000,
            'wallet_balance' => 0,
            'is_debt_locked' => true,
        ]);

        DB::table('store_liability_ledger_entries')->insert([
            'id' => 'liability-manual-test-1',
            'store_id' => 'store-topup-test',
            'direction' => 'debt_increase',
            'amount' => 300000,
            'balance_before' => 0,
            'balance_after' => 300000,
            'entry_type' => 'customer_refund',
            'funding_source' => 'platform_advance',
            'source_type' => 'order_return',
            'source_id' => 'return-test-1',
            'idempotency_key' => 'refund:return-test-1:store_liability',
            'created_at' => now(),
        ]);

        $result = app(StoreCreditService::class)->recordAdminManualTopup('store-topup-test', 150000, 'Nạp thử nghiệm', 999);
        
        $store = DB::table('stores')->where('id', 'store-topup-test')->first();

        $this->assertTrue($result['applied']);
        $this->assertSame(150000.0, $result['debt_repayment']);
        $this->assertSame(0.0, $result['wallet_deposit']);
        $this->assertSame(150000.0, (float) $store->ticket_debt);
        $this->assertSame(0.0, (float) $store->wallet_balance);
        $this->assertFalse((bool) $store->is_debt_locked);

        $walletTx = DB::table('store_wallet_transactions')->where('store_id', 'store-topup-test')->first();
        $this->assertNotNull($walletTx);
        $this->assertSame('topup', $walletTx->type);
        $this->assertSame(150000.0, (float)$walletTx->amount);
        $this->assertSame(0.0, (float)$walletTx->wallet_balance_before);
        $this->assertSame(0.0, (float)$walletTx->wallet_balance_after);
        $this->assertSame('Nạp thử nghiệm', $walletTx->note);
        $this->assertSame('999', (string)$walletTx->created_by);

        $ticketTx = DB::table('store_ticket_transactions')->where('store_id', 'store-topup-test')->first();
        $this->assertNotNull($ticketTx);
        $this->assertSame('ADMIN_MANUAL_TOPUP', $ticketTx->event_type);
        $this->assertSame(150000.0, (float)$ticketTx->amount_vnd);
        $this->assertSame(0.0, (float)$ticketTx->wallet_delta);
        $this->assertSame(-150000.0, (float)$ticketTx->debt_delta);
        $this->assertSame(0.0, (float)$ticketTx->wallet_balance_after);
        $this->assertSame(150000.0, (float)$ticketTx->ticket_debt_after);
    }

    public function test_public_store_menu_hides_debt_locked_store_and_returns_after_unlock(): void
    {
        $this->insertStore([
            'id' => 'store-public',
            'name' => 'Public Store',
            'is_debt_locked' => true,
        ]);
        DB::table('store_menu')->insert([
            'id' => 'menu-public',
            'store_id' => 'store-public',
            'item_code' => 'ITEM-1',
            'item_name' => 'Lucky item',
            'price' => 10000,
            'is_available' => true,
            'is_featured' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controller = new ClientCatalogController();
        $hiddenResponse = $controller->storeMenu('store-public', Request::create('/api/stores/store-public/menu', 'GET'));

        $this->assertSame(404, $hiddenResponse->getStatusCode());

        DB::table('stores')->where('id', 'store-public')->update([
            'is_debt_locked' => false,
            'debt_locked_at' => null,
            'debt_lock_reason' => null,
        ]);

        $visibleResponse = $controller->storeMenu('store-public', Request::create('/api/stores/store-public/menu', 'GET'));
        $payload = $visibleResponse->getData(true);

        $this->assertSame(200, $visibleResponse->getStatusCode());
        $this->assertSame('store-public', $payload['store']['id']);
        $this->assertCount(1, $payload['items']);
    }

    public function test_public_store_menu_hides_manually_closed_store(): void
    {
        $this->insertStore([
            'id' => 'store-manually-closed',
            'name' => 'Closed Store',
            'is_open' => false,
        ]);
        DB::table('store_menu')->insert([
            'id' => 'menu-closed',
            'store_id' => 'store-manually-closed',
            'item_code' => 'ITEM-CLOSED',
            'item_name' => 'Hidden item',
            'price' => 10000,
            'is_available' => true,
            'is_featured' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = (new ClientCatalogController())->storeMenu(
            'store-manually-closed',
            Request::create('/api/stores/store-manually-closed/menu', 'GET')
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_existing_pending_order_can_still_be_credit_checked_after_store_is_closed(): void
    {
        $this->insertStore([
            'id' => 'store-closed-after-order',
            'is_open' => false,
        ]);
        DB::table('orders')->insert([
            'id' => 'order-before-close',
            'order_code' => 'ORDER-BEFORE-CLOSE',
            'store_id' => 'store-closed-after-order',
            'status' => 'pending',
            'required_topup' => 0,
            'ticket_fee_vnd' => 0,
            'credit_snapshot_version' => 0,
            'credit_status' => 'unchecked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'id' => 'item-before-close',
            'order_id' => 'order-before-close',
            'item_name' => 'Existing order item',
            'quantity' => 1,
            'unit_price' => 10000,
            'ticket_reward_quantity' => 1,
            'created_at' => now(),
        ]);

        $snapshot = app(StoreCreditService::class)
            ->precheckPendingOrder(Order::query()->findOrFail('order-before-close'));

        $this->assertSame('ok', $snapshot['credit_status']);
        $this->assertSame('pending', DB::table('orders')->where('id', 'order-before-close')->value('status'));
    }

    public function test_manually_closed_store_rejects_new_order_validation(): void
    {
        $this->insertStore([
            'id' => 'store-closed-before-order',
            'is_open' => false,
        ]);

        $method = new ReflectionMethod(ClientOrderController::class, 'validateStoreOperatingStatus');
        $method->setAccessible(true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cửa hàng hiện đang tạm nghỉ');

        $method->invoke(
            new ClientOrderController(),
            Store::query()->findOrFail('store-closed-before-order')
        );
    }

    public function test_store_admin_serialized_credit_keeps_locked_flag_until_ticket_debt_is_clear(): void
    {
        $this->insertStore([
            'id' => 'store-admin',
            'credit_limit' => 200000,
            'ticket_debt' => 100000,
            'wallet_balance' => 0,
            'is_debt_locked' => true,
        ]);

        $method = new ReflectionMethod(StoreAdminAuthController::class, 'serializeStore');
        $method->setAccessible(true);
        $payload = $method->invoke(new StoreAdminAuthController(), Store::query()->findOrFail('store-admin'));

        $this->assertTrue($payload['credit']['is_debt_locked']);
        $this->assertTrue((bool) DB::table('stores')->where('id', 'store-admin')->value('is_debt_locked'));
    }

    public function test_precheck_pending_order_sets_initial_credit_snapshot_after_items_exist(): void
    {
        $this->insertStore([
            'id' => 'store-precheck',
            'credit_limit' => 200000,
            'ticket_debt' => 190000,
            'wallet_balance' => 0,
            'is_debt_locked' => false,
        ]);
        DB::table('orders')->insert([
            'id' => 'order-precheck',
            'order_code' => 'ORDER-PRECHECK',
            'store_id' => 'store-precheck',
            'status' => 'pending',
            'required_topup' => 0,
            'ticket_fee_vnd' => 0,
            'credit_snapshot_version' => 0,
            'credit_status' => 'unchecked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'id' => 'item-precheck',
            'order_id' => 'order-precheck',
            'item_name' => 'Ticket item',
            'quantity' => 1,
            'unit_price' => 10000,
            'ticket_reward_quantity' => 2,
            'created_at' => now(),
        ]);

        $snapshot = app(StoreCreditService::class)
            ->precheckPendingOrder(Order::query()->findOrFail('order-precheck'));
        $order = DB::table('orders')->where('id', 'order-precheck')->first();

        $this->assertSame(50000.0, $snapshot['ticket_fee_vnd']);
        $this->assertSame(40000.0, $snapshot['required_topup']);
        $this->assertSame('insufficient', $snapshot['credit_status']);
        $this->assertSame(50000.0, (float) $order->ticket_fee_vnd);
        $this->assertSame(40000.0, (float) $order->required_topup);
        $this->assertSame('insufficient', $order->credit_status);
        $this->assertNotNull($order->credit_checked_at);
    }

    public function test_store_wallet_withdrawal_ledger_payload_satisfies_required_ticket_columns(): void
    {
        Schema::dropIfExists('store_ticket_transactions');
        Schema::create('store_ticket_transactions', function (Blueprint $table): void {
            $table->string('transaction_id')->primary();
            $table->string('store_id')->nullable();
            $table->integer('tickets_consumed');
            $table->decimal('amount_vnd', 15, 2)->default(0);
            $table->decimal('wallet_delta', 15, 2)->default(0);
            $table->decimal('debt_delta', 15, 2)->default(0);
            $table->decimal('wallet_balance_after', 15, 2)->nullable();
            $table->decimal('ticket_debt_after', 15, 2)->nullable();
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 160)->nullable();
            $table->string('event_type', 50)->default('ORDER_DELIVERED');
            $table->json('metadata')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });

        $method = new ReflectionMethod(StoreFinanceWithdrawalService::class, 'ledgerPayload');
        $method->setAccessible(true);

        $payload = $method->invoke(
            app(StoreFinanceWithdrawalService::class),
            'store-withdrawal-ledger',
            375500.0,
            -375500.0,
            0.0,
            0.0,
            'store-withdrawal:request-1:requested',
            'request-1',
            '23',
            'WALLET_WITHDRAW'
        );

        DB::table('store_ticket_transactions')->insert($payload);
        $row = DB::table('store_ticket_transactions')
            ->where('idempotency_key', 'store-withdrawal:request-1:requested')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->tickets_consumed);
        $this->assertSame(-375500.0, (float) $row->wallet_delta);
    }

    public function test_wallet_history_preserves_return_refund_event_and_ledger_deltas(): void
    {
        $this->insertStore([
            'id' => 'store-wallet-history',
            'owner_id' => '77',
        ]);
        DB::table('store_ticket_transactions')->insert([
            [
                'transaction_id' => 'tx-order-accepted',
                'order_id' => 'order-cash',
                'store_id' => 'store-wallet-history',
                'event_type' => 'ORDER_ACCEPTED',
                'amount_vnd' => 25000,
                'wallet_delta' => 0,
                'debt_delta' => 25000,
                'idempotency_key' => 'test:order-accepted',
                'source_type' => null,
                'source_id' => null,
                'metadata' => json_encode(['order_code' => 'ORDER-CASH']),
                'timestamp' => now()->subMinute(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'transaction_id' => 'tx-return-refund',
                'order_id' => 'order-cash',
                'store_id' => 'store-wallet-history',
                'event_type' => 'RETURN_TICKET_REFUND',
                'amount_vnd' => 25000,
                'wallet_delta' => 0,
                'debt_delta' => -25000,
                'idempotency_key' => 'test:return-refund',
                'source_type' => 'order_return',
                'source_id' => 'return-1',
                'metadata' => json_encode(['return_code' => 'RET-001']),
                'timestamp' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $user = new User();
        $user->forceFill(['id' => 77, 'role' => 'store_admin']);
        $request = Request::create('/api/store-admin/finance/wallet-logs', 'GET', [
            'store_id' => 'store-wallet-history',
            'type' => 'all',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(StoreAdminFinanceController::class)->walletLogs($request);
        $payload = $response->getData(true);
        $refund = collect($payload['data'])->firstWhere('transaction_id', 'tx-return-refund');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('RETURN_TICKET_REFUND', $refund['event_type']);
        $this->assertSame(0.0, (float) $refund['wallet_delta']);
        $this->assertSame(-25000.0, (float) $refund['debt_delta']);
        $this->assertCount(2, $payload['data']);
    }

    private function createSchema(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_code', 50)->nullable();
            $table->string('owner_id')->nullable();
            $table->string('sale_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('cover_image')->nullable();
            $table->text('banner_url')->nullable();
            $table->text('banner_urls')->nullable();
            $table->string('status', 50)->default('approved');
            $table->boolean('is_open')->default(true);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->integer('min_order_tickets')->nullable();
            $table->integer('max_order_tickets')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(200000);
            $table->decimal('ticket_debt', 15, 2)->default(0);
            $table->decimal('wallet_balance', 15, 2)->default(0);
            $table->unsignedSmallInteger('credit_level')->default(1);
            $table->unsignedSmallInteger('credit_score')->default(100);
            $table->boolean('is_debt_locked')->default(false);
            $table->unsignedInteger('credit_version')->default(0);
            $table->timestamp('debt_locked_at')->nullable();
            $table->string('debt_lock_reason', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('store_wallet_transactions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('wallet_balance_before', 15, 2)->default(0);
            $table->decimal('wallet_balance_after', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('reference_code', 100)->nullable();
            $table->string('created_by')->nullable();
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

        Schema::create('store_menu', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id')->nullable();
            $table->string('item_code', 50)->nullable();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('original_price', 15, 2)->nullable();
            $table->string('category', 100)->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_code', 50)->unique();
            $table->string('store_id')->nullable();
            $table->string('status', 50)->default('pending');
            $table->integer('total_tickets_used')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->timestamp('timeout_at')->nullable();
            $table->integer('sla_timeout_minutes')->default(15);
            $table->boolean('sla_warning_sent')->default(false);
            $table->decimal('required_topup', 15, 2)->default(0);
            $table->decimal('ticket_fee_vnd', 15, 2)->default(0);
            $table->timestamp('credit_checked_at')->nullable();
            $table->unsignedInteger('credit_snapshot_version')->default(0);
            $table->string('credit_status', 32)->default('unchecked');
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('item_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->integer('ticket_reward_quantity')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    private function insertStore(array $overrides = []): void
    {
        DB::table('stores')->insert(array_merge([
            'id' => 'store-default',
            'store_code' => 'STORE-DEFAULT',
            'name' => 'Default Store',
            'address' => 'Default Address',
            'status' => 'approved',
            'is_open' => true,
            'credit_limit' => 200000,
            'ticket_debt' => 0,
            'wallet_balance' => 0,
            'credit_level' => 1,
            'credit_score' => 100,
            'is_debt_locked' => false,
            'credit_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
