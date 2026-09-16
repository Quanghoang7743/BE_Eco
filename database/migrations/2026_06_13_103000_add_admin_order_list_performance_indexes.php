<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('orders', ['status', 'created_at'], 'idx_orders_status_created_at');
        $this->addIndex('orders', ['status_payment', 'created_at'], 'idx_orders_payment_created_at');
        $this->addIndex('orders', ['store_id', 'status', 'created_at'], 'idx_orders_store_status_created_at');
        $this->addIndex('tickets', ['source_id'], 'idx_tickets_source_id');
        $this->addIndex('tickets', ['used_in_order_id'], 'idx_tickets_used_in_order_id');
        $this->addIndex('refunds', ['source_id'], 'idx_refunds_source_id');
    }

    public function down(): void
    {
        $this->dropIndex('orders', 'idx_orders_status_created_at');
        $this->dropIndex('orders', 'idx_orders_payment_created_at');
        $this->dropIndex('orders', 'idx_orders_store_status_created_at');
        $this->dropIndex('tickets', 'idx_tickets_source_id');
        $this->dropIndex('tickets', 'idx_tickets_used_in_order_id');
        $this->dropIndex('refunds', 'idx_refunds_source_id');
    }

    private function addIndex(string $table, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table) || $this->hasIndex($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndex(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
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
