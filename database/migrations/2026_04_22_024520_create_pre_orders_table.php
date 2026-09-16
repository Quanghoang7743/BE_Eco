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
        Schema::create('pre_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pre_order_code')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->json('payload');
            $table->string('status')->default('pending'); // pending, converted, expired
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_orders');
    }
};
