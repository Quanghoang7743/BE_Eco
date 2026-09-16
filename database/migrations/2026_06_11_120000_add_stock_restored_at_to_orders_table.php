<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'stock_restored_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('stock_restored_at')->nullable()->after('cancelled_at')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'stock_restored_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('stock_restored_at');
        });
    }
};
