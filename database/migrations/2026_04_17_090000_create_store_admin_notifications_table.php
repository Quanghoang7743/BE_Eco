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
        if (Schema::hasTable('store_admin_notifications')) {
            return;
        }

        Schema::create('store_admin_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('store_id', 64)->index();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('order_code', 100)->nullable();
            $table->string('type', 80)->default('order_new')->index();
            $table->string('title', 191);
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'order_id', 'type'],
                'store_admin_notifications_store_order_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_admin_notifications');
    }
};
