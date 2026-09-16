<?php

use App\Events\AffiliateWalletUpdated;
use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Services\AffiliateCommissionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class AffiliateCommissionServiceTest extends LaravelTestCase
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

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
    }

    public function test_product_link_commission_does_not_create_f1_and_releases_once(): void
    {
        $this->insertUser(1, ['balance' => 1000]);
        $this->insertUser(2);
        $this->approveAffiliate(1);

        DB::table('stores')->insert(['id' => 'store-1', 'owner_id' => 99, 'name' => 'Store']);
        DB::table('products')->insert(['id' => 10, 'store_id' => 'store-1', 'name' => 'Shared product', 'commission_rate' => 0]);
        DB::table('orders')->insert([
            'id' => 'order-1',
            'user_id' => 2,
            'referrer_id' => null,
            'store_id' => 'store-1',
            'status' => 'delivered',
            'status_payment' => 'paid',
            'delivered_at' => now()->subDays(8),
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(8),
        ]);
        DB::table('order_items')->insert([
            'id' => 'item-1',
            'order_id' => 'order-1',
            'product_id' => 10,
            'referrer_id' => 1,
            'quantity' => 1,
            'unit_price' => 50000,
            'total_price' => 50000,
            'commission_amount' => 2500,
            'commission_rate_mode' => 'percent',
            'item_name' => 'Shared product',
        ]);

        $service = app(AffiliateCommissionService::class);
        $service->syncOrderCommissions(Order::query()->with(['items.product', 'items.variant'])->findOrFail('order-1'));

        $commission = AffiliateCommission::query()->firstOrFail();
        $this->assertSame(1, (int) $commission->affiliate_id);
        $this->assertNull($commission->source_assignment_id);
        $this->assertSame('product_link', $commission->policy_snapshot_json['source_type'] ?? null);
        $this->assertSame(0, DB::table('affiliate_customers')->count());
        $this->assertSame('pending', DB::table('commission_users')->value('status'));

        $service->approveCommission((int) $commission->id);

        Event::fake([AffiliateWalletUpdated::class]);

        $this->assertSame(1, $service->transitionWaitingReturnPeriodToEligible());
        $this->assertSame(0, $service->transitionWaitingReturnPeriodToEligible());

        $this->assertSame(3500.0, (float) DB::table('users')->where('id', 1)->value('balance'));
        $this->assertSame(1, DB::table('affiliate_wallet_logs')->where('action_type', 'affiliate_commission_credit')->count());
        $walletLog = DB::table('affiliate_wallet_logs')->where('action_type', 'affiliate_commission_credit')->first();
        $walletMetadata = json_decode((string) $walletLog->metadata, true);
        $this->assertSame('product_link', $walletMetadata['source_type'] ?? null);
        $this->assertSame('Link sản phẩm Affiliate', $walletMetadata['source_label'] ?? null);
        Event::assertDispatched(AffiliateWalletUpdated::class, function (AffiliateWalletUpdated $event): bool {
            return $event->userId === 1
                && $event->action === 'affiliate_commission_credit'
                && ($event->payload['summary']['available_balance'] ?? null) === 3500.0
                && ($event->payload['ledger_entry']['metadata']['source_type'] ?? null) === 'product_link';
        });
        $this->assertSame(AffiliateCommission::STATUS_PAID, AffiliateCommission::query()->firstOrFail()->status);
        $this->assertSame('paid', DB::table('commission_users')->value('status'));
    }

    public function test_existing_upline_wins_over_product_link_referrer(): void
    {
        $this->insertUser(1);
        $this->insertUser(2, ['referrer_id' => 1]);
        $this->insertUser(3);
        $this->approveAffiliate(1);
        $this->approveAffiliate(3);

        DB::table('stores')->insert(['id' => 'store-1', 'owner_id' => 99, 'name' => 'Store']);
        DB::table('products')->insert(['id' => 10, 'store_id' => 'store-1', 'name' => 'Shared product', 'commission_rate' => 0]);
        DB::table('orders')->insert([
            'id' => 'order-2',
            'user_id' => 2,
            'referrer_id' => null,
            'store_id' => 'store-1',
            'status' => 'delivered',
            'status_payment' => 'paid',
            'delivered_at' => now()->subDays(8),
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(8),
        ]);
        DB::table('order_items')->insert([
            'id' => 'item-2',
            'order_id' => 'order-2',
            'product_id' => 10,
            'referrer_id' => 3,
            'quantity' => 1,
            'unit_price' => 50000,
            'total_price' => 50000,
            'commission_amount' => 2500,
            'commission_rate_mode' => 'percent',
            'item_name' => 'Shared product',
        ]);

        app(AffiliateCommissionService::class)
            ->syncOrderCommissions(Order::query()->with(['items.product', 'items.variant'])->findOrFail('order-2'));

        $this->assertSame(1, (int) AffiliateCommission::query()->value('affiliate_id'));
        $this->assertSame(1, (int) DB::table('commission_users')->value('user_id'));
        $this->assertSame(1, (int) DB::table('affiliate_customers')->value('affiliate_id'));
    }

    public function test_f2_purchase_credits_only_direct_f1_when_f0_assignment_is_stale(): void
    {
        $this->insertUser(1, ['balance' => 0]);
        $this->insertUser(2, ['balance' => 1000, 'referrer_id' => 1]);
        $this->insertUser(3, ['balance' => 0, 'referrer_id' => 2]);
        $this->approveAffiliate(1);
        $this->approveAffiliate(2);

        DB::table('affiliate_customers')->insert([
            'affiliate_id' => 1,
            'customer_id' => 3,
            'source_type' => 'stale_assignment',
            'source_id' => 'legacy-f0',
            'assigned_at' => now()->subDays(20),
            'status' => 'ACTIVE',
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        DB::table('stores')->insert(['id' => 'store-1', 'owner_id' => 99, 'name' => 'Store']);
        DB::table('products')->insert(['id' => 10, 'store_id' => 'store-1', 'name' => 'Shared product', 'commission_rate' => 0]);
        DB::table('orders')->insert([
            'id' => 'order-f2',
            'user_id' => 3,
            'referrer_id' => 1,
            'store_id' => 'store-1',
            'status' => 'delivered',
            'status_payment' => 'paid',
            'final_amount' => 50000,
            'delivered_at' => now()->subDays(8),
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(8),
        ]);
        DB::table('order_items')->insert([
            'id' => 'item-f2',
            'order_id' => 'order-f2',
            'product_id' => 10,
            'referrer_id' => 1,
            'quantity' => 1,
            'unit_price' => 50000,
            'total_price' => 50000,
            'commission_amount' => 2500,
            'commission_rate_mode' => 'percent',
            'item_name' => 'Shared product',
        ]);

        $service = app(AffiliateCommissionService::class);
        $service->syncOrderCommissions(Order::query()->with(['items.product', 'items.variant'])->findOrFail('order-f2'));

        $this->assertSame(2, (int) AffiliateCommission::query()->value('affiliate_id'));
        $this->assertFalse(AffiliateCommission::query()->where('affiliate_id', 1)->exists());
        $this->assertSame(2, (int) DB::table('commission_users')->value('user_id'));
        $this->assertSame(0.0, (float) DB::table('users')->where('id', 1)->value('balance'));

        $service->approveCommission((int) AffiliateCommission::query()->value('id'));

        Event::fake([AffiliateWalletUpdated::class]);

        $this->assertSame(1, $service->transitionWaitingReturnPeriodToEligible());

        $this->assertSame(0.0, (float) DB::table('users')->where('id', 1)->value('balance'));
        $this->assertSame(3500.0, (float) DB::table('users')->where('id', 2)->value('balance'));
        $this->assertSame(0, DB::table('affiliate_wallet_logs')->where('user_id', '1')->count());
        $this->assertSame(1, DB::table('affiliate_wallet_logs')->where('user_id', '2')->where('action_type', 'affiliate_commission_credit')->count());
        $this->assertSame(0, $service->paginateAffiliateCommissionHistory(1, 'all', 1, 20)['pagination']['total']);
        $this->assertSame(1, $service->paginateAffiliateCommissionHistory(2, 'all', 1, 20)['pagination']['total']);

        $f0Summary = $service->getDashboardSummary(1);
        $this->assertSame(1, $f0Summary['referral_count']);
        $this->assertSame(0, $f0Summary['referral_order_count']);
        $this->assertSame(0.0, $f0Summary['referral_sales']);
    }

    public function test_dashboard_uses_user_balance_and_history_totals(): void
    {
        $this->insertUser(1, ['balance' => 777]);
        $this->approveAffiliate(1);

        $createdAt = '2026-06-23 12:00:00';

        DB::table('affiliate_commissions')->insert([
            [
                'affiliate_id' => 1,
                'pending_amount' => 1000,
                'approved_amount' => 1000,
                'payout_amount' => 1000,
                'status' => AffiliateCommission::STATUS_PAID,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
            [
                'affiliate_id' => 1,
                'pending_amount' => 300,
                'approved_amount' => 0,
                'payout_amount' => 0,
                'status' => AffiliateCommission::STATUS_WAITING_RETURN_PERIOD,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
        ]);
        DB::table('withdraw_requests')->insert([
            'id' => 10,
            'user_id' => 1,
            'amount' => 200,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('affiliate_payout_batches')->insert([
            'id' => 20,
            'batch_code' => 'LEGACY-WR-10',
            'status' => 'PAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('affiliate_payout_batch_items')->insert([
            'batch_id' => 20,
            'affiliate_id' => 1,
            'net_payout_amount' => 200,
            'status' => 'PAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(AffiliateCommissionService::class)->getDashboardSummary(1);

        $this->assertSame(777.0, $summary['available_balance']);
        $this->assertSame(300.0, $summary['commission_pending']);
        $this->assertSame(300.0, $summary['pending_balance']);
        $this->assertSame(200.0, $summary['commission_withdrawn']);
        $this->assertSame(200.0, $summary['total_withdrawn']);
        $this->assertSame(1000.0, $summary['total_income']);

        DB::table('affiliate_commissions')->insert([
            'affiliate_id' => 1,
            'pending_amount' => 500,
            'approved_amount' => 500,
            'payout_amount' => 0,
            'status' => AffiliateCommission::STATUS_APPROVED,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $chart = app(AffiliateCommissionService::class)->commissionChart(
            1,
            \Carbon\Carbon::parse('2026-06-01 00:00:00'),
            \Carbon\Carbon::parse('2026-06-30 23:59:59')
        );

        $chartRows = $chart['commission_rows'];
        $availableCommission = array_sum(array_column($chartRows, 'available_commission'));
        $paidCommission = array_sum(array_column($chartRows, 'paid_commission'));

        $this->assertSame(1000.0, $availableCommission);
        $this->assertSame(1000.0, $paidCommission);
    }

    private function insertUser(int $id, array $overrides = []): void
    {
        DB::table('users')->insert(array_merge([
            'id' => $id,
            'name' => 'User ' . $id,
            'email' => 'user' . $id . '@example.test',
            'password' => 'secret',
            'balance' => 0,
            'referrer_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function approveAffiliate(int $userId): void
    {
        DB::table('affiliate_profiles')->insert([
            'user_id' => $userId,
            'personal_tax_code' => 'TAX' . $userId,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_accounts')->insert([
            'user_id' => $userId,
            'account_name' => 'Affiliate ' . $userId,
            'account_number' => '000' . $userId,
            'bank_name' => 'Test Bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('personal_tax_code')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('name')->nullable();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('store_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('commission_rate', 12, 2)->nullable();
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('commission_rate', 12, 2)->nullable();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->string('store_id')->nullable();
            $table->string('status')->nullable();
            $table->string('status_payment')->nullable();
            $table->decimal('final_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->string('commission_rate_mode')->nullable();
            $table->string('item_name')->nullable();
            $table->string('product_name')->nullable();
        });

        Schema::create('affiliate_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('first_order_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status')->default('active');
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
            $table->decimal('commission_base_amount', 12, 2)->default(0);
            $table->decimal('pending_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('tax_withholding_amount', 12, 2)->default(0);
            $table->decimal('payout_amount', 12, 2)->default(0);
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

        Schema::create('commission_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('order_item_id');
            $table->unsignedTinyInteger('level')->default(1);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
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

        Schema::create('withdraw_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('affiliate_payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_code')->nullable();
            $table->string('status')->default('DRAFT');
            $table->timestamps();
        });

        Schema::create('affiliate_payout_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->decimal('net_payout_amount', 14, 2)->default(0);
            $table->string('status')->default('DRAFT');
            $table->timestamps();
        });
    }
}
