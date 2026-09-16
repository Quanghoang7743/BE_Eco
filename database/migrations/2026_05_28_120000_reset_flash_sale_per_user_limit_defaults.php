<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flash_sale_items') || !Schema::hasColumn('flash_sale_items', 'per_user_limit')) {
            return;
        }

        DB::table('flash_sale_items')
            ->whereNotNull('per_user_limit')
            ->whereColumn('per_user_limit', 'sale_quantity')
            ->update([
                'per_user_limit' => null,
                'updated_at' => now('Asia/Ho_Chi_Minh'),
            ]);
    }

    public function down(): void
    {
        // Intentionally left blank because the previous defaulted rows
        // cannot be distinguished from rows that were intentionally unlimited.
    }
};
