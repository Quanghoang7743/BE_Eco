<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        if (!Schema::hasTable('store_approval_histories')) {
            Schema::create('store_approval_histories', function (Blueprint $table): void {
                $table->id();
                $table->uuid('store_id');
                $table->unsignedInteger('attempt_number')->default(1);
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('submitted_by')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by')->nullable()->index();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'attempt_number'], 'store_approval_histories_store_attempt_unique');
                $table->index(['store_id', 'status'], 'store_approval_histories_store_status_idx');

                $table->foreign('store_id')
                    ->references('id')
                    ->on('stores')
                    ->cascadeOnDelete();
            });
        }

        $this->backfillExistingStores();
    }

    public function down(): void
    {
        Schema::dropIfExists('store_approval_histories');
    }

    private function backfillExistingStores(): void
    {
        if (!Schema::hasTable('store_approval_histories')) {
            return;
        }

        $hasSubmittedAt = Schema::hasColumn('stores', 'submitted_at');
        $hasSubmittedBy = Schema::hasColumn('stores', 'submitted_by');

        DB::table('stores')
            ->orderBy('created_at')
            ->chunk(200, function ($stores) use ($hasSubmittedAt, $hasSubmittedBy): void {
                $now = now();
                $rows = [];

                foreach ($stores as $store) {
                    $status = mb_strtolower(trim((string) ($store->status ?? 'pending')));
                    $historyStatus = match ($status) {
                        'active', 'approved' => 'approved',
                        'rejected' => 'rejected',
                        default => 'pending',
                    };

                    $submittedAt = $hasSubmittedAt && !empty($store->submitted_at)
                        ? $store->submitted_at
                        : ($store->created_at ?: $now);
                    $submittedBy = $hasSubmittedBy && !empty($store->submitted_by)
                        ? $store->submitted_by
                        : ($store->owner_id ?? null);

                    $reviewedAt = null;
                    if ($historyStatus === 'approved') {
                        $reviewedAt = $store->approved_at ?: ($store->updated_at ?? null);
                    } elseif ($historyStatus === 'rejected') {
                        $reviewedAt = $store->approved_at ?: ($store->updated_at ?? null);
                    }

                    $rows[] = [
                        'store_id' => (string) $store->id,
                        'attempt_number' => 1,
                        'status' => $historyStatus,
                        'submitted_by' => is_numeric($submittedBy) ? (int) $submittedBy : null,
                        'reviewed_by' => is_numeric($store->approved_by ?? null) ? (int) $store->approved_by : null,
                        'submitted_at' => $submittedAt,
                        'reviewed_at' => $reviewedAt,
                        'rejection_reason' => $historyStatus === 'rejected' ? ($store->rejection_reason ?? null) : null,
                        'created_at' => $submittedAt,
                        'updated_at' => $store->updated_at ?: $now,
                    ];
                }

                if (!empty($rows)) {
                    DB::table('store_approval_histories')->insertOrIgnore($rows);
                }
            });
    }
};
