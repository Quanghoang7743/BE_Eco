<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Initialize timeout_at for existing pending orders that don't have it
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'timeout_at')) {
            DB::table('orders')
                ->where('status', 'pending')
                ->whereNull('timeout_at')
                ->update([
                    'timeout_at' => DB::raw('DATE_ADD(created_at, INTERVAL COALESCE(sla_timeout_minutes, 15) MINUTE)'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally clear timeout_at values that were just initialized
        // Not recommended in production - removed for safety
    }
};
