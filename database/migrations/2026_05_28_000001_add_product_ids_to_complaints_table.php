<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('complaints') || Schema::hasColumn('complaints', 'product_ids')) {
            return;
        }

        Schema::table('complaints', function (Blueprint $table) {
            $table->json('product_ids')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('complaints') || !Schema::hasColumn('complaints', 'product_ids')) {
            return;
        }

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('product_ids');
        });
    }
};
