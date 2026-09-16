<?php

use App\Models\SpecialistFeeConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('specialist_fee_configs')
            || ! Schema::hasColumn('specialist_fee_configs', 'status')
        ) {
            return;
        }

        DB::table('specialist_fee_configs')
            ->where('status', SpecialistFeeConfig::STATUS_DRAFT)
            ->whereNull('approved_by')
            ->whereNull('cancelled_at')
            ->update([
                'status' => SpecialistFeeConfig::STATUS_ACTIVE,
                'approved_at' => DB::raw('created_at'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Keep legacy configs active; reverting would make the default fee unavailable.
    }
};
