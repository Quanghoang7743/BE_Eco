<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rooms')) {
            Schema::table('rooms', function (Blueprint $table) {
                if (!Schema::hasColumn('rooms', 'ticket_type_code')) {
                    $table->string('ticket_type_code', 50)->nullable()->after('required_votes');
                    $table->index('ticket_type_code');
                }

                if (!Schema::hasColumn('rooms', 'open_at')) {
                    $table->timestamp('open_at')->nullable()->after('ticket_type_code');
                    $table->index('open_at');
                }

                if (!Schema::hasColumn('rooms', 'close_at')) {
                    $table->timestamp('close_at')->nullable()->after('open_at');
                    $table->index('close_at');
                }

                if (!Schema::hasColumn('rooms', 'is_template')) {
                    $table->boolean('is_template')->default(false)->after('close_at');
                    $table->index('is_template');
                }
            });
        }

        if (Schema::hasTable('room_schedules')) {
            Schema::table('room_schedules', function (Blueprint $table) {
                if (!Schema::hasColumn('room_schedules', 'template_room_id')) {
                    $table->uuid('template_room_id')->nullable()->after('prize_template_id');
                    $table->index('template_room_id');
                }
            });
        }

        if (Schema::hasTable('tickets')) {
            Schema::table('tickets', function (Blueprint $table) {
                if (!Schema::hasColumn('tickets', 'ticket_type_code')) {
                    $table->string('ticket_type_code', 50)->nullable()->after('status');
                    $table->index('ticket_type_code');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets')) {
            Schema::table('tickets', function (Blueprint $table) {
                if (Schema::hasColumn('tickets', 'ticket_type_code')) {
                    $table->dropIndex(['ticket_type_code']);
                    $table->dropColumn('ticket_type_code');
                }
            });
        }

        if (Schema::hasTable('room_schedules')) {
            Schema::table('room_schedules', function (Blueprint $table) {
                if (Schema::hasColumn('room_schedules', 'template_room_id')) {
                    $table->dropIndex(['template_room_id']);
                    $table->dropColumn('template_room_id');
                }
            });
        }

        if (Schema::hasTable('rooms')) {
            Schema::table('rooms', function (Blueprint $table) {
                if (Schema::hasColumn('rooms', 'is_template')) {
                    $table->dropIndex(['is_template']);
                    $table->dropColumn('is_template');
                }

                if (Schema::hasColumn('rooms', 'close_at')) {
                    $table->dropIndex(['close_at']);
                    $table->dropColumn('close_at');
                }

                if (Schema::hasColumn('rooms', 'open_at')) {
                    $table->dropIndex(['open_at']);
                    $table->dropColumn('open_at');
                }

                if (Schema::hasColumn('rooms', 'ticket_type_code')) {
                    $table->dropIndex(['ticket_type_code']);
                    $table->dropColumn('ticket_type_code');
                }
            });
        }
    }
};
