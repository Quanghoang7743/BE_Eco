<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('store_operating_histories')) {
            return;
        }

        Schema::create('store_operating_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->index();
            $table->string('action_code', 80)->index();
            $table->string('action_label', 150);
            $table->string('status_after', 20)->index();
            $table->string('reason', 500)->nullable();
            $table->uuid('changed_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_operating_histories');
    }
};
