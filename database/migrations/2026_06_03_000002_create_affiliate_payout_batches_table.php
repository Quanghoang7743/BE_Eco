<?php

use App\Models\AffiliatePayoutBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 50)->unique();
            $table->date('payout_period_from');
            $table->date('payout_period_to');
            $table->unsignedInteger('total_affiliates')->default(0);
            $table->decimal('total_commission', 14, 2)->default(0);
            $table->decimal('total_tax_withholding', 14, 2)->default(0);
            $table->decimal('total_payout', 14, 2)->default(0);
            $table->string('status', 50)->default(AffiliatePayoutBatch::STATUS_DRAFT);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'payout_period_from', 'payout_period_to'], 'aff_payout_batches_status_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payout_batches');
    }
};
