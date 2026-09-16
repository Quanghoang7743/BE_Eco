<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('products')
            || !Schema::hasTable('store_menu')
            || !Schema::hasColumn('products', 'status')
            || !Schema::hasColumn('store_menu', 'product_id')
            || !Schema::hasColumn('store_menu', 'store_id')
        ) {
            return;
        }

        $menuProductQuery = DB::table('store_menu')
            ->select('product_id')
            ->whereNotNull('product_id');

        if (Schema::hasColumn('store_menu', 'is_available')) {
            $menuProductQuery->where('is_available', true);
        }

        $updates = [
            'status' => 'published',
        ];

        $now = now();
        if (Schema::hasColumn('products', 'published_at')) {
            $updates['published_at'] = $now;
        }
        if (Schema::hasColumn('products', 'approved_at')) {
            $updates['approved_at'] = $now;
        }
        if (Schema::hasColumn('products', 'rejection_reason')) {
            $updates['rejection_reason'] = null;
        }
        if (Schema::hasColumn('products', 'updated_at')) {
            $updates['updated_at'] = $now;
        }

        DB::table('products')
            ->whereIn('id', $menuProductQuery)
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), ['', 'pending', 'pending_approval', 'draft'])
            ->update($updates);
    }

    public function down(): void
    {
        // No safe rollback: these rows may have been intentionally published after the backfill.
    }
};
