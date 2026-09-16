<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_ticket_transactions') || ! Schema::hasColumn('store_ticket_transactions', 'tickets_consumed')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `store_ticket_transactions` MODIFY `tickets_consumed` INT NOT NULL DEFAULT 0');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "store_ticket_transactions" ALTER COLUMN "tickets_consumed" SET DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('store_ticket_transactions') || ! Schema::hasColumn('store_ticket_transactions', 'tickets_consumed')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `store_ticket_transactions` MODIFY `tickets_consumed` INT NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "store_ticket_transactions" ALTER COLUMN "tickets_consumed" DROP DEFAULT');
        }
    }
};
