<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('financial_reconciliations')) {
            return;
        }

        Schema::table('financial_reconciliations', function (Blueprint $table) {
            // Drop the old unique constraint
            $table->dropUnique('uniq_store_period_date');
            
            // Add the new unique constraint with status included
            $table->unique(['store_id', 'period', 'period_date', 'status'], 'uniq_store_period_date_status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('financial_reconciliations')) {
            return;
        }

        Schema::table('financial_reconciliations', function (Blueprint $table) {
            // Revert to the old unique constraint
            $table->dropUnique('uniq_store_period_date_status');
            $table->unique(['store_id', 'period', 'period_date'], 'uniq_store_period_date');
        });
    }
};
