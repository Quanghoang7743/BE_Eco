<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('vouchers', 'store_id')) {
                $table->uuid('store_id')->nullable()->after('used');
                $table->index('store_id');
                $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (Schema::hasColumn('vouchers', 'store_id')) {
                $table->dropForeign(['store_id']);
                $table->dropIndex(['store_id']);
                $table->dropColumn('store_id');
            }
        });
    }
};
