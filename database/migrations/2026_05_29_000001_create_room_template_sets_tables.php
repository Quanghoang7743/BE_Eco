<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('room_template_sets')) {
            Schema::create('room_template_sets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name', 255);
                $table->unsignedInteger('rooms_per_day');
                $table->unsignedInteger('required_votes');
                $table->string('ticket_type_code', 50)->nullable();
                $table->string('status', 50)->default('WAITING');
                $table->string('created_by', 64)->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->date('last_target_date')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('ticket_type_code');
                $table->index('created_by');
                $table->index('last_target_date');
            });
        }

        if (!Schema::hasTable('room_templates')) {
            Schema::create('room_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('room_template_set_id');
                $table->unsignedInteger('room_number');
                $table->string('room_name')->nullable();
                $table->string('status', 50)->default('WAITING');
                $table->unsignedInteger('required_votes');
                $table->string('ticket_type_code', 50)->nullable();
                $table->time('open_time')->nullable();
                $table->time('close_time')->nullable();
                $table->time('draw_time')->nullable();
                $table->unsignedBigInteger('prize_template_id')->nullable();
                $table->string('prize_type', 100)->default('electric_bike');
                $table->decimal('prize_value', 15, 2)->nullable();
                $table->text('prize_description')->nullable();
                $table->text('prize_image_url')->nullable();
                $table->json('secondary_prizes')->nullable();
                $table->timestamps();

                $table->unique(['room_template_set_id', 'room_number'], 'room_templates_set_room_unique');
                $table->index('room_template_set_id');
                $table->index('status');
                $table->index('ticket_type_code');
                $table->index('prize_template_id');

                $table->foreign('room_template_set_id', 'room_templates_set_fk')
                    ->references('id')
                    ->on('room_template_sets')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_templates');
        Schema::dropIfExists('room_template_sets');
    }
};
