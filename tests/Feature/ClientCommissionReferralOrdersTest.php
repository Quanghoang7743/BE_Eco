<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\AffiliateCommissionService;
use App\Services\AffiliateCustomerService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BootstrapsAffiliateTestSchema;
use Tests\TestCase;

class ClientCommissionReferralOrdersTest extends TestCase
{
    use BootstrapsAffiliateTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->bootAffiliateTestSchema();
    }

    public function test_referral_orders_include_orders_from_active_affiliate_customer_assignment(): void
    {
        $affiliate = $this->makeUser([
            'full_name' => 'Affiliate A',
            'role' => 'affiliate',
        ]);
        $this->makeAffiliateProfile($affiliate->id, [
            'affiliate_code' => 'AFF-A',
        ]);
        $this->makeBankAccount($affiliate->id);

        $customer = $this->makeUser([
            'full_name' => 'Customer B',
        ]);
        app(AffiliateCustomerService::class)->createRegistrationAssignment($customer, (int) $affiliate->id, [
            'source_type' => 'affiliate_link',
            'source_id' => 'AFF-A',
        ]);

        $storeId = $this->makeStore();
        $productId = $this->makeProduct([
            'store_id' => $storeId,
            'commission_rate' => 12,
        ]);
        $order = $this->makeOrder([
            'id' => 'order-assignment-only',
            'user_id' => $customer->id,
            'referrer_id' => null,
            'store_id' => $storeId,
            'status' => 'delivered',
            'status_payment' => 'paid',
            'total' => 200000,
            'total_amount' => 200000,
            'final_amount' => 200000,
            'delivered_at' => now()->subDays(8),
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(8),
        ]);
        $this->makeOrderItem($order, [
            'id' => 'item-assignment-only',
            'product_id' => $productId,
            'commission_amount' => null,
            'commission_rate_mode' => 'percent',
            'quantity' => 1,
            'price' => 200000,
            'unit_price' => 200000,
            'total_price' => 200000,
        ]);

        app(AffiliateCommissionService::class)
            ->syncOrderCommissions(Order::query()->with(['items.product', 'items.variant'])->findOrFail($order->id));

        $this->assertDatabaseHas('affiliate_commissions', [
            'affiliate_id' => $affiliate->id,
            'order_id' => $order->id,
            'order_item_id' => 'item-assignment-only',
            'pending_amount' => 24000,
        ]);

        Sanctum::actingAs($affiliate);

        $response = $this->getJson('/api/commission/referral-orders?page_size=100');

        $response->assertOk();
        $this->assertContains(
            $order->id,
            collect($response->json('data.items'))->pluck('order_id')->all()
        );
        $response->assertJsonPath('data.items.0.commission_amount', 24000);
    }
}
