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
        if (Schema::hasTable('order_returns') && ! Schema::hasColumn('order_returns', 'refund_bank_info')) {
            Schema::table('order_returns', function (Blueprint $table) {
                $table->json('refund_bank_info')->nullable()->after('refund_method');
            });
        }

        if (Schema::hasTable('complaints') && ! Schema::hasColumn('complaints', 'refund_bank_info')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->json('refund_bank_info')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_returns') && Schema::hasColumn('order_returns', 'refund_bank_info')) {
            Schema::table('order_returns', function (Blueprint $table) {
                $table->dropColumn('refund_bank_info');
            });
        }

        if (Schema::hasTable('complaints') && Schema::hasColumn('complaints', 'refund_bank_info')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->dropColumn('refund_bank_info');
            });
        }
    }
};
