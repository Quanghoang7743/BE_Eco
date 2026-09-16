<?php

namespace Tests\Support;

use App\Models\AffiliateCustomer;
use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

trait BootstrapsAffiliateTestSchema
{
    protected function bootAffiliateTestSchema(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('personal_full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('avatar')->nullable();
            $table->string('zalo_id')->nullable();
            $table->string('role')->default('user');
            $table->string('password')->nullable();
            $table->decimal('balance', 14, 2)->default(0);
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->date('birthday')->nullable();
            $table->text('personal_address')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('id_number')->nullable();
            $table->string('personal_tax_code')->nullable();
            $table->string('status')->default(AffiliateProfile::STATUS_APPROVED);
            $table->string('affiliate_code')->nullable();
            $table->string('affiliate_link')->nullable();
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('account_name');
            $table->string('account_number');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('affiliate_wallet_logs', function (Blueprint $table) {
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

        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount', 14, 2);
            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->string('image')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_open')->default(true);
            $table->text('address')->nullable();
            $table->string('public_url')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('store_id', 36)->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('status')->nullable();
            $table->string('thumbnail')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->integer('stock')->default(0);
            $table->decimal('commission_rate', 10, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('stock')->default(0);
            $table->decimal('commission_rate', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('order_code')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->char('store_id', 36)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status_payment')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('total_tickets_used')->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('final_amount', 14, 2)->default(0);
            $table->unsignedInteger('sla_timeout_minutes')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('order_id', 36);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('commission_amount', 14, 2)->nullable();
            $table->string('commission_rate_mode')->nullable();
        });

        Schema::create('affiliate_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->char('first_order_id', 36)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status')->default(AffiliateCustomer::STATUS_ACTIVE);
            $table->unsignedBigInteger('transferred_to_affiliate_id')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->text('transfer_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('object_type');
            $table->string('object_id');
            $table->text('old_value_json')->nullable();
            $table->text('new_value_json')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->text('config_value')->nullable();
            $table->string('value_type')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('referred_user_id')->nullable();
            $table->char('order_item_id', 36)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedInteger('level')->default(1);
            $table->string('status')->default('pending');
            $table->boolean('agency_rights')->default(false);
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('order_id', 36);
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->string('changed_by')->nullable();
            $table->string('changed_by_role')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('store_admin_notifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('store_id', 36)->nullable();
            $table->char('order_id', 36)->nullable();
            $table->string('order_code')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->text('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 100)->nullable();
            $table->char('store_id', 36)->nullable();
            $table->char('order_id', 36)->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->text('body')->nullable();
            $table->string('audience_scope')->default('admin_shared');
            $table->string('action_url', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('order_id', 36)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->char('order_id', 36)->nullable();
            $table->char('order_item_id', 36)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->char('seller_id', 36)->nullable();
            $table->string('commission_type')->default('percent');
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->decimal('commission_base_amount', 12, 2)->default(0);
            $table->decimal('pending_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('tax_withholding_amount', 12, 2)->default(0);
            $table->decimal('payout_amount', 12, 2)->default(0);
            $table->string('status', 50)->default('PENDING');
            $table->text('reason_cancelled')->nullable();
            $table->text('held_reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('payout_batch_id')->nullable();
            $table->unsignedBigInteger('source_assignment_id')->nullable();
            $table->text('policy_snapshot_json')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 50)->unique();
            $table->date('payout_period_from');
            $table->date('payout_period_to');
            $table->unsignedInteger('total_affiliates')->default(0);
            $table->decimal('total_commission', 14, 2)->default(0);
            $table->decimal('total_tax_withholding', 14, 2)->default(0);
            $table->decimal('total_payout', 14, 2)->default(0);
            $table->string('status', 50)->default('DRAFT');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_payout_batch_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedInteger('commission_count')->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('tax_withholding_amount', 14, 2)->default(0);
            $table->decimal('net_payout_amount', 14, 2)->default(0);
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('status', 50)->default('DRAFT');
            $table->string('payment_reference')->nullable();
            $table->string('payment_proof_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

    }

    protected function resetAffiliateTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'affiliate_payout_batch_items',
            'affiliate_payout_batches',
            'affiliate_commissions',
            'complaints',
            'notifications',
            'store_admin_notifications',
            'order_status_history',
            'commission_users',
            'personal_access_tokens',
            'system_configs',
            'affiliate_audit_logs',
            'affiliate_customers',
            'order_items',
            'orders',
            'product_variants',
            'products',
            'stores',
            'withdraw_requests',
            'affiliate_wallet_logs',
            'bank_accounts',
            'banks',
            'affiliate_profiles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    protected function makeUser(array $overrides = []): User
    {
        static $seq = 1;

        return User::query()->create(array_merge([
            'name' => 'User ' . $seq,
            'full_name' => 'User ' . $seq,
            'email' => 'user' . $seq . '@example.com',
            'phone' => '0900000' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'password' => 'password',
            'role' => 'user',
        ], $overrides, ['id' => $overrides['id'] ?? $seq++]));
    }

    protected function makeAffiliateProfile(int $userId, array $overrides = [])
    {
        return DB::table('affiliate_profiles')->insert(array_merge([
            'user_id' => $userId,
            'id_number' => 'ID-' . $userId,
            'personal_tax_code' => 'TAX-' . $userId,
            'status' => AffiliateProfile::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeBankAccount(int $userId, array $overrides = []): int
    {
        DB::table('banks')->updateOrInsert(
            ['id' => 1],
            ['code' => 'ACB', 'name' => 'Ngân hàng TMCP Á Châu', 'image' => null, 'created_at' => now(), 'updated_at' => now()],
        );

        return DB::table('bank_accounts')->insertGetId(array_merge([
            'user_id' => $userId,
            'bank_id' => 1,
            'account_name' => 'USER ' . $userId,
            'account_number' => '000' . $userId,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function linkAffiliateCustomer(int $affiliateId, int $customerId, array $overrides = []): int
    {
        return DB::table('affiliate_customers')->insertGetId(array_merge([
            'affiliate_id' => $affiliateId,
            'customer_id' => $customerId,
            'source_type' => 'test',
            'assigned_at' => now(),
            'status' => AffiliateCustomer::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeStore(array $overrides = []): string
    {
        static $seq = 1;
        $id = $overrides['id'] ?? sprintf('store-%032d', $seq++);
        DB::table('stores')->insert(array_merge([
            'id' => $id,
            'name' => 'Store ' . $id,
            'status' => 'approved',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides, ['id' => $id]));

        return $id;
    }

    protected function makeProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'name' => 'Product test',
            'slug' => 'product-test-' . uniqid(),
            'status' => 'published',
            'commission_rate' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeOrder(array $overrides = []): Order
    {
        static $seq = 1;
        $id = $overrides['id'] ?? sprintf('order-%031d', $seq++);

        Order::query()->create(array_merge([
            'id' => $id,
            'order_code' => 'ORD-' . strtoupper(substr(md5($id), 0, 8)),
            'payment_method' => 'BANK',
            'status_payment' => 'paid',
            'status' => 'processing',
            'total' => 100000,
            'total_amount' => 100000,
            'final_amount' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides, ['id' => $id]));

        return Order::query()->findOrFail($id);
    }

    protected function makeOrderItem(Order $order, array $overrides = []): OrderItem
    {
        static $seq = 1;
        $id = $overrides['id'] ?? sprintf('item-%032d', $seq++);

        OrderItem::query()->create(array_merge([
            'id' => $id,
            'order_id' => $order->id,
            'product_name' => 'Product test',
            'item_name' => 'Product test',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'total_price' => 100000,
            'commission_amount' => 10000,
            'commission_rate_mode' => 'percent',
        ], $overrides, ['id' => $id, 'order_id' => $order->id]));

        return OrderItem::query()->findOrFail($id);
    }

    protected function setSystemConfig(string $key, string $value): void
    {
        DB::table('system_configs')->updateOrInsert(
            ['config_key' => $key],
            [
                'config_value' => $value,
                'value_type' => 'string',
                'description' => null,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
