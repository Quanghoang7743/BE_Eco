<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('report_exports')) {
            return;
        }

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->index();
            $table->string('report_type', 100)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->text('file_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
