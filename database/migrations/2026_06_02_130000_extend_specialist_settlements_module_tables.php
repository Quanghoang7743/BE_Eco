<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialist_settlements')) {
            Schema::table('specialist_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialist_settlements', 'fee_per_ticket')) {
                    $table->decimal('fee_per_ticket', 15, 2)->default(100)->after('total_eligible_tickets');
                }

                if (! Schema::hasColumn('specialist_settlements', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('amount')->index();
                }

                if (! Schema::hasColumn('specialist_settlements', 'paid_by')) {
                    $table->unsignedBigInteger('paid_by')->nullable()->after('approved_at')->index();
                }

                if (! Schema::hasColumn('specialist_settlements', 'note')) {
                    $table->text('note')->nullable()->after('notes');
                }

                if (! Schema::hasColumn('specialist_settlements', 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('note')->index();
                }

                if (! Schema::hasColumn('specialist_settlements', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by')->index();
                }
            });

            if (Schema::hasColumn('specialist_settlements', 'notes') && Schema::hasColumn('specialist_settlements', 'note')) {
                DB::table('specialist_settlements')
                    ->whereNull('note')
                    ->update(['note' => DB::raw('notes')]);
            }

            DB::table('specialist_settlements')
                ->whereIn('status', ['pending', 'pending_settlement', 'estimated'])
                ->update(['status' => 'WAITING_APPROVAL']);

            DB::table('specialist_settlements')
                ->whereIn('status', ['approved'])
                ->update(['status' => 'APPROVED']);

            DB::table('specialist_settlements')
                ->whereIn('status', ['paid', 'settled', 'completed'])
                ->update(['status' => 'PAID']);
        }

        if (Schema::hasTable('specialist_ticket_earnings')) {
            Schema::table('specialist_ticket_earnings', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialist_ticket_earnings', 'adjustment_source_earning_id')) {
                    $table->unsignedBigInteger('adjustment_source_earning_id')->nullable()->after('settlement_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('specialist_ticket_earnings')) {
            Schema::table('specialist_ticket_earnings', function (Blueprint $table): void {
                if (Schema::hasColumn('specialist_ticket_earnings', 'adjustment_source_earning_id')) {
                    $table->dropColumn('adjustment_source_earning_id');
                }
            });
        }

        if (Schema::hasTable('specialist_settlements')) {
            Schema::table('specialist_settlements', function (Blueprint $table): void {
                foreach (['cancelled_at', 'cancelled_by', 'note', 'paid_by', 'created_by', 'fee_per_ticket'] as $column) {
                    if (Schema::hasColumn('specialist_settlements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
