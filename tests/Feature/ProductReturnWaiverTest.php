<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\ProductReturnEligibilityService;
use App\Services\ComplaintTicketLockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductReturnWaiverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->bootBaseSchema();
        $this->bootReturnWaiverSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'complaint_ticket_locks',
            'complaints',
            'ticket_ledger',
            'ticket_locks',
            'room_tickets',
            'rooms',
            'tickets',
            'user_balances',
            'order_items',
            'orders',
            'products',
            'personal_access_tokens',
            'users',
            'user_balances',
            'ticket_ledger',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_available_reward_ticket_exposes_return_waiver_metadata(): void
    {
        [$user, $order, $item, $ticketId] = $this->makeReturnableTicketFixture();

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->getJson('/api/tickets?status=AVAILABLE');

        $response->assertOk()
            ->assertJsonPath('0.id', $ticketId)
            ->assertJsonPath('0.source_order_item_id', (string) $item->id)
            ->assertJsonPath('0.source_order_id', (string) $order->id)
            ->assertJsonPath('0.source_product_name', 'San pham co Ticket')
            ->assertJsonPath('0.requires_return_waiver', true);
    }

    public function test_join_requires_confirmation_for_ticket_inside_return_window(): void
    {
        [$user, , , $ticketId] = $this->makeReturnableTicketFixture();
        $roomId = (string) Str::uuid();
        DB::table('rooms')->insert([
            'id' => $roomId,
            'room_code' => 'ROOM-RETURN',
            'room_date' => now()->toDateString(),
            'room_number' => 1,
            'room_name' => 'Phong test',
            'status' => 'WAITING',
            'current_votes' => 0,
            'required_votes' => 10,
            'ticket_type_code' => 'ALL_TYPES',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/draw-rooms/' . $roomId . '/join', [
                'ticketIds' => [$ticketId],
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'TICKET_RETURN_WAIVER_REQUIRED')
            ->assertJsonPath('products.0.product_name', 'San pham co Ticket');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => 'AVAILABLE',
        ]);
    }

    public function test_confirmed_join_blocks_product_and_consumes_ticket_atomically(): void
    {
        [$user, , $item, $ticketId] = $this->makeReturnableTicketFixture();
        $roomId = $this->insertRoom(2);

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/draw-rooms/' . $roomId . '/join', [
                'ticketIds' => [$ticketId],
                'acceptReturnWaiver' => true,
            ]);

        $response->assertOk()->assertJsonPath('currentTickets', 1);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => 'USED_IN_ROOM',
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => (string) $item->id,
            'return_blocked_by_ticket_id' => $ticketId,
        ]);
    }

    public function test_blocking_one_ticket_only_forfeits_its_source_product(): void
    {
        [, $order, $firstItem, $firstTicketId] = $this->makeReturnableTicketFixture();
        $secondItem = $this->makeOrderItem($order, [
            'id' => 'item-return-2',
            'product_name' => 'San pham khac',
            'item_name' => 'San pham khac',
            'ticket_reward_quantity' => 1,
        ]);
        $secondTicketId = $this->insertRewardTicket((string) $order->user_id, (string) $order->id, (string) $secondItem->id, 'RETURN-002');

        app(ProductReturnEligibilityService::class)->blockReturnsForTickets(
            Ticket::query()->where('id', $firstTicketId)->get(),
        );

        $this->assertDatabaseHas('order_items', [
            'id' => (string) $firstItem->id,
            'return_blocked_by_ticket_id' => $firstTicketId,
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => (string) $secondItem->id,
            'return_blocked_at' => null,
        ]);
        $this->assertDatabaseHas('tickets', [
            'id' => $secondTicketId,
            'status' => 'AVAILABLE',
        ]);
    }

    public function test_order_level_complaint_is_allowed_after_ticket_use_forfeits_product_return(): void
    {
        [$user, $order, , $ticketId] = $this->makeReturnableTicketFixture();
        app(ProductReturnEligibilityService::class)->blockReturnsForTickets(
            Ticket::query()->where('id', $ticketId)->get(),
        );

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/complaints', [
                'complaint_type' => 'refund_request',
                'order_code' => $order->order_code,
                'title' => 'Yeu cau hoan san pham',
                'description' => 'San pham khong con phu hop.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('scope', 'order');
    }

    public function test_ticket_at_72_hour_deadline_does_not_require_waiver(): void
    {
        [$user, $order, $item, $ticketId] = $this->makeReturnableTicketFixture();
        DB::table('orders')->where('id', $order->id)->update([
            'delivered_at' => now()->subHours(72),
        ]);
        $roomId = $this->insertRoom(2);

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/draw-rooms/' . $roomId . '/join', [
                'ticketIds' => [$ticketId],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('order_items', [
            'id' => (string) $item->id,
            'return_blocked_at' => null,
        ]);
    }

    public function test_withdrawing_ticket_does_not_restore_return_right(): void
    {
        [$user, , $item, $ticketId] = $this->makeReturnableTicketFixture();
        $roomId = $this->insertRoom(3);
        $token = $user->createToken('phpunit')->plainTextToken;

        $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/draw-rooms/' . $roomId . '/join', [
                'ticketIds' => [$ticketId],
                'acceptReturnWaiver' => true,
            ])
            ->assertOk();

        $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/draw-rooms/' . $roomId . '/withdraw', [
                'ticketCount' => 1,
            ])
            ->assertOk();

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => 'AVAILABLE',
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => (string) $item->id,
            'return_blocked_by_ticket_id' => $ticketId,
        ]);
    }

    public function test_refund_complaint_remains_order_scoped_when_one_product_return_is_blocked(): void
    {
        [$user, $order, , $ticketId] = $this->makeReturnableTicketFixture();
        $this->makeOrderItem($order, [
            'id' => 'item-return-available',
            'product_name' => 'San pham van duoc hoan',
            'item_name' => 'San pham van duoc hoan',
            'ticket_reward_quantity' => 0,
        ]);
        app(ProductReturnEligibilityService::class)->blockReturnsForTickets(
            Ticket::query()->where('id', $ticketId)->get(),
        );

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/complaints', [
                'complaint_type' => 'refund_request',
                'order_code' => $order->order_code,
                'title' => 'Yeu cau hoan san pham khac',
                'description' => 'San pham khac trong cung don can duoc hoan.',
            ]);

        $response->assertSuccessful();
    }

    public function test_non_refund_complaint_remains_order_scoped_when_product_return_is_blocked(): void
    {
        [$user, $order, , $ticketId] = $this->makeReturnableTicketFixture();
        app(ProductReturnEligibilityService::class)->blockReturnsForTickets(
            Ticket::query()->where('id', $ticketId)->get(),
        );

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/complaints', [
                'complaint_type' => 'quality_issue',
                'order_code' => $order->order_code,
                'title' => 'Bao cao chat luong san pham',
                'description' => 'Phan anh chat luong, khong phai yeu cau hoan tien.',
            ]);

        $response->assertSuccessful();
    }

    public function test_order_level_complaint_locks_all_customer_reward_tickets(): void
    {
        [$user, $order, , $firstTicketId] = $this->makeReturnableTicketFixture();
        $secondItem = $this->makeOrderItem($order, [
            'id' => 'item-order-scope-2',
            'product_name' => 'San pham thu hai',
            'item_name' => 'San pham thu hai',
            'ticket_reward_quantity' => 1,
        ]);
        $secondTicketId = $this->insertRewardTicket(
            (string) $user->id,
            (string) $order->id,
            (string) $secondItem->id,
            'ORDER-SCOPE-002'
        );

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/complaints', [
                'complaint_type' => 'quality_issue',
                'order_code' => $order->order_code,
                'title' => 'Khieu nai toan bo don hang',
                'description' => 'Tat ca san pham trong don hang deu co van de.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('scope', 'order')
            ->assertJsonPath('locked_ticket_count', 2);

        $complaintId = (string) $response->json('complaint_id');
        foreach ([$firstTicketId, $secondTicketId] as $ticketId) {
            $this->assertDatabaseHas('tickets', [
                'id' => $ticketId,
                'status' => 'LOCKED',
                'locked_by' => 'complaint:' . $complaintId,
            ]);
            $this->assertDatabaseHas('complaint_ticket_locks', [
                'complaint_id' => $complaintId,
                'ticket_id' => $ticketId,
                'released_at' => null,
            ]);
        }

        $this->assertDatabaseHas('user_balances', [
            'user_id' => (string) $user->id,
            'available_balance' => 0,
        ]);
        $this->assertSame(2, DB::table('ticket_ledger')
            ->where('reference_type', 'COMPLAINT')
            ->where('reference_id', $complaintId)
            ->where('action_type', 'LOCKED')
            ->where('amount_change', -1)
            ->count());
    }

    public function test_complaint_create_rejects_legacy_product_ids(): void
    {
        [$user, $order, $item] = $this->makeReturnableTicketFixture();

        $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/complaints', [
                'complaint_type' => 'quality_issue',
                'order_code' => $order->order_code,
                'product_ids' => [(string) $item->id],
                'title' => 'Khieu nai legacy',
                'description' => 'Client cu khong con duoc gui product_ids.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_ids']);
    }

    public function test_customer_cancel_is_idempotent_and_expires_ticket_that_elapsed_while_locked(): void
    {
        [$user, $order, , $ticketId] = $this->makeReturnableTicketFixture();
        $token = $user->createToken('phpunit')->plainTextToken;

        $created = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/complaints', [
                'complaint_type' => 'quality_issue',
                'order_code' => $order->order_code,
                'title' => 'Khieu nai can huy',
                'description' => 'Khach hang muon huy sau khi gui.',
            ])
            ->assertCreated();

        $complaintId = (string) $created->json('complaint_id');
        DB::table('tickets')->where('id', $ticketId)->update(['expired_at' => now()->subMinute()]);

        $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/complaints/' . $complaintId . '/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('idempotent', false);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => 'EXPIRED',
            'locked_by' => null,
        ]);
        $this->assertDatabaseHas('complaint_ticket_locks', [
            'complaint_id' => $complaintId,
            'ticket_id' => $ticketId,
            'release_reason' => 'customer_cancelled',
        ]);

        $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/complaints/' . $complaintId . '/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('idempotent', true);
    }

    public function test_complaint_create_requires_authentication(): void
    {
        [, $order] = $this->makeReturnableTicketFixture();

        $this->postJson('/api/complaints', [
            'complaint_type' => 'quality_issue',
            'order_code' => $order->order_code,
            'title' => 'Khong co token',
            'description' => 'Request nay phai bi tu choi.',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_refund_resolution_revokes_locked_order_reward_instead_of_releasing_it(): void
    {
        [$user, $order, , $ticketId] = $this->makeReturnableTicketFixture();
        $created = $this
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('phpunit')->plainTextToken)
            ->postJson('/api/complaints', [
                'complaint_type' => 'refund_request',
                'order_code' => $order->order_code,
                'title' => 'Hoan toan bo don',
                'description' => 'Admin chap nhan hoan tien.',
            ])
            ->assertCreated();

        $complaintId = (string) $created->json('complaint_id');
        app(ComplaintTicketLockService::class)->revokeForRefund($complaintId, $user->id);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => 'CANCELLED',
            'locked_by' => null,
        ]);
        $this->assertDatabaseHas('complaint_ticket_locks', [
            'complaint_id' => $complaintId,
            'ticket_id' => $ticketId,
            'release_reason' => 'refund_processed',
        ]);
    }

    private function makeReturnableTicketFixture(): array
    {
        $user = $this->makeUser();
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'status' => 'delivered',
            'delivered_at' => now()->subHour(),
        ]);
        $item = $this->makeOrderItem($order, [
            'id' => 'item-return-1',
            'product_name' => 'San pham co Ticket',
            'item_name' => 'San pham co Ticket',
            'ticket_reward_quantity' => 1,
        ]);
        $ticketId = $this->insertRewardTicket((string) $user->id, (string) $order->id, (string) $item->id, 'RETURN-001');

        return [$user, $order, $item, $ticketId];
    }

    private function makeUser(): \App\Models\User
    {
        return \App\Models\User::query()->create([
            'name' => 'Customer',
            'email' => 'customer-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 'active',
            'role' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(array $overrides = []): \App\Models\Order
    {
        return \App\Models\Order::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'order_code' => 'ORD-' . Str::upper(Str::random(8)),
            'user_id' => null,
            'store_id' => (string) Str::uuid(),
            'status' => 'delivered',
            'total_amount' => 100000,
            'final_amount' => 100000,
            'discount_amount' => 0,
            'delivered_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makeOrderItem(\App\Models\Order $order, array $overrides = []): \App\Models\OrderItem
    {
        return \App\Models\OrderItem::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'order_id' => (string) $order->id,
            'product_id' => null,
            'variant_id' => null,
            'product_name' => 'San pham test',
            'item_name' => 'San pham test',
            'quantity' => 1,
            'unit_price' => 100000,
            'price' => 100000,
            'total_price' => 100000,
            'ticket_reward_quantity' => 0,
        ], $overrides));
    }

    private function insertRewardTicket(string $userId, string $orderId, string $orderItemId, string $code): string
    {
        $ticketId = (string) Str::uuid();
        DB::table('tickets')->insert([
            'id' => $ticketId,
            'ticket_code' => $code,
            'user_id' => $userId,
            'status' => 'AVAILABLE',
            'ticket_type_code' => 'TICKET_A',
            'source_type' => 'ORDER_REWARD',
            'source_id' => $orderId,
            'source_order_item_id' => $orderItemId,
            'purchased_at' => now(),
            'expired_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $balance = DB::table('user_balances')->where('user_id', $userId)->first();
        if ($balance) {
            DB::table('user_balances')
                ->where('user_id', $userId)
                ->update([
                    'available_balance' => ((int) $balance->available_balance) + 1,
                    'total_lifetime_purchased' => ((int) $balance->total_lifetime_purchased) + 1,
                    'last_updated' => now(),
                    'version' => ((int) $balance->version) + 1,
                ]);
        } else {
            DB::table('user_balances')->insert([
                'user_id' => $userId,
                'available_balance' => 1,
                'locked_balance' => 0,
                'used_balance' => 0,
                'expired_balance' => 0,
                'total_lifetime_purchased' => 1,
                'total_lifetime_used' => 0,
                'last_updated' => now(),
                'version' => 0,
            ]);
        }

        return $ticketId;
    }

    private function insertRoom(int $requiredVotes): string
    {
        $roomId = (string) Str::uuid();
        DB::table('rooms')->insert([
            'id' => $roomId,
            'room_code' => 'ROOM-' . substr($roomId, 0, 8),
            'room_date' => now()->toDateString(),
            'room_number' => random_int(10, 9999),
            'room_name' => 'Phong test',
            'status' => 'WAITING',
            'current_votes' => 0,
            'required_votes' => $requiredVotes,
            'ticket_type_code' => 'ALL_TYPES',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roomId;
    }

    private function bootReturnWaiverSchema(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'ticket_reward_quantity')) {
                $table->unsignedInteger('ticket_reward_quantity')->default(0);
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'ticket_reward_quantity')) {
                $table->unsignedInteger('ticket_reward_quantity')->default(0);
            }
            if (!Schema::hasColumn('order_items', 'return_blocked_at')) {
                $table->timestamp('return_blocked_at')->nullable();
            }
            if (!Schema::hasColumn('order_items', 'return_blocked_by_ticket_id')) {
                $table->string('return_blocked_by_ticket_id', 36)->nullable();
            }
        });

        if (!Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('ticket_code')->unique();
                $table->string('user_id')->nullable();
                $table->string('status')->default('AVAILABLE');
                $table->string('ticket_type_code')->nullable();
                $table->string('source_type')->nullable();
                $table->string('source_id')->nullable();
                $table->string('source_order_item_id')->nullable();
                $table->timestamp('purchased_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->string('locked_by')->nullable();
                $table->timestamp('lock_expires_at')->nullable();
                $table->string('used_in_room_id')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ticket_ledger')) {
            Schema::create('ticket_ledger', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('ticket_id', 36)->nullable()->index();
                $table->string('user_id')->nullable()->index();
                $table->string('action_type', 50)->index();
                $table->integer('amount_change');
                $table->integer('balance_before')->nullable();
                $table->integer('balance_after')->nullable();
                $table->string('reference_id', 36)->nullable();
                $table->string('reference_type', 50)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('room_code');
                $table->date('room_date');
                $table->unsignedInteger('room_number');
                $table->string('room_name')->nullable();
                $table->string('status')->default('WAITING');
                $table->unsignedInteger('current_votes')->default(0);
                $table->unsignedInteger('required_votes')->default(1000);
                $table->string('ticket_type_code')->nullable();
                $table->timestamp('open_at')->nullable();
                $table->timestamp('close_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('room_tickets')) {
            Schema::create('room_tickets', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('room_id', 36)->nullable();
                $table->string('ticket_id', 36)->nullable();
                $table->string('user_id')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->boolean('is_winner')->default(false);
                $table->boolean('is_refunded')->default(false);
            });
        }

        if (!Schema::hasTable('ticket_locks')) {
            Schema::create('ticket_locks', function (Blueprint $table): void {
                $table->string('lock_key')->primary();
                $table->string('lock_value')->nullable();
                $table->string('owner')->nullable();
                $table->integer('ttl_seconds')->default(300);
                $table->timestamp('acquired_at')->nullable();
                $table->timestamp('expires_at')->nullable();
            });
        }

        if (!Schema::hasTable('complaints')) {
            Schema::create('complaints', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('complaint_code')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('store_id')->nullable();
                $table->string('order_id')->nullable();
                $table->string('complaint_type')->nullable();
                $table->string('severity')->nullable();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('status')->nullable();
                $table->string('resolution_type')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('complaint_ticket_locks')) {
            Schema::create('complaint_ticket_locks', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('complaint_id', 36);
                $table->string('ticket_id', 36);
                $table->string('locked_from_status')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('release_reason')->nullable();
            });
        }

        if (!Schema::hasTable('user_balances')) {
            Schema::create('user_balances', function (Blueprint $table): void {
                $table->string('user_id', 100)->primary();
                $table->integer('available_balance')->default(0);
                $table->integer('locked_balance')->default(0);
                $table->integer('used_balance')->default(0);
                $table->integer('expired_balance')->default(0);
                $table->integer('total_lifetime_purchased')->default(0);
                $table->integer('total_lifetime_used')->default(0);
                $table->timestamp('last_updated')->nullable();
                $table->unsignedInteger('version')->default(0);
            });
        }

        if (!Schema::hasTable('ticket_ledger')) {
            Schema::create('ticket_ledger', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('ticket_id', 36)->nullable();
                $table->string('user_id', 100)->nullable();
                $table->string('action_type', 50);
                $table->integer('amount_change')->default(0);
                $table->integer('balance_before')->default(0);
                $table->integer('balance_after')->default(0);
                $table->string('reference_id', 100)->nullable();
                $table->string('reference_type', 100)->nullable();
                $table->text('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    private function bootBaseSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->string('role')->default('user');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_balances', function (Blueprint $table): void {
            $table->string('user_id')->primary();
            $table->integer('available_balance')->default(0);
            $table->integer('locked_balance')->default(0);
            $table->integer('used_balance')->default(0);
            $table->integer('expired_balance')->default(0);
            $table->integer('total_lifetime_purchased')->default(0);
            $table->integer('total_lifetime_used')->default(0);
            $table->timestamp('last_updated')->nullable();
            $table->unsignedInteger('version')->default(0);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('ticket_reward_quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_code')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('store_id')->nullable();
            $table->string('status')->default('delivered');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->timestamp('delivered_at')->nullable();
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
            $table->unsignedInteger('ticket_reward_quantity')->default(0);
            $table->timestamp('return_blocked_at')->nullable();
            $table->string('return_blocked_by_ticket_id', 36)->nullable();
        });
    }
}
