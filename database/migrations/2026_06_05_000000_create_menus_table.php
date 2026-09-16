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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Display name
            $table->string('url')->nullable(); // Static URL (if custom type)
            $table->unsignedBigInteger('parent_id')->nullable()->default(0);
            $table->integer('order')->default(0);
            $table->boolean('active')->default(true);
            $table->enum('type', ['custom', 'category', 'post'])->default('custom');
            $table->unsignedBigInteger('reference_id')->nullable(); // ID of Category or Article
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
