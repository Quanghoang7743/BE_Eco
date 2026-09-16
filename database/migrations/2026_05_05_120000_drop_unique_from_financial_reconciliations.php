<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('financial_reconciliations')) {
            return;
        }

        $exists = (bool) optional(DB::selectOne("SELECT COUNT(1) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'financial_reconciliations' AND index_name = 'uniq_store_period_date_status'"))->cnt;

        if ($exists) {
            Schema::table('financial_reconciliations', function (Blueprint $table) {
                $table->dropUnique('uniq_store_period_date_status');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('financial_reconciliations')) {
            return;
        }

        $exists = (bool) optional(DB::selectOne("SELECT COUNT(1) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'financial_reconciliations' AND index_name = 'uniq_store_period_date_status'"))->cnt;

        if (! $exists) {
            Schema::table('financial_reconciliations', function (Blueprint $table) {
                $table->unique(['store_id', 'period', 'period_date', 'status'], 'uniq_store_period_date_status');
            });
        }
    }
};