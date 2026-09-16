<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('specialist_store_assignments')) {
            Schema::create('specialist_store_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->uuid('store_id')->index();
                $table->timestamp('assigned_from')->useCurrent()->index();
                $table->timestamp('assigned_to')->nullable()->index();
                $table->unsignedBigInteger('assigned_by')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['specialist_id', 'assigned_from', 'assigned_to'], 'idx_specialist_assignment_window');
                $table->index(['store_id', 'assigned_to'], 'idx_specialist_assignment_store_active');
            });
        }

        if (! Schema::hasTable('specialist_settlements')) {
            Schema::create('specialist_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->date('period_from')->nullable()->index();
                $table->date('period_to')->nullable()->index();
                $table->string('status', 30)->default('pending')->index();
                $table->decimal('amount', 15, 2)->default(0);
                $table->unsignedBigInteger('approved_by')->nullable()->index();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['specialist_id', 'status'], 'idx_specialist_settlement_status');
            });
        }

        if (! Schema::hasTable('specialist_ticket_earnings')) {
            Schema::create('specialist_ticket_earnings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->uuid('store_id')->nullable()->index();
                $table->uuid('ticket_id')->nullable()->index();
                $table->uuid('order_id')->nullable()->index();
                $table->unsignedBigInteger('settlement_id')->nullable()->index();
                $table->string('status', 30)->default('estimated')->index();
                $table->decimal('ticket_value_amount', 15, 2)->default(0);
                $table->decimal('commission_rate', 5, 2)->default(0);
                $table->decimal('earning_amount', 15, 2)->default(0);
                $table->timestamp('earned_at')->nullable()->index();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['specialist_id', 'store_id', 'earned_at'], 'idx_specialist_earning_scope');
                $table->index(['specialist_id', 'status', 'earned_at'], 'idx_specialist_earning_status');
            });
        }

        $this->backfillCurrentAssignments();
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_ticket_earnings');
        Schema::dropIfExists('specialist_settlements');
        Schema::dropIfExists('specialist_store_assignments');
    }

    private function backfillCurrentAssignments(): void
    {
        if (
            ! Schema::hasTable('specialist_store_assignments')
            || ! Schema::hasTable('stores')
            || ! Schema::hasColumn('stores', 'sale_id')
        ) {
            return;
        }

        $now = now();
        $stores = DB::table('stores')
            ->whereNotNull('sale_id')
            ->get(['id', 'sale_id', 'created_at']);

        foreach ($stores as $store) {
            $exists = DB::table('specialist_store_assignments')
                ->where('specialist_id', (int) $store->sale_id)
                ->where('store_id', (string) $store->id)
                ->whereNull('assigned_to')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('specialist_store_assignments')->insert([
                'specialist_id' => (int) $store->sale_id,
                'store_id' => (string) $store->id,
                'assigned_from' => $store->created_at ?: $now,
                'assigned_to' => null,
                'assigned_by' => null,
                'notes' => 'Phân công tự động từ cấu hình chuyên viên cửa hàng',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
