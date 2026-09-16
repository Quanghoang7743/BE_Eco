<?php

use App\Models\AffiliatePayoutBatch;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payout_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('affiliate_payout_batches')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('commission_count')->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('tax_withholding_amount', 14, 2)->default(0);
            $table->decimal('net_payout_amount', 14, 2)->default(0);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('status', 50)->default('DRAFT');
            $table->string('payment_reference', 191)->nullable();
            $table->string('payment_proof_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'affiliate_id'], 'aff_payout_batch_affiliate_unique');
            $table->index(['affiliate_id', 'status'], 'aff_payout_items_affiliate_status_idx');
        });

        if (Schema::hasTable('affiliate_commissions')) {
            Schema::table('affiliate_commissions', function (Blueprint $table) {
                $table->foreign('payout_batch_id', 'aff_comm_payout_batch_fk')
                    ->references('id')
                    ->on('affiliate_payout_batches')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('withdraw_requests') || !Schema::hasTable('affiliate_payout_batches')) {
            return;
        }

        DB::table('withdraw_requests')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $status = strtolower((string) ($row->status ?? 'pending'));
                    $mappedStatus = match ($status) {
                        'approved' => AffiliatePayoutBatch::STATUS_PAID,
                        'rejected' => AffiliatePayoutBatch::STATUS_CANCELLED,
                        default => AffiliatePayoutBatch::STATUS_WAITING_APPROVAL,
                    };
                    $createdAt = !empty($row->created_at) ? Carbon::parse((string) $row->created_at) : now();
                    $updatedAt = !empty($row->updated_at) ? Carbon::parse((string) $row->updated_at) : $createdAt;

                    $paidAt = $mappedStatus === AffiliatePayoutBatch::STATUS_PAID
                        ? $updatedAt
                        : null;

                    $batchId = DB::table('affiliate_payout_batches')->insertGetId([
                        'batch_code' => 'LEGACY-WR-' . $row->id,
                        'payout_period_from' => $createdAt->toDateString(),
                        'payout_period_to' => $createdAt->toDateString(),
                        'total_affiliates' => 1,
                        'total_commission' => (float) ($row->amount ?? 0),
                        'total_tax_withholding' => 0,
                        'total_payout' => (float) ($row->amount ?? 0),
                        'status' => $mappedStatus,
                        'created_by' => null,
                        'approved_by' => null,
                        'paid_by' => null,
                        'approved_at' => $mappedStatus === AffiliatePayoutBatch::STATUS_PAID ? $updatedAt : null,
                        'paid_at' => $paidAt,
                        'note' => 'Backfilled from legacy withdraw_requests #' . $row->id . ($row->note ? "\n" . $row->note : ''),
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]);

                    $bankAccountId = Schema::hasTable('bank_accounts')
                        ? DB::table('bank_accounts')
                            ->where('user_id', $row->user_id)
                            ->orderByDesc('is_default')
                            ->orderByDesc('id')
                            ->value('id')
                        : null;

                    DB::table('affiliate_payout_batch_items')->insert([
                        'batch_id' => $batchId,
                        'affiliate_id' => $row->user_id,
                        'commission_count' => 0,
                        'gross_amount' => (float) ($row->amount ?? 0),
                        'tax_withholding_amount' => 0,
                        'net_payout_amount' => (float) ($row->amount ?? 0),
                        'bank_account_id' => $bankAccountId,
                        'status' => $mappedStatus,
                        'payment_reference' => null,
                        'payment_proof_url' => $row->image ?? null,
                        'paid_at' => $paidAt,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('affiliate_commissions')) {
            Schema::table('affiliate_commissions', function (Blueprint $table) {
                if (Schema::hasColumn('affiliate_commissions', 'payout_batch_id')) {
                    $table->dropForeign('aff_comm_payout_batch_fk');
                }
            });
        }

        Schema::dropIfExists('affiliate_payout_batch_items');
    }
};
