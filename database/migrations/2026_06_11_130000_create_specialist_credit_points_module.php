<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialists')) {
            Schema::table('specialists', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialists', 'credit_points')) {
                    $table->unsignedTinyInteger('credit_points')->default(100)->after('other_documents_json')->index();
                }
            });

            $now = now();
            DB::table('users')
                ->where('role', 'sale')
                ->orderBy('id')
                ->select('id')
                ->chunkById(200, function ($users) use ($now): void {
                    $rows = $users->map(static fn ($user): array => [
                        'user_id' => (int) $user->id,
                        'credit_points' => 100,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    if ($rows !== []) {
                        DB::table('specialists')->insertOrIgnore($rows);
                    }
                });

            DB::table('specialists')
                ->whereNull('credit_points')
                ->update(['credit_points' => 100]);
        }

        if (! Schema::hasTable('specialist_credit_logs')) {
            Schema::create('specialist_credit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->unsignedBigInteger('admin_user_id')->nullable()->index();
                $table->string('action_type', 20)->index();
                $table->integer('points_change');
                $table->unsignedTinyInteger('points_before');
                $table->unsignedTinyInteger('points_after');
                $table->text('reason');
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->unsignedBigInteger('source_log_id')->nullable()->index();
                $table->unsignedBigInteger('cancelled_by')->nullable()->index();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('specialist_id')->references('id')->on('specialists')->cascadeOnDelete();
                $table->foreign('admin_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('source_log_id')->references('id')->on('specialist_credit_logs')->nullOnDelete();
                $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['specialist_id', 'status', 'created_at'], 'idx_specialist_credit_history');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_credit_logs');

        if (Schema::hasTable('specialists') && Schema::hasColumn('specialists', 'credit_points')) {
            Schema::table('specialists', function (Blueprint $table): void {
                $table->dropColumn('credit_points');
            });
        }
    }
};
