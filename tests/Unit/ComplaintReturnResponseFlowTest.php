<?php

namespace Tests\Unit;

use App\Events\ComplaintStatusUpdated;
use App\Models\Complaint;
use App\Models\OrderReturn;
use App\Models\User;
use App\Services\ComplaintResolutionService;
use App\Services\ComplaintStoreLockService;
use App\Services\ComplaintTicketLockService;
use App\Services\OrderReturnService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ComplaintReturnResponseFlowTest extends LaravelTestCase
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

    protected function tearDown(): void
    {
        foreach ([
            'specialist_notifications',
            'complaint_action_logs',
            'complaints',
            'order_return_items',
            'order_returns',
            'order_items',
            'orders',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_store_rejection_moves_complaint_back_to_processing_and_notifies_assignee(): void
    {
        Event::fake([ComplaintStatusUpdated::class]);

        $actor = $this->makeUser('store_admin');
        $assignee = $this->makeUser('sale');
        $complaint = $this->makeComplaint([
            'assigned_to' => $assignee->id,
            'status' => 'awaiting_store_action',
            'resolution_type' => 'return_requested',
        ]);
        $return = $this->makeReturn($complaint, OrderReturn::STATUS_REJECTED);

        $this->resolutionService()->handleStoreReturnResponse($return, $actor, 'rejected', 'Need clearer evidence');

        $this->assertDatabaseHas('complaints', [
            'id' => (string) $complaint->id,
            'status' => 'processing',
            'resolution_type' => 'store_rejected_return',
        ]);
        $this->assertDatabaseHas('complaint_action_logs', [
            'complaint_id' => (string) $complaint->id,
            'actor_id' => (string) $actor->id,
            'action' => 'store_rejected_return',
            'from_status' => 'awaiting_store_action',
            'to_status' => 'processing',
        ]);
        $this->assertDatabaseHas('specialist_notifications', [
            'specialist_id' => $assignee->id,
            'type' => 'complaint_return_update',
        ]);

        Event::assertDispatched(
            ComplaintStatusUpdated::class,
            fn (ComplaintStatusUpdated $event): bool => $event->complaintId === (string) $complaint->id
                && ($event->payload['status'] ?? null) === 'processing'
                && ($event->payload['resolution_type'] ?? null) === 'store_rejected_return'
        );
    }

    public function test_store_approval_moves_complaint_back_to_processing(): void
    {
        Event::fake([ComplaintStatusUpdated::class]);

        $actor = $this->makeUser('store_admin');
        $complaint = $this->makeComplaint([
            'status' => 'awaiting_store_action',
            'resolution_type' => 'return_requested',
        ]);
        $return = $this->makeReturn($complaint, OrderReturn::STATUS_APPROVED);

        $this->resolutionService()->handleStoreReturnResponse($return, $actor, 'approved');

        $this->assertDatabaseHas('complaints', [
            'id' => (string) $complaint->id,
            'status' => 'processing',
            'resolution_type' => 'store_approved_return',
        ]);

        Event::assertDispatched(
            ComplaintStatusUpdated::class,
            fn (ComplaintStatusUpdated $event): bool => $event->complaintId === (string) $complaint->id
                && ($event->payload['status'] ?? null) === 'processing'
                && ($event->payload['resolution_type'] ?? null) === 'store_approved_return'
        );
    }

    public function test_forced_refund_can_continue_after_store_rejects_complaint_return(): void
    {
        $admin = $this->makeUser('super_admin');
        $complaint = $this->makeComplaint([
            'status' => 'processing',
            'resolution_type' => 'store_rejected_return',
        ]);
        $this->makeReturn($complaint, OrderReturn::STATUS_REJECTED);
        $orderReturnService = new ComplaintReturnResponseOrderReturnServiceFake();
        $service = $this->resolutionService($orderReturnService);

        $return = $service->forceRefund($complaint, $admin, 'Force refund after store rejection.');

        $this->assertSame(OrderReturn::STATUS_REFUNDED, $return->status);
        $this->assertTrue($orderReturnService->processRefundWasCalledWithComplaintOverride);
        $this->assertDatabaseHas('complaints', [
            'id' => (string) $complaint->id,
            'status' => 'closed',
            'resolution_type' => 'forced_refund',
            'resolved_by' => (string) $admin->id,
        ]);
    }

    private function resolutionService(?OrderReturnService $orderReturnService = null): ComplaintResolutionService
    {
        return new ComplaintResolutionService(
            $orderReturnService ?? new ComplaintReturnResponseOrderReturnServiceFake(),
            new ComplaintTicketLockService(),
            new ComplaintStoreLockService(),
        );
    }

    private function makeUser(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role),
            'email' => uniqid($role) . '@example.test',
            'status' => 'active',
            'role' => $role,
        ]);
    }

    private function makeComplaint(array $overrides = []): Complaint
    {
        return Complaint::query()->create(array_merge([
            'id' => 'complaint-' . uniqid(),
            'complaint_code' => 'CMP-' . uniqid(),
            'user_id' => 1,
            'store_id' => 'store-test',
            'order_id' => 'order-test',
            'complaint_type' => 'quality_issue',
            'title' => 'Complaint test',
            'description' => 'Complaint full order test.',
            'status' => 'processing',
            'resolution_type' => null,
        ], $overrides));
    }

    private function makeReturn(Complaint $complaint, string $status): OrderReturn
    {
        return OrderReturn::query()->create([
            'id' => 'return-' . uniqid(),
            'complaint_id' => (string) $complaint->id,
            'return_code' => 'RET-' . uniqid(),
            'order_id' => (string) $complaint->order_id,
            'user_id' => (string) $complaint->user_id,
            'store_id' => (string) $complaint->store_id,
            'status' => $status,
            'return_type' => 'refund',
            'refund_method' => 'money_wallet',
            'reason' => 'Complaint resolution',
            'refund_amount_final' => 0,
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
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_code')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id')->index();
            $table->string('product_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();
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
            $table->text('reason')->nullable();
            $table->decimal('refund_amount_final', 15, 2)->default(0);
            $table->timestamp('refund_processed_at')->nullable();
            $table->string('refund_processed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_return_id')->index();
            $table->string('order_item_id')->index();
            $table->integer('quantity')->default(1);
            $table->timestamps();
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

        Schema::create('specialist_notifications', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('specialist_id')->index();
            $table->string('type', 80)->index();
            $table->string('title', 191);
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('event_key', 191)->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['specialist_id', 'event_key']);
        });
    }
}

class ComplaintReturnResponseOrderReturnServiceFake extends OrderReturnService
{
    public bool $processRefundWasCalledWithComplaintOverride = false;

    public function __construct()
    {
    }

    public function createComplaintReturn(
        Complaint $complaint,
        User $actor,
        string $status = OrderReturn::STATUS_PENDING
    ): OrderReturn {
        return OrderReturn::query()
            ->where('complaint_id', (string) $complaint->id)
            ->firstOrFail();
    }

    public function processRefund(OrderReturn $return, mixed $actor = null, bool $allowComplaintOverride = false): OrderReturn
    {
        $this->processRefundWasCalledWithComplaintOverride = $allowComplaintOverride;

        if (! in_array($return->status, [OrderReturn::STATUS_REFUND_PROCESSING, OrderReturn::STATUS_REJECTED], true)) {
            throw new RuntimeException('UNEXPECTED_RETURN_STATUS');
        }

        $return->forceFill([
            'status' => OrderReturn::STATUS_REFUNDED,
            'refund_amount_final' => 100000,
            'refund_processed_at' => now(),
            'refund_processed_by' => $actor instanceof User ? (string) $actor->id : null,
        ])->save();

        return $return->fresh();
    }
}
