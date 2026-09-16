<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialist_store_care_logs')) {
            return;
        }

        Schema::create('specialist_store_care_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('specialist_id')->nullable()->index();
            $table->uuid('store_id')->index();
            $table->string('care_type', 50)->default('call')->index();
            $table->text('content');
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['store_id', 'created_at'], 'idx_store_care_logs_store_date');
            $table->index(['specialist_id', 'created_at'], 'idx_store_care_logs_specialist_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_store_care_logs');
    }
};
