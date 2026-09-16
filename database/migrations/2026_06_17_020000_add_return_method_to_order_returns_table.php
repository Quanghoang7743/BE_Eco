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
        if (Schema::hasTable('order_returns')) {
            Schema::table('order_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('order_returns', 'return_method')) {
                    $table->string('return_method', 20)->nullable()->after('refund_method');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_returns')) {
            Schema::table('order_returns', function (Blueprint $table) {
                if (Schema::hasColumn('order_returns', 'return_method')) {
                    $table->dropColumn('return_method');
                }
            });
        }
    }
};
