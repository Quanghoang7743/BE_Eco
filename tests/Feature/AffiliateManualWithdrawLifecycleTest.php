<?php

namespace Tests\Feature;

use App\Http\Controllers\BankAccountController;
use App\Models\WithdrawRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BootstrapsAffiliateTestSchema;
use Tests\TestCase;

class AffiliateManualWithdrawLifecycleTest extends TestCase
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

        $this->resetAffiliateTestSchema();
        $this->bootAffiliateTestSchema();
    }

    public function test_user_can_create_manual_withdraw_request_and_balance_is_locked(): void
    {
        $affiliate = $this->makeUser(['full_name' => 'Affiliate Manual']);
        $affiliate->forceFill(['balance' => 500000])->save();
        $this->makeBankAccount($affiliate->id);

        Sanctum::actingAs($affiliate);

        $response = $this->postJson('/api/wallet/affiliate-withdraw', [
            'amount' => 200000,
            'note' => 'Rut thu cong',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', WithdrawRequest::STATUS_PENDING);

        $this->assertSame(300000, (int) $affiliate->refresh()->balance);
        $this->assertDatabaseHas('withdraw_requests', [
            'user_id' => $affiliate->id,
            'amount' => 200000,
            'status' => WithdrawRequest::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('affiliate_wallet_logs', [
            'user_id' => (string) $affiliate->id,
            'action_type' => 'affiliate_withdraw_request',
            'amount_change' => -200000,
            'balance_before' => 500000,
            'balance_after' => 300000,
        ]);
    }

    public function test_withdraw_creation_requires_bank_account_sufficient_balance_and_no_active_request(): void
    {
        $affiliate = $this->makeUser(['full_name' => 'Affiliate Guard']);
        $affiliate->forceFill(['balance' => 500000])->save();

        Sanctum::actingAs($affiliate);

        $this->postJson('/api/wallet/affiliate-withdraw', ['amount' => 200000])
            ->assertStatus(422);

        $this->makeBankAccount($affiliate->id);

        $this->postJson('/api/wallet/affiliate-withdraw', ['amount' => 99999])
            ->assertStatus(422);

        $this->postJson('/api/wallet/affiliate-withdraw', ['amount' => 600000])
            ->assertStatus(422);

        DB::table('withdraw_requests')->insert([
            'user_id' => $affiliate->id,
            'amount' => 100000,
            'status' => WithdrawRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/wallet/affiliate-withdraw', ['amount' => 100000])
            ->assertStatus(409)
            ->assertJsonPath('active_request_status', WithdrawRequest::STATUS_APPROVED);

        $this->assertSame(500000, (int) $affiliate->refresh()->balance);
    }

    public function test_admin_can_approve_then_reject_and_refund_once(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $affiliate = $this->makeUser(['full_name' => 'Affiliate Refund']);
        $affiliate->forceFill(['balance' => 300000])->save();
        $withdrawRequest = $this->makeWithdrawRequest($affiliate->id, 200000, WithdrawRequest::STATUS_PENDING);

        $this->callAdminWithdrawUpdate($admin, $withdrawRequest->id, [
            'status' => WithdrawRequest::STATUS_APPROVED,
        ]);

        $withdrawRequest->refresh();
        $this->assertSame(WithdrawRequest::STATUS_APPROVED, $withdrawRequest->status);
        $this->assertSame($admin->id, (int) $withdrawRequest->approved_by);
        $this->assertNotNull($withdrawRequest->approved_at);
        $this->assertSame(300000, (int) $affiliate->refresh()->balance);

        $this->callAdminWithdrawUpdate($admin, $withdrawRequest->id, [
            'status' => WithdrawRequest::STATUS_REJECTED,
            'rejection_reason' => 'Sai thong tin ngan hang',
        ]);

        $withdrawRequest->refresh();
        $this->assertSame(WithdrawRequest::STATUS_REJECTED, $withdrawRequest->status);
        $this->assertSame('Sai thong tin ngan hang', $withdrawRequest->rejection_reason);
        $this->assertSame(500000, (int) $affiliate->refresh()->balance);
        $this->assertSame(1, DB::table('affiliate_wallet_logs')->where('action_type', 'affiliate_withdraw_refund')->count());

        $this->callAdminWithdrawUpdate($admin, $withdrawRequest->id, [
            'status' => WithdrawRequest::STATUS_REJECTED,
            'rejection_reason' => 'Sai thong tin ngan hang',
        ]);

        $this->assertSame(500000, (int) $affiliate->refresh()->balance);
        $this->assertSame(1, DB::table('affiliate_wallet_logs')->where('action_type', 'affiliate_withdraw_refund')->count());
    }

    public function test_admin_can_mark_approved_request_as_paid_and_terminal_status_cannot_be_changed(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $affiliate = $this->makeUser(['full_name' => 'Affiliate Paid']);
        $affiliate->forceFill(['balance' => 300000])->save();
        $withdrawRequest = $this->makeWithdrawRequest($affiliate->id, 200000, WithdrawRequest::STATUS_APPROVED, [
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $this->callAdminWithdrawUpdate($admin, $withdrawRequest->id, [
            'status' => WithdrawRequest::STATUS_PAID,
        ]);

        $withdrawRequest->refresh();
        $this->assertSame(WithdrawRequest::STATUS_PAID, $withdrawRequest->status);
        $this->assertSame($admin->id, (int) $withdrawRequest->paid_by);
        $this->assertNotNull($withdrawRequest->paid_at);
        $this->assertSame(300000, (int) $affiliate->refresh()->balance);

        $this->callAdminWithdrawUpdate($admin, $withdrawRequest->id, [
            'status' => WithdrawRequest::STATUS_REJECTED,
            'rejection_reason' => 'Khong hop le',
        ]);

        $withdrawRequest->refresh();
        $this->assertSame(WithdrawRequest::STATUS_PAID, $withdrawRequest->status);
        $this->assertSame(300000, (int) $affiliate->refresh()->balance);
        $this->assertSame(0, DB::table('affiliate_wallet_logs')->where('action_type', 'affiliate_withdraw_refund')->count());
    }

    public function test_admin_can_transition_pending_request_directly_to_paid_and_approval_fields_are_populated(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $affiliate = $this->makeUser(['full_name' => 'Affiliate Direct Paid']);
        $affiliate->forceFill(['balance' => 300000])->save();
        $withdrawRequest = $this->makeWithdrawRequest($affiliate->id, 200000, WithdrawRequest::STATUS_PENDING);

        $this->callAdminWithdrawUpdate($admin, $withdrawRequest->id, [
            'status' => WithdrawRequest::STATUS_PAID,
        ]);

        $withdrawRequest->refresh();
        $this->assertSame(WithdrawRequest::STATUS_PAID, $withdrawRequest->status);
        $this->assertSame($admin->id, (int) $withdrawRequest->approved_by);
        $this->assertNotNull($withdrawRequest->approved_at);
        $this->assertSame($admin->id, (int) $withdrawRequest->paid_by);
        $this->assertNotNull($withdrawRequest->paid_at);
        $this->assertSame(300000, (int) $affiliate->refresh()->balance);
    }

    private function makeWithdrawRequest(int $userId, int $amount, string $status, array $overrides = []): WithdrawRequest
    {
        return WithdrawRequest::query()->create(array_merge([
            'user_id' => $userId,
            'amount' => $amount,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function callAdminWithdrawUpdate($admin, int $withdrawRequestId, array $payload): void
    {
        Auth::login($admin);

        $request = Request::create('/admin/bank-accounts/' . $withdrawRequestId, 'PUT', $payload);
        $request->setUserResolver(fn () => $admin);

        app(BankAccountController::class)->update($request, $withdrawRequestId);
    }
}
