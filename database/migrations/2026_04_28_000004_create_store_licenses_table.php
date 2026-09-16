<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_licenses')) {
            return;
        }

        Schema::create('store_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('license_type', 255);
            $table->string('license_number', 100)->nullable();
            $table->text('image_url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index('store_id');
            $table->index('status');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_licenses')) {
            return;
        }

        Schema::dropIfExists('store_licenses');
    }
};
