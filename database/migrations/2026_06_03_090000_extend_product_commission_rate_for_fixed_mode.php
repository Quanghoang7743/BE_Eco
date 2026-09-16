<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->widenCommissionRate('products');
        $this->widenCommissionRate('product_variants');

        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'commission_rate_mode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('commission_rate_mode', 20)->default('percent')->after('commission_rate');
            });
        }

        if (Schema::hasTable('product_variants') && !Schema::hasColumn('product_variants', 'commission_rate_mode')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->string('commission_rate_mode', 20)->default('percent')->after('commission_rate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'commission_rate_mode')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn('commission_rate_mode');
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'commission_rate_mode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('commission_rate_mode');
            });
        }

        $this->shrinkCommissionRate('product_variants');
        $this->shrinkCommissionRate('products');
    }

    private function widenCommissionRate(string $table): void
    {
        if (
            DB::getDriverName() !== 'mysql'
            || !Schema::hasTable($table)
            || !Schema::hasColumn($table, 'commission_rate')
        ) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `commission_rate` DECIMAL(15, 2) NOT NULL DEFAULT 0");
    }

    private function shrinkCommissionRate(string $table): void
    {
        if (
            DB::getDriverName() !== 'mysql'
            || !Schema::hasTable($table)
            || !Schema::hasColumn($table, 'commission_rate')
        ) {
            return;
        }

        DB::table($table)
            ->where('commission_rate', '>', 999.99)
            ->update(['commission_rate' => 999.99]);

        DB::statement("ALTER TABLE `{$table}` MODIFY `commission_rate` DECIMAL(5, 2) NOT NULL DEFAULT 0");
    }
};
