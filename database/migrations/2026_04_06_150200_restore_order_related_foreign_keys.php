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
        if (Schema::hasTable('order_items') && Schema::hasTable('orders')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('order_tickets') && Schema::hasTable('orders')) {
            Schema::table('order_tickets', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('order_status_history') && Schema::hasTable('orders')) {
            Schema::table('order_status_history', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('store_reviews') && Schema::hasTable('orders')) {
            Schema::table('store_reviews', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }

        if (Schema::hasTable('complaints') && Schema::hasTable('orders')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('complaints')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->dropForeign(['order_id']);
            });
        }

        if (Schema::hasTable('store_reviews')) {
            Schema::table('store_reviews', function (Blueprint $table) {
                $table->dropForeign(['order_id']);
            });
        }

        if (Schema::hasTable('order_status_history')) {
            Schema::table('order_status_history', function (Blueprint $table) {
                $table->dropForeign(['order_id']);
            });
        }

        if (Schema::hasTable('order_tickets')) {
            Schema::table('order_tickets', function (Blueprint $table) {
                $table->dropForeign(['order_id']);
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropForeign(['order_id']);
            });
        }
    }
};
