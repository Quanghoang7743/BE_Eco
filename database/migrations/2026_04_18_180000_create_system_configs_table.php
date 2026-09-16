<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            return;
        }

        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->string('config_key', 100)->unique();
            $table->text('config_value')->nullable();
            $table->string('value_type', 20)->default('string');
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('config_key');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('system_configs')) {
            return;
        }

        Schema::dropIfExists('system_configs');
    }
};
