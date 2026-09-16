<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop all inbound foreign keys that currently reference `orders`
            // so the table can be recreated with UUID primary key.
            $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->select('TABLE_NAME', 'CONSTRAINT_NAME')
                ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
                ->where('REFERENCED_TABLE_NAME', 'orders')
                ->whereNotNull('CONSTRAINT_NAME')
                ->get();

            foreach ($foreignKeys as $foreignKey) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $foreignKey->TABLE_NAME,
                    $foreignKey->CONSTRAINT_NAME
                ));
            }

            // Drop existing orders table if it exists (old structure)
            if (Schema::hasTable('orders')) {
                Schema::dropIfExists('orders');
            }

            // Create new orders table with correct schema
            Schema::create('orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('order_code', 50)->unique();
                $table->uuid('user_id')->nullable();
                $table->uuid('store_id')->nullable();

                $table->string('status', 50)->default('pending');
                $table->integer('total_tickets_used')->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('final_amount', 15, 2)->default(0);

                $table->text('delivery_address')->nullable();
                $table->json('delivery_location')->nullable();
                $table->string('delivery_phone', 20)->nullable();
                $table->text('delivery_note')->nullable();

                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('preparing_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('timeout_at')->nullable();

                $table->text('cancel_reason')->nullable();
                $table->uuid('cancelled_by')->nullable();

                $table->integer('sla_timeout_minutes')->default(15);
                $table->boolean('sla_warning_sent')->default(false);
                $table->boolean('zalo_notification_sent')->default(false);

                $table->timestamps();

                // Indexes
                $table->index('user_id');
                $table->index('store_id');
                $table->index('status');
                $table->index('order_code');
            });
        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
