<?php

namespace Tests\Feature;

use App\Models\AffiliateCommission;
use App\Models\AffiliatePayoutBatch;
use App\Services\AffiliateCommissionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BootstrapsAffiliateTestSchema;
use Tests\TestCase;

class ClientCommissionPayoutBatchTest extends TestCase
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

    public function test_client_can_review_payout_batch_tax_and_paid_batch_does_not_credit_wallet_again(): void
    {
        $this->setSystemConfig(AffiliateCommissionService::CONFIG_MINIMUM_PAYOUT, '0');
        $this->setSystemConfig(AffiliateCommissionService::CONFIG_TAX_WITHHOLDING_RATE, '10');

        $admin = $this->makeUser(['role' => 'admin']);
        $affiliate = $this->makeUser([
            'full_name' => 'Affiliate Payout',
            'role' => 'affiliate',
        ]);
        $affiliate->forceFill(['balance' => 123456])->save();
        $this->makeAffiliateProfile($affiliate->id);
        $this->makeBankAccount($affiliate->id);

        $approvedAt = now()->subDay();
        AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'pending_amount' => 70000,
            'approved_amount' => 70000,
            'status' => AffiliateCommission::STATUS_APPROVED,
            'approved_at' => $approvedAt,
            'eligible_at' => $approvedAt,
            'created_at' => $approvedAt,
            'updated_at' => $approvedAt,
        ]);
        AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'pending_amount' => 30000,
            'approved_amount' => 30000,
            'status' => AffiliateCommission::STATUS_APPROVED,
            'approved_at' => $approvedAt,
            'eligible_at' => $approvedAt,
            'created_at' => $approvedAt,
            'updated_at' => $approvedAt,
        ]);

        Sanctum::actingAs($admin);
        $createResponse = $this->postJson('/api/admin/affiliate/payout-batches', [
            'payout_period_from' => now()->subDays(2)->toDateString(),
            'payout_period_to' => now()->toDateString(),
            'note' => 'Monthly payout batch',
        ]);

        $createResponse->assertOk()
            ->assertJsonPath('data.status', AffiliatePayoutBatch::STATUS_DRAFT)
            ->assertJsonPath('data.total_commission', 100000)
            ->assertJsonPath('data.total_tax_withholding', 10000)
            ->assertJsonPath('data.total_payout', 90000);

        $batchId = (int) $createResponse->json('data.id');

        Sanctum::actingAs($affiliate);
        $listResponse = $this->getJson('/api/commission/payouts');

        $listResponse->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.batch_id', $batchId)
            ->assertJsonPath('data.items.0.status', AffiliatePayoutBatch::STATUS_DRAFT)
            ->assertJsonPath('data.items.0.gross_amount', 100000)
            ->assertJsonPath('data.items.0.tax_withholding_amount', 10000)
            ->assertJsonPath('data.items.0.net_payout_amount', 90000);

        $detailResponse = $this->getJson('/api/commission/payouts/' . $batchId);

        $detailResponse->assertOk()
            ->assertJsonPath('data.batch.id', $batchId)
            ->assertJsonPath('data.batch.status', AffiliatePayoutBatch::STATUS_DRAFT)
            ->assertJsonPath('data.commission_count', 2)
            ->assertJsonPath('data.gross_amount', 100000)
            ->assertJsonPath('data.tax_withholding_amount', 10000)
            ->assertJsonPath('data.net_payout_amount', 90000);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/affiliate/payout-batches/' . $batchId . '/approve')
            ->assertOk()
            ->assertJsonPath('data.status', AffiliatePayoutBatch::STATUS_APPROVED);

        $paidResponse = $this->postJson('/api/admin/affiliate/payout-batches/' . $batchId . '/mark-paid', [
            'payment_reference' => 'BANK-TRANSFER-001',
        ]);

        $paidResponse->assertOk()
            ->assertJsonPath('data.status', AffiliatePayoutBatch::STATUS_PAID)
            ->assertJsonPath('data.total_payout', 90000);

        $this->assertSame(2, AffiliateCommission::query()
            ->where('payout_batch_id', $batchId)
            ->where('status', AffiliateCommission::STATUS_PAID)
            ->count());
        $this->assertSame(10000.0, (float) AffiliateCommission::query()
            ->where('payout_batch_id', $batchId)
            ->sum('tax_withholding_amount'));
        $this->assertSame(90000.0, (float) AffiliateCommission::query()
            ->where('payout_batch_id', $batchId)
            ->sum('payout_amount'));
        $this->assertSame(123456, (int) $affiliate->refresh()->balance);
    }
}
