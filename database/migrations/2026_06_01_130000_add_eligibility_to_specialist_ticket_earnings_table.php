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
            if (! Schema::hasColumn('specialist_ticket_earnings', 'is_eligible')) {
                $table->boolean('is_eligible')->nullable()->after('status')->index();
            }

            if (! Schema::hasColumn('specialist_ticket_earnings', 'reason_not_eligible')) {
                $table->string('reason_not_eligible', 255)->nullable()->after('earning_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('specialist_ticket_earnings')) {
            return;
        }

        Schema::table('specialist_ticket_earnings', function (Blueprint $table): void {
            if (Schema::hasColumn('specialist_ticket_earnings', 'reason_not_eligible')) {
                $table->dropColumn('reason_not_eligible');
            }

            if (Schema::hasColumn('specialist_ticket_earnings', 'is_eligible')) {
                $table->dropColumn('is_eligible');
            }
        });
    }
};
