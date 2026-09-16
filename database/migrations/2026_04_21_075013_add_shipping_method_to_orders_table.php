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
        Schema::table('orders', function (Blueprint $row) {
            if (!Schema::hasColumn('orders', 'shipping_method')) {
                $row->string('shipping_method', 50)->nullable()->after('payment_method');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $row) {
            if (Schema::hasColumn('orders', 'shipping_method')) {
                $row->dropColumn('shipping_method');
            }
        });
    }
};
