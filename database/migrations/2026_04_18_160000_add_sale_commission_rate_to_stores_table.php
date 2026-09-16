<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'sale_commission_rate')) {
                $table->decimal('sale_commission_rate', 5, 2)->default(0)->after('sale_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'sale_commission_rate')) {
                $table->dropColumn('sale_commission_rate');
            }
        });
    }
};
