<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('prize_templates')) {
            Schema::create('prize_templates', function (Blueprint $table) {
                $table->id();
                $table->string('prize_name', 150);
                $table->decimal('monetary_value', 15, 2)->default(0);
                $table->string('image_path', 2000)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
            });
        }

        if (Schema::hasTable('rooms') && !Schema::hasColumn('rooms', 'prize_template_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->unsignedBigInteger('prize_template_id')->nullable()->after('prize_image_url');
                $table->index('prize_template_id');
            });
        }

        if (Schema::hasTable('room_schedules') && !Schema::hasColumn('room_schedules', 'prize_template_id')) {
            Schema::table('room_schedules', function (Blueprint $table) {
                $table->unsignedBigInteger('prize_template_id')->nullable()->after('last_run_message');
                $table->index('prize_template_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'prize_template_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropIndex(['prize_template_id']);
                $table->dropColumn('prize_template_id');
            });
        }

        if (Schema::hasTable('room_schedules') && Schema::hasColumn('room_schedules', 'prize_template_id')) {
            Schema::table('room_schedules', function (Blueprint $table) {
                $table->dropIndex(['prize_template_id']);
                $table->dropColumn('prize_template_id');
            });
        }

        Schema::dropIfExists('prize_templates');
    }
};
