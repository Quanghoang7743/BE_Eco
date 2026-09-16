<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialist_audit_logs')) {
            return;
        }

        Schema::create('specialist_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('specialist_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['specialist_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_audit_logs');
    }
};
