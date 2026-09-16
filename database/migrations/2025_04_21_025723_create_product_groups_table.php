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
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('position')->default(0); // Vị trí hiển thị
            $table->unsignedBigInteger('parent_id')->nullable(); // Nhóm cha
            $table->string('logo')->nullable(); // Logo
            $table->timestamps();
        
            $table->foreign('parent_id')->references('id')->on('product_groups')->onDelete('set null');
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_groups');
    }
};
