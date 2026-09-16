<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_withdrawal_requests', function (Blueprint $table) {
            $table->string('cccd_document', 255)->nullable()->after('approved_at');
            $table->string('tax_code', 50)->nullable()->after('cccd_document');
            $table->string('social_channel', 255)->nullable()->after('tax_code');
            $table->string('identity_phone', 30)->nullable()->after('social_channel');
            $table->string('bank_account_name', 255)->nullable()->after('identity_phone');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn([
                'cccd_document',
                'tax_code',
                'social_channel',
                'identity_phone',
                'bank_account_name',
                'bank_account_number',
            ]);
        });
    }
};