<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'timeout_at') && !$this->hasIndex('orders', 'idx_orders_timeout_at')) {
                $table->index('timeout_at', 'idx_orders_timeout_at');
            }

            if (Schema::hasColumn('orders', 'status')
                && Schema::hasColumn('orders', 'sla_warning_sent')
                && Schema::hasColumn('orders', 'timeout_at')
                && !$this->hasIndex('orders', 'idx_orders_sla_scan')) {
                $table->index(['status', 'sla_warning_sent', 'timeout_at'], 'idx_orders_sla_scan');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if ($this->hasIndex('orders', 'idx_orders_timeout_at')) {
                $table->dropIndex('idx_orders_timeout_at');
            }

            if ($this->hasIndex('orders', 'idx_orders_sla_scan')) {
                $table->dropIndex('idx_orders_sla_scan');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(1) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName]
        );

        return (int) ($result->total ?? 0) > 0;
    }
};
