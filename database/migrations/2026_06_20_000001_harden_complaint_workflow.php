<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('complaints')) {
            Schema::table('complaints', function (Blueprint $table): void {
                if (! Schema::hasColumn('complaints', 'resolution_type')) {
                    $table->string('resolution_type', 50)->nullable()->after('resolution_note')->index();
                }
                if (! Schema::hasColumn('complaints', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('resolved_at');
                }
                if (! Schema::hasColumn('complaints', 'cancelled_by')) {
                    $table->string('cancelled_by', 64)->nullable()->after('cancelled_at')->index();
                }
                if (! Schema::hasColumn('complaints', 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable()->after('cancelled_by');
                }
            });
        }

        if (Schema::hasTable('complaint_ticket_locks') && ! Schema::hasColumn('complaint_ticket_locks', 'released_by')) {
            Schema::table('complaint_ticket_locks', function (Blueprint $table): void {
                $table->string('released_by', 64)->nullable()->after('release_reason');
            });
        }

        if (! Schema::hasTable('complaint_action_logs')) {
            Schema::create('complaint_action_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('complaint_id')->index();
                $table->string('actor_id', 64)->nullable()->index();
                $table->string('actor_role', 50)->nullable();
                $table->string('action', 80)->index();
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50)->nullable();
                $table->json('metadata')->nullable();
                $table->string('idempotency_key', 180)->nullable()->unique();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('complaint_store_locks')) {
            Schema::create('complaint_store_locks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('complaint_id')->unique();
                $table->uuid('store_id')->index();
                $table->string('locked_by', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('released_at')->nullable()->index();
                $table->string('release_reason', 80)->nullable();
                $table->string('released_by', 64)->nullable();
            });
        }

        if (Schema::hasTable('order_returns') && ! Schema::hasColumn('order_returns', 'complaint_id')) {
            Schema::table('order_returns', function (Blueprint $table): void {
                $table->uuid('complaint_id')->nullable()->after('id')->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_returns') && Schema::hasColumn('order_returns', 'complaint_id')) {
            Schema::table('order_returns', function (Blueprint $table): void {
                $table->dropUnique(['complaint_id']);
                $table->dropColumn('complaint_id');
            });
        }

        Schema::dropIfExists('complaint_store_locks');
        Schema::dropIfExists('complaint_action_logs');

        if (Schema::hasTable('complaint_ticket_locks') && Schema::hasColumn('complaint_ticket_locks', 'released_by')) {
            Schema::table('complaint_ticket_locks', function (Blueprint $table): void {
                $table->dropColumn('released_by');
            });
        }

        if (Schema::hasTable('complaints')) {
            Schema::table('complaints', function (Blueprint $table): void {
                $columns = collect(['resolution_type', 'cancelled_at', 'cancelled_by', 'cancel_reason'])
                    ->filter(fn (string $column): bool => Schema::hasColumn('complaints', $column))
                    ->all();
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
