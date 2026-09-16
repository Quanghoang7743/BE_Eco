<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdraw_requests')) {
            return;
        }

        Schema::table('withdraw_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('withdraw_requests', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('rejection_reason');
            }

            if (! Schema::hasColumn('withdraw_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }

            if (! Schema::hasColumn('withdraw_requests', 'paid_by')) {
                $table->unsignedBigInteger('paid_by')->nullable()->after('approved_at');
            }

            if (! Schema::hasColumn('withdraw_requests', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('paid_by');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `withdraw_requests` MODIFY `status` ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('withdraw_requests')) {
            return;
        }

        DB::table('withdraw_requests')->where('status', 'paid')->update(['status' => 'approved']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `withdraw_requests` MODIFY `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('withdraw_requests', function (Blueprint $table) {
            foreach (['paid_at', 'paid_by', 'approved_at', 'approved_by'] as $column) {
                if (Schema::hasColumn('withdraw_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
