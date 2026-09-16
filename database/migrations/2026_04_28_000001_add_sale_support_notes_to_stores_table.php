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
            if (!Schema::hasColumn('stores', 'sale_support_notes')) {
                $table->text('sale_support_notes')->nullable()->after('sale_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'sale_support_notes')) {
                $table->dropColumn('sale_support_notes');
            }
        });
    }
};
