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
            Schema::create('specialist_fee_configs', function (Blueprint $table): void {
                $table->id();
                $table->decimal('fee_per_ticket', 15, 2)->default(100);
                $table->timestamp('effective_from')->useCurrent()->index();
                $table->timestamp('effective_to')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['effective_from', 'effective_to'], 'idx_specialist_fee_effective_window');
            });
        }

        if (Schema::hasTable('specialist_fee_configs') && DB::table('specialist_fee_configs')->count() === 0) {
            $now = now();

            DB::table('specialist_fee_configs')->insert([
                'fee_per_ticket' => 100,
                'effective_from' => $now,
                'effective_to' => null,
                'created_by' => null,
                'notes' => 'Mức phí mặc định cho chuyên viên',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_fee_configs');
    }
};
