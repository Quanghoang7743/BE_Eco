<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_returns') || Schema::hasColumn('order_returns', 'shipping_receipt_images')) {
            return;
        }

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->json('shipping_receipt_images')->nullable()->after('carrier_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_returns') || ! Schema::hasColumn('order_returns', 'shipping_receipt_images')) {
            return;
        }

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->dropColumn('shipping_receipt_images');
        });
    }
};
