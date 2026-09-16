<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rooms MODIFY COLUMN winner_ticket_id CHAR(36) NULL");
        DB::statement("ALTER TABLE draws MODIFY COLUMN winner_ticket_id CHAR(36) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE rooms MODIFY COLUMN winner_ticket_id BIGINT UNSIGNED NULL");
        DB::statement("ALTER TABLE draws MODIFY COLUMN winner_ticket_id BIGINT UNSIGNED NULL");
    }
};
