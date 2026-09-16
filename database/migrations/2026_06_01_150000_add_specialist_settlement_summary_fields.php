<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialist_settlements')) {
            Schema::table('specialist_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialist_settlements', 'total_eligible_tickets')) {
                    $table->unsignedInteger('total_eligible_tickets')->default(0)->after('status');
                }

                if (! Schema::hasColumn('specialist_settlements', 'gross_income')) {
                    $table->decimal('gross_income', 15, 2)->default(0)->after('total_eligible_tickets');
                }

                if (! Schema::hasColumn('specialist_settlements', 'adjustment_amount')) {
                    $table->decimal('adjustment_amount', 15, 2)->default(0)->after('gross_income');
                }

                if (! Schema::hasColumn('specialist_settlements', 'final_income')) {
                    $table->decimal('final_income', 15, 2)->default(0)->after('adjustment_amount');
                }
            });
        }

        if (Schema::hasTable('specialist_store_assignments') && Schema::hasColumn('specialist_store_assignments', 'notes')) {
            DB::table('specialist_store_assignments')
                ->where('notes', 'Backfilled from stores.sale_id')
                ->update(['notes' => 'Phân công tự động từ cấu hình chuyên viên cửa hàng']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('specialist_settlements')) {
            return;
        }

        Schema::table('specialist_settlements', function (Blueprint $table): void {
            foreach (['final_income', 'adjustment_amount', 'gross_income', 'total_eligible_tickets'] as $column) {
                if (Schema::hasColumn('specialist_settlements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
