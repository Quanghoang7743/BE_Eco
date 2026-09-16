<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_user_verifications')) {
            return;
        }

        Schema::create('client_user_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('full_name', 255);
            $table->string('id_number', 50);
            $table->date('birth_date');
            $table->text('address');
            $table->string('id_front_image', 2048);
            $table->string('id_back_image', 2048);
            $table->string('id_selfie_image', 2048);
            $table->string('status', 30)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('user_id', 'client_user_verifications_user_unique');
            $table->index('status', 'client_user_verifications_status_idx');
            $table->index('submitted_at', 'client_user_verifications_submitted_at_idx');
            $table->index('id_number', 'client_user_verifications_id_number_idx');

            $table->foreign('user_id', 'client_user_verifications_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('reviewed_by', 'client_user_verifications_reviewer_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_user_verifications');
    }
};
