<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'view_count')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->unsignedBigInteger('view_count')->default(0)->after('stock');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'view_count')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('view_count');
            });
        }
    }
};
