<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_variants') || Schema::hasColumn('product_variants', 'enabled')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->boolean('enabled')->default(true)->after('stock')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_variants') || !Schema::hasColumn('product_variants', 'enabled')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('enabled');
        });
    }
};
