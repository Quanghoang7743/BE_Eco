<?php

namespace Tests\Feature;

use App\Models\ClientUserVerification;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillApprovedUserVerificationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();

        ClientUserVerification::query()->delete();
        User::query()->delete();
    }

    public function test_dry_run_reports_candidates_without_updating_users(): void
    {
        $user = $this->makeUser([
            'full_name' => null,
            'personal_full_name' => null,
            'personal_identity_number' => null,
            'personal_address' => null,
            'birthday' => null,
        ]);

        $this->makeVerification($user->id, [
            'status' => ClientUserVerification::STATUS_APPROVED,
            'full_name' => 'Tên xác thực',
            'id_number' => '012345678901',
            'birth_date' => '1995-05-10',
            'address' => 'Địa chỉ xác thực',
        ]);

        $this->artisan('users:backfill-approved-verifications')
            ->expectsOutput('Approved verifications: 1')
            ->expectsOutput('Users needing profile backfill: 1')
            ->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->full_name);
        $this->assertNull($user->personal_full_name);
        $this->assertNull($user->personal_identity_number);
        $this->assertNull($user->personal_address);
        $this->assertNull($user->birthday);
    }

    public function test_apply_backfills_only_empty_user_fields_from_approved_verifications(): void
    {
        $blankUser = $this->makeUser([
            'full_name' => null,
            'personal_full_name' => null,
            'personal_identity_number' => null,
            'personal_address' => null,
            'birthday' => null,
        ]);
        $this->makeVerification($blankUser->id, [
            'status' => ClientUserVerification::STATUS_APPROVED,
            'full_name' => 'Tên xác thực',
            'id_number' => '012345678901',
            'birth_date' => '1995-05-10',
            'address' => 'Địa chỉ xác thực',
        ]);

        $partiallyFilledUser = $this->makeUser([
            'full_name' => 'Tên tài khoản',
            'personal_full_name' => 'Tên đã có',
            'personal_identity_number' => '999999999999',
            'personal_address' => null,
            'birthday' => '1990-01-01',
        ]);
        $this->makeVerification($partiallyFilledUser->id, [
            'status' => ClientUserVerification::STATUS_APPROVED,
            'full_name' => 'Tên hồ sơ không ghi đè',
            'id_number' => '111111111111',
            'birth_date' => '1999-09-09',
            'address' => 'Địa chỉ được điền',
        ]);

        $pendingUser = $this->makeUser([
            'full_name' => null,
            'personal_full_name' => null,
        ]);
        $this->makeVerification($pendingUser->id, [
            'status' => ClientUserVerification::STATUS_PENDING,
            'full_name' => 'Tên chờ duyệt',
        ]);

        $this->artisan('users:backfill-approved-verifications --apply')
            ->expectsOutput('Approved verifications: 2')
            ->expectsOutput('Users needing profile backfill: 2')
            ->expectsOutput('Backfilled users: 2')
            ->assertSuccessful();

        $blankUser->refresh();
        $this->assertSame('Tên xác thực', $blankUser->full_name);
        $this->assertSame('Tên xác thực', $blankUser->personal_full_name);
        $this->assertSame('012345678901', $blankUser->personal_identity_number);
        $this->assertSame('Địa chỉ xác thực', $blankUser->personal_address);
        $this->assertSame('1995-05-10', $blankUser->birthday?->toDateString());

        $partiallyFilledUser->refresh();
        $this->assertSame('Tên tài khoản', $partiallyFilledUser->full_name);
        $this->assertSame('Tên đã có', $partiallyFilledUser->personal_full_name);
        $this->assertSame('999999999999', $partiallyFilledUser->personal_identity_number);
        $this->assertSame('Địa chỉ được điền', $partiallyFilledUser->personal_address);
        $this->assertSame('1990-01-01', $partiallyFilledUser->birthday?->toDateString());

        $pendingUser->refresh();
        $this->assertNull($pendingUser->full_name);
        $this->assertNull($pendingUser->personal_full_name);
    }

    public function test_customer_datatable_includes_customer_roles_and_searches_verification_fields(): void
    {
        $this->withoutMiddleware();

        $affiliate = $this->makeUser([
            'name' => 'Hoàng Quang',
            'full_name' => 'Hoàng Quang',
            'phone' => '+84865770777',
            'role' => 'affiliate',
        ]);
        $this->makeVerification($affiliate->id, [
            'full_name' => 'HOÀNG MINH QUANG',
            'id_number' => '019204989231',
            'status' => ClientUserVerification::STATUS_APPROVED,
        ]);

        $pendingStore = $this->makeUser([
            'name' => 'Store Pending',
            'phone' => '+84000000001',
            'role' => 'admin_store_pending',
        ]);
        $this->makeVerification($pendingStore->id, [
            'status' => ClientUserVerification::STATUS_PENDING,
        ]);

        $this->makeUser([
            'name' => 'Store Admin',
            'phone' => '+84000000004',
            'role' => 'admin_store',
        ]);

        $this->makeUser([
            'name' => 'Account User',
            'phone' => '+84000000002',
            'role' => 'account_user',
        ]);

        $this->makeUser([
            'name' => 'Internal Admin',
            'phone' => '+84000000003',
            'role' => 'admin',
        ]);

        $response = $this->getJson(route('admin.users.datatable', [
            'draw' => 1,
            'start' => 0,
            'length' => 20,
            'list_type' => 'customer',
            'search' => ['value' => 'HOÀNG MINH QUANG'],
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertSame('+84865770777', $response->json('data.0.phone'));
        $this->assertStringContainsString('Hoàng Quang', $response->json('data.0.name'));
        $this->assertStringContainsString('User', $response->json('data.0.role'));
        $this->assertStringNotContainsString('Affiliate', $response->json('data.0.role'));
        $this->assertStringContainsString('Đã duyệt', $response->json('data.0.verification_status'));

        $userFilterResponse = $this->getJson(route('admin.users.datatable', [
            'draw' => 1,
            'start' => 0,
            'length' => 20,
            'list_type' => 'customer',
            'role_filter' => 'user',
        ]));

        $userFilterResponse->assertOk();
        $userPhones = collect($userFilterResponse->json('data'))->pluck('phone')->all();
        $this->assertContains('+84865770777', $userPhones);
        $this->assertContains('+84000000002', $userPhones);
        $this->assertNotContains('+84000000001', $userPhones);

        $storeFilterResponse = $this->getJson(route('admin.users.datatable', [
            'draw' => 1,
            'start' => 0,
            'length' => 20,
            'list_type' => 'customer',
            'role_filter' => 'admin_store',
        ]));

        $storeFilterResponse->assertOk();
        $storePhones = collect($storeFilterResponse->json('data'))->pluck('phone')->all();
        $this->assertContains('+84000000001', $storePhones);
        $this->assertContains('+84000000004', $storePhones);
        $this->assertNotContains('+84865770777', $storePhones);

        $directTechnicalFilterResponse = $this->getJson(route('admin.users.datatable', [
            'draw' => 1,
            'start' => 0,
            'length' => 20,
            'list_type' => 'customer',
            'role_filter' => 'affiliate',
        ]));

        $directTechnicalFilterResponse->assertOk();
        $this->assertSame(0, $directTechnicalFilterResponse->json('recordsFiltered'));

        $allResponse = $this->getJson(route('admin.users.datatable', [
            'draw' => 1,
            'start' => 0,
            'length' => 20,
            'list_type' => 'customer',
        ]));

        $allResponse->assertOk();
        $allPhones = collect($allResponse->json('data'))->pluck('phone')->all();
        $this->assertContains('+84865770777', $allPhones);
        $this->assertContains('+84000000001', $allPhones);
        $this->assertContains('+84000000002', $allPhones);
        $this->assertContains('+84000000004', $allPhones);
        $this->assertNotContains('+84000000003', $allPhones);
        $this->assertSame(4, $allResponse->json('recordsTotal'));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('full_name')->nullable();
                $table->string('personal_full_name')->nullable();
                $table->string('personal_identity_number')->nullable();
                $table->string('personal_address')->nullable();
                $table->date('birthday')->nullable();
                $table->string('role')->default('user');
                $table->string('password')->nullable();
                $table->timestamps();
            });
        }

        $userColumns = [
            'full_name' => fn (Blueprint $table) => $table->string('full_name')->nullable(),
            'personal_full_name' => fn (Blueprint $table) => $table->string('personal_full_name')->nullable(),
            'personal_identity_number' => fn (Blueprint $table) => $table->string('personal_identity_number')->nullable(),
            'personal_address' => fn (Blueprint $table) => $table->string('personal_address')->nullable(),
            'birthday' => fn (Blueprint $table) => $table->date('birthday')->nullable(),
        ];

        foreach ($userColumns as $column => $definition) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $definition($table));
            }
        }

        if (! Schema::hasTable('client_user_verifications')) {
            Schema::create('client_user_verifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('full_name');
                $table->string('id_number');
                $table->date('birth_date');
                $table->text('address');
                $table->string('id_front_image', 2048);
                $table->string('id_back_image', 2048);
                $table->string('id_selfie_image', 2048);
                $table->string('status')->default(ClientUserVerification::STATUS_PENDING);
                $table->text('rejection_reason')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function makeUser(array $overrides = []): User
    {
        static $seq = 1;

        return User::query()->create(array_merge([
            'name' => 'user-' . $seq,
            'email' => 'user-' . $seq . '@example.com',
            'phone' => '0900000' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'role' => 'user',
            'password' => 'password',
        ], $overrides, [
            'id' => $overrides['id'] ?? $seq++,
        ]));
    }

    private function makeVerification(int $userId, array $overrides = []): ClientUserVerification
    {
        return ClientUserVerification::query()->create(array_merge([
            'user_id' => $userId,
            'full_name' => 'Tên xác thực',
            'id_number' => '012345678901',
            'birth_date' => '1995-05-10',
            'address' => 'Địa chỉ xác thực',
            'id_front_image' => 'https://cdn.example.com/front.jpg',
            'id_back_image' => 'https://cdn.example.com/back.jpg',
            'id_selfie_image' => 'https://cdn.example.com/selfie.jpg',
            'status' => ClientUserVerification::STATUS_APPROVED,
            'submitted_at' => now(),
        ], $overrides));
    }
}
