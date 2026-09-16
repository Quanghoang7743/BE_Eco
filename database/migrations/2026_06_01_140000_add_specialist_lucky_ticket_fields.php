<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return;
        }

        Schema::table('specialist_ticket_earnings', function (Blueprint $table): void {
            if (! Schema::hasColumn('specialist_ticket_earnings', 'ticket_status')) {
                $table->string('ticket_status', 50)->nullable()->after('ticket_id')->index();
            }

            if (! Schema::hasColumn('specialist_ticket_earnings', 'earning_status')) {
                $table->string('earning_status', 50)->nullable()->after('status')->index();
            }

            if (! Schema::hasColumn('specialist_ticket_earnings', 'fee_per_ticket')) {
                $table->decimal('fee_per_ticket', 15, 2)->default(100)->after('ticket_value_amount');
            }

            if (! Schema::hasColumn('specialist_ticket_earnings', 'generated_at')) {
                $table->timestamp('generated_at')->nullable()->after('reason_not_eligible')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return;
        }

        Schema::table('specialist_ticket_earnings', function (Blueprint $table): void {
            foreach (['generated_at', 'fee_per_ticket', 'earning_status', 'ticket_status'] as $column) {
                if (Schema::hasColumn('specialist_ticket_earnings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
