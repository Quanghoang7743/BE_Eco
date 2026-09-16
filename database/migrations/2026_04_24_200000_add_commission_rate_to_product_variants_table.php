<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_variants') && ! Schema::hasColumn('product_variants', 'commission_rate')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->decimal('commission_rate', 5, 2)->default(0)->after('sale_price');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'commission_rate')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn('commission_rate');
            });
        }
    }
};
