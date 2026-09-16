<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('money_wallet_withdrawal_requests')) {
            return;
        }

        if (! Schema::hasColumn('money_wallet_withdrawal_requests', 'idempotency_key')) {
            Schema::table('money_wallet_withdrawal_requests', function (Blueprint $table): void {
                $table->string('idempotency_key', 160)
                    ->nullable()
                    ->after('account_name');
            });
        }

        try {
            Schema::table('money_wallet_withdrawal_requests', function (Blueprint $table): void {
                $table->unique('idempotency_key', 'uniq_money_wallet_withdrawal_idempotency');
            });
        } catch (Throwable) {
            // Existing installs may already have the unique key from a manual hardening pass.
        }
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('money_wallet_withdrawal_requests')
            || ! Schema::hasColumn('money_wallet_withdrawal_requests', 'idempotency_key')
        ) {
            return;
        }

        try {
            Schema::table('money_wallet_withdrawal_requests', function (Blueprint $table): void {
                $table->dropUnique('uniq_money_wallet_withdrawal_idempotency');
            });
        } catch (Throwable) {
            // Keep rollback non-blocking when the index name differs by database driver.
        }

        Schema::table('money_wallet_withdrawal_requests', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
    }
};
