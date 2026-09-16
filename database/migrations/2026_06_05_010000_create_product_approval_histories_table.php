<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        if (!Schema::hasTable('product_approval_histories')) {
            Schema::create('product_approval_histories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedInteger('attempt_number')->default(1);
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('submitted_by')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by')->nullable()->index();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'attempt_number'], 'product_approval_histories_product_attempt_unique');
                $table->index(['product_id', 'status'], 'product_approval_histories_product_status_idx');

                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            });
        }

        $this->backfillExistingProducts();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_approval_histories');
    }

    private function backfillExistingProducts(): void
    {
        if (!Schema::hasTable('product_approval_histories')) {
            return;
        }

        DB::table('products')
            ->select([
                'id',
                'status',
                'approved_by',
                'approved_at',
                'published_at',
                'rejection_reason',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                $now = now();
                $rows = [];

                foreach ($products as $product) {
                    $status = mb_strtolower(trim((string) ($product->status ?? 'pending_approval')));
                    $historyStatus = match ($status) {
                        'published', 'approved', 'active' => 'approved',
                        'rejected' => 'rejected',
                        default => 'pending',
                    };

                    $reviewedAt = null;
                    if ($historyStatus === 'approved') {
                        $reviewedAt = $product->approved_at ?: ($product->published_at ?: $product->updated_at);
                    } elseif ($historyStatus === 'rejected') {
                        $reviewedAt = $product->updated_at;
                    }

                    $rows[] = [
                        'product_id' => (int) $product->id,
                        'attempt_number' => 1,
                        'status' => $historyStatus,
                        'submitted_by' => null,
                        'reviewed_by' => $historyStatus === 'approved' ? $product->approved_by : null,
                        'submitted_at' => $product->created_at ?: $now,
                        'reviewed_at' => $reviewedAt,
                        'rejection_reason' => $historyStatus === 'rejected' ? $product->rejection_reason : null,
                        'created_at' => $product->created_at ?: $now,
                        'updated_at' => $product->updated_at ?: $now,
                    ];
                }

                if (!empty($rows)) {
                    DB::table('product_approval_histories')->insertOrIgnore($rows);
                }
            });
    }
};
