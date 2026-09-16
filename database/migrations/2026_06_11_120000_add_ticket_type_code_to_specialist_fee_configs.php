<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('specialist_fee_configs')) {
            return;
        }

        Schema::table('specialist_fee_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('specialist_fee_configs', 'ticket_type_code')) {
                $table->string('ticket_type_code', 50)
                    ->nullable()
                    ->after('config_name')
                    ->index('idx_specialist_fee_ticket_type');
            }
        });

        DB::table('specialist_fee_configs')
            ->whereNull('ticket_type_code')
            ->orWhere('ticket_type_code', '')
            ->update([
                'ticket_type_code' => 'TICKET_DEFAULT',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (
            Schema::hasTable('specialist_fee_configs')
            && Schema::hasColumn('specialist_fee_configs', 'ticket_type_code')
        ) {
            Schema::table('specialist_fee_configs', function (Blueprint $table): void {
                $table->dropIndex('idx_specialist_fee_ticket_type');
                $table->dropColumn('ticket_type_code');
            });
        }
    }
};
