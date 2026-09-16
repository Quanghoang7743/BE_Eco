<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_settings')) {
            Schema::create('user_settings', function (Blueprint $table) {
                $table->uuid('user_id')->primary();
                $table->boolean('notif_winner')->default(true);
                $table->boolean('notif_order')->default(true);
                $table->boolean('notif_room')->default(true);
                $table->boolean('notif_ticket_expiry')->default(true);
                $table->boolean('notif_promotion')->default(false);
                $table->boolean('notif_via_zalo_oa')->default(true);
                $table->boolean('notif_via_zalo_miniapp')->default(true);
                $table->boolean('gps_enabled')->default(false);
                $table->boolean('share_phone')->default(false);
                $table->string('language', 10)->default('vi');
                $table->string('theme', 20)->default('light');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_balances')) {
            Schema::create('user_balances', function (Blueprint $table) {
                $table->uuid('user_id')->primary();
                $table->integer('available_balance')->default(0);
                $table->integer('locked_balance')->default(0);
                $table->integer('used_balance')->default(0);
                $table->integer('expired_balance')->default(0);
                $table->integer('total_lifetime_purchased')->default(0);
                $table->integer('total_lifetime_used')->default(0);
                $table->timestamp('last_updated')->useCurrent();
                $table->integer('version')->default(0);
            });
        }

        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('ticket_code', 50)->unique();
                $table->uuid('user_id')->nullable();
                $table->string('status', 50)->default('AVAILABLE');
                $table->decimal('value_amount', 15, 2)->default(5000);
                $table->decimal('purchased_price', 15, 2)->nullable();
                $table->timestamp('purchased_at')->useCurrent();
                $table->timestamp('expired_at')->nullable();
                $table->string('source_type', 50)->nullable();
                $table->uuid('source_id')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->string('locked_by', 100)->nullable();
                $table->timestamp('lock_expires_at')->nullable();
                $table->uuid('used_in_room_id')->nullable();
                $table->uuid('used_in_order_id')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->uuid('batch_id')->nullable();
                $table->text('qr_code')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index('status');
                $table->index('ticket_code');
                $table->index('expired_at');
                $table->index(['user_id', 'status']);
                $table->index('locked_by');
            });
        }

        if (! Schema::hasTable('ticket_ledger')) {
            Schema::create('ticket_ledger', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->nullable();
                $table->uuid('user_id')->nullable();
                $table->string('action_type', 50);
                $table->integer('amount_change');
                $table->integer('balance_before')->nullable();
                $table->integer('balance_after')->nullable();
                $table->uuid('reference_id')->nullable();
                $table->string('reference_type', 50)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('ticket_id');
                $table->index('user_id');
                $table->index('action_type');
                $table->index('created_at');
                $table->index(['user_id', 'action_type', 'created_at']);
            });
        }

        if (! Schema::hasTable('ticket_locks')) {
            Schema::create('ticket_locks', function (Blueprint $table) {
                $table->string('lock_key', 255)->primary();
                $table->string('lock_value', 255)->nullable();
                $table->string('owner', 255)->nullable();
                $table->integer('ttl_seconds')->default(300);
                $table->timestamp('acquired_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();

                $table->index('expires_at');
            });
        }

        if (! Schema::hasTable('ticket_batches')) {
            Schema::create('ticket_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('batch_code', 50)->unique();
                $table->uuid('user_id')->nullable();
                $table->integer('quantity');
                $table->decimal('total_amount', 15, 2)->nullable();
                $table->string('status', 50)->default('completed');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
            });
        }

        if (! Schema::hasTable('draw_logs')) {
            Schema::create('draw_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('draw_id')->nullable();
                $table->string('log_level', 20)->nullable();
                $table->string('log_type', 50)->nullable();
                $table->text('message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('draw_id');
                $table->index('log_level');
            });
        }

        if (! Schema::hasTable('draw_blockchain')) {
            Schema::create('draw_blockchain', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('draw_id')->nullable();
                $table->uuid('room_id')->nullable();
                $table->string('blockchain_network', 50)->nullable();
                $table->string('contract_address', 255)->nullable();
                $table->string('draw_hash', 255)->nullable();
                $table->string('transaction_hash', 255)->nullable();
                $table->text('block_explorer_url')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('store_code', 50)->unique();
                $table->uuid('owner_id')->nullable();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->text('address');
                $table->json('location')->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('email', 255)->nullable();
                $table->text('logo_url')->nullable();
                $table->text('cover_image')->nullable();
                $table->string('license_number', 100)->nullable();
                $table->text('license_image')->nullable();
                $table->string('status', 50)->default('pending');
                $table->boolean('is_open')->default(true);
                $table->time('open_time')->default('09:00:00');
                $table->time('close_time')->default('21:00:00');
                $table->decimal('commission_rate', 5, 2)->default(10);
                $table->decimal('rating_avg', 3, 2)->default(0);
                $table->integer('rating_count')->default(0);
                $table->integer('min_order_tickets')->default(1);
                $table->integer('max_order_tickets')->default(100);
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('status');
                $table->index('owner_id');
                $table->index('store_code');
            });
        }

        if (! Schema::hasTable('store_menu')) {
            Schema::create('store_menu', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('store_id')->nullable();
                $table->string('item_code', 50)->nullable();
                $table->string('item_name', 255);
                $table->text('description')->nullable();
                $table->decimal('price', 15, 2);
                $table->decimal('original_price', 15, 2)->nullable();
                $table->boolean('is_lucky_item')->default(true);
                $table->string('category', 100)->nullable();
                $table->string('sub_category', 100)->nullable();
                $table->text('image_url')->nullable();
                $table->boolean('is_available')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index('store_id');
                $table->index(['store_id', 'is_available']);
                $table->index(['store_id', 'is_lucky_item']);
            });
        }

        if (! Schema::hasTable('store_reviews')) {
            Schema::create('store_reviews', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('store_id')->nullable();
                $table->uuid('user_id')->nullable();
                $table->uuid('order_id')->nullable();
                $table->integer('rating')->nullable();
                $table->text('comment')->nullable();
                $table->json('images')->nullable();
                $table->uuid('replied_by')->nullable();
                $table->text('reply_text')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->boolean('is_hidden')->default(false);
                $table->text('hidden_reason')->nullable();
                $table->timestamps();

                $table->index('store_id');
                $table->index('user_id');
                $table->index('rating');
            });
        }

        if (! Schema::hasTable('store_hours')) {
            Schema::create('store_hours', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('store_id')->nullable();
                $table->integer('day_of_week')->nullable();
                $table->time('open_time')->nullable();
                $table->time('close_time')->nullable();
                $table->boolean('is_closed')->default(false);
                $table->timestamps();

                $table->unique(['store_id', 'day_of_week']);
            });
        }

        if (! Schema::hasTable('store_service_areas')) {
            Schema::create('store_service_areas', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('store_id')->nullable();
                $table->string('area_name', 255)->nullable();
                $table->text('area_polygon')->nullable();
                $table->decimal('delivery_fee', 15, 2)->default(0);
                $table->decimal('min_order_amount', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->useCurrent();

                $table->index('store_id');
            });
        }

        if (! Schema::hasTable('order_tickets')) {
            Schema::create('order_tickets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->nullable();
                $table->uuid('ticket_id')->nullable();
                $table->uuid('user_id')->nullable();
                $table->timestamp('used_at')->useCurrent();

                $table->index('order_id');
                $table->index('ticket_id');
                $table->unique(['order_id', 'ticket_id']);
            });
        }

        if (! Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->nullable();
                $table->string('old_status', 50)->nullable();
                $table->string('new_status', 50)->nullable();
                $table->uuid('changed_by')->nullable();
                $table->string('changed_by_role', 50)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('order_id');
            });
        }

        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('refund_code', 50)->unique();
                $table->uuid('user_id')->nullable();
                $table->uuid('ticket_id')->nullable();
                $table->string('refund_type', 50)->nullable();
                $table->text('reason')->nullable();
                $table->string('reason_code', 50)->nullable();
                $table->string('old_status', 50)->nullable();
                $table->string('new_status', 50)->default('REFUNDED');
                $table->string('source_type', 50)->nullable();
                $table->uuid('source_id')->nullable();
                $table->uuid('processed_by')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('user_id');
                $table->index('ticket_id');
                $table->index('refund_code');
            });
        }

        if (! Schema::hasTable('refund_logs')) {
            Schema::create('refund_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('refund_id')->nullable();
                $table->string('action', 50)->nullable();
                $table->text('details')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('complaints')) {
            Schema::create('complaints', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('complaint_code', 50)->unique();
                $table->uuid('user_id')->nullable();
                $table->uuid('store_id')->nullable();
                $table->uuid('order_id')->nullable();
                $table->string('complaint_type', 50)->nullable();
                $table->string('severity', 20)->default('normal');
                $table->string('title', 255)->nullable();
                $table->text('description')->nullable();
                $table->json('images')->nullable();
                $table->string('status', 50)->default('pending');
                $table->uuid('assigned_to')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->text('resolution_note')->nullable();
                $table->boolean('refund_requested')->default(false);
                $table->uuid('refund_id')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index('store_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('complaint_messages')) {
            Schema::create('complaint_messages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('complaint_id')->nullable();
                $table->uuid('sender_id')->nullable();
                $table->string('sender_type', 50)->nullable();
                $table->text('message')->nullable();
                $table->json('attachments')->nullable();
                $table->boolean('is_from_admin')->default(false);
                $table->boolean('is_read')->default(false);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (Schema::hasTable('ticket_ledger') && Schema::hasTable('tickets')) {
            Schema::table('ticket_ledger', function (Blueprint $table) {
                $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
            });
        }

        if (Schema::hasTable('draw_logs') && Schema::hasTable('draws')) {
            Schema::table('draw_logs', function (Blueprint $table) {
                $table->foreign('draw_id')->references('id')->on('draws')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('draw_blockchain') && Schema::hasTable('draws')) {
            Schema::table('draw_blockchain', function (Blueprint $table) {
                $table->foreign('draw_id')->references('id')->on('draws')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('draw_blockchain') && Schema::hasTable('rooms')) {
            Schema::table('draw_blockchain', function (Blueprint $table) {
                $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('store_menu') && Schema::hasTable('stores')) {
            Schema::table('store_menu', function (Blueprint $table) {
                $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('store_hours') && Schema::hasTable('stores')) {
            Schema::table('store_hours', function (Blueprint $table) {
                $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('store_service_areas') && Schema::hasTable('stores')) {
            Schema::table('store_service_areas', function (Blueprint $table) {
                $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('order_tickets') && Schema::hasTable('tickets')) {
            Schema::table('order_tickets', function (Blueprint $table) {
                $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('refund_logs') && Schema::hasTable('refunds')) {
            Schema::table('refund_logs', function (Blueprint $table) {
                $table->foreign('refund_id')->references('id')->on('refunds')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('complaints') && Schema::hasTable('stores')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            });
        }

        if (Schema::hasTable('complaints') && Schema::hasTable('refunds')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->foreign('refund_id')->references('id')->on('refunds')->nullOnDelete();
            });
        }

        if (Schema::hasTable('complaint_messages') && Schema::hasTable('complaints')) {
            Schema::table('complaint_messages', function (Blueprint $table) {
                $table->foreign('complaint_id')->references('id')->on('complaints')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaint_messages');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('refund_logs');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_tickets');
        Schema::dropIfExists('store_service_areas');
        Schema::dropIfExists('store_hours');
        Schema::dropIfExists('store_reviews');
        Schema::dropIfExists('store_menu');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('draw_blockchain');
        Schema::dropIfExists('draw_logs');
        Schema::dropIfExists('ticket_batches');
        Schema::dropIfExists('ticket_locks');
        Schema::dropIfExists('ticket_ledger');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('user_balances');
        Schema::dropIfExists('user_settings');
    }
};
