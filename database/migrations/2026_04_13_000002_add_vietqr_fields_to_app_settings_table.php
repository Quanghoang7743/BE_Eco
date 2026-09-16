<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('app_settings', 'vietqr_client_id')) {
                $table->string('vietqr_client_id')->nullable()->after('ticket_expiry_days');
            }
            if (!Schema::hasColumn('app_settings', 'vietqr_api_key')) {
                $table->string('vietqr_api_key')->nullable()->after('vietqr_client_id');
            }
            if (!Schema::hasColumn('app_settings', 'vietqr_bank_id')) {
                $table->string('vietqr_bank_id')->nullable()->after('vietqr_api_key');
            }
            if (!Schema::hasColumn('app_settings', 'vietqr_account_no')) {
                $table->string('vietqr_account_no')->nullable()->after('vietqr_bank_id');
            }
            if (!Schema::hasColumn('app_settings', 'vietqr_account_name')) {
                $table->string('vietqr_account_name')->nullable()->after('vietqr_account_no');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'vietqr_client_id',
                'vietqr_api_key',
                'vietqr_bank_id',
                'vietqr_account_no',
                'vietqr_account_name',
            ]);
        });
    }
};
