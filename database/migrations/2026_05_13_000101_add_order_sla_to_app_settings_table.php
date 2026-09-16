<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('app_settings', 'order_sla_timeout_seconds')) {
                $table->unsignedInteger('order_sla_timeout_seconds')->nullable()->after('ticket_expiry_days');
            }

            if (!Schema::hasColumn('app_settings', 'order_sla_warning_seconds')) {
                $table->unsignedInteger('order_sla_warning_seconds')->nullable()->after('order_sla_timeout_seconds');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            if (Schema::hasColumn('app_settings', 'order_sla_warning_seconds')) {
                $table->dropColumn('order_sla_warning_seconds');
            }

            if (Schema::hasColumn('app_settings', 'order_sla_timeout_seconds')) {
                $table->dropColumn('order_sla_timeout_seconds');
            }
        });
    }
};
