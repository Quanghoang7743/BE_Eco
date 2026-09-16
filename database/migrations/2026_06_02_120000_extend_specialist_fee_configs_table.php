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
            if (! Schema::hasColumn('specialist_fee_configs', 'config_name')) {
                $table->string('config_name')->nullable()->after('id');
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'apply_scope')) {
                $table->string('apply_scope', 40)->default('global')->after('fee_per_ticket')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'specialist_id')) {
                $table->unsignedBigInteger('specialist_id')->nullable()->after('apply_scope')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'region_id')) {
                $table->unsignedBigInteger('region_id')->nullable()->after('specialist_id')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'store_type')) {
                $table->string('store_type')->nullable()->after('region_id')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'status')) {
                $table->string('status', 30)->default('DRAFT')->after('effective_to')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('created_by')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('approved_at')->index();
            }

            if (! Schema::hasColumn('specialist_fee_configs', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by')->index();
            }
        });

        DB::table('specialist_fee_configs')
            ->whereNull('config_name')
            ->orWhere('config_name', '')
            ->update(['config_name' => DB::raw("CONCAT('Cấu hình phí #', id)")]);

        DB::table('specialist_fee_configs')
            ->whereNull('apply_scope')
            ->orWhere('apply_scope', '')
            ->update(['apply_scope' => 'global']);

        DB::table('specialist_fee_configs')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'ACTIVE']);

        DB::table('specialist_fee_configs')
            ->whereNull('approved_at')
            ->where('status', 'ACTIVE')
            ->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('specialist_fee_configs')) {
            return;
        }

        Schema::table('specialist_fee_configs', function (Blueprint $table): void {
            foreach ([
                'config_name',
                'apply_scope',
                'specialist_id',
                'region_id',
                'store_type',
                'status',
                'approved_by',
                'approved_at',
                'cancelled_by',
                'cancelled_at',
            ] as $column) {
                if (Schema::hasColumn('specialist_fee_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
