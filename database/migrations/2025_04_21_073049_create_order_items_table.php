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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name'); // snapshot
            $table->string('thumbnail')->nullable(); // snapshot
            $table->decimal('price', 12, 0); // snapshot
            $table->integer('quantity');
            $table->foreignId('referrer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->decimal('commission_amount', 12, 0)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
