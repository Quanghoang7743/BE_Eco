<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('room_tickets') || ! Schema::hasColumn('room_tickets', 'id')) {
            return;
        }

        $column = DB::selectOne(
            "
            SELECT DATA_TYPE AS data_type
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'room_tickets'
              AND COLUMN_NAME = 'id'
            LIMIT 1
            "
        );

        if (! $column || ! isset($column->data_type)) {
            return;
        }

        $dataType = strtolower((string) $column->data_type);

        if (in_array($dataType, ['char', 'varchar'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `room_tickets` MODIFY `id` CHAR(36) NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep as CHAR(36). Reverting safely is not deterministic for existing data.
    }
};
