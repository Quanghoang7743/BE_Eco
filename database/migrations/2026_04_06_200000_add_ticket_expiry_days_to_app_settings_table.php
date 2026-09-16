<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('app_settings', 'ticket_expiry_days')) {
                $table->unsignedInteger('ticket_expiry_days')->default(30)->after('donated');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            if (Schema::hasColumn('app_settings', 'ticket_expiry_days')) {
                $table->dropColumn('ticket_expiry_days');
            }
        });
    }
};
