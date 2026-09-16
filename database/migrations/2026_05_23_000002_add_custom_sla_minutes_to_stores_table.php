<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'custom_sla_minutes')) {
                $table->unsignedInteger('custom_sla_minutes')->nullable()->after('bank_account');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'custom_sla_minutes')) {
                $table->dropColumn('custom_sla_minutes');
            }
        });
    }
};
