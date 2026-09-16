<?php

use App\Models\AffiliateCommission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('order_id', 36)->nullable();
            $table->char('order_item_id', 36)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->char('seller_id', 36)->nullable();
            $table->string('commission_type', 20)->default('percent');
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->decimal('commission_base_amount', 12, 2)->default(0);
            $table->decimal('pending_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('tax_withholding_amount', 12, 2)->default(0);
            $table->decimal('payout_amount', 12, 2)->default(0);
            $table->string('status', 50)->default(AffiliateCommission::STATUS_PENDING);
            $table->text('reason_cancelled')->nullable();
            $table->text('held_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('payout_batch_id')->nullable();
            $table->foreignId('source_assignment_id')->nullable()->constrained('affiliate_customers')->nullOnDelete();
            $table->json('policy_snapshot_json')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status'], 'aff_comm_aff_status_idx');
            $table->index(['customer_id', 'status'], 'aff_comm_customer_status_idx');
            $table->index(['order_id', 'status'], 'aff_comm_order_status_idx');
            $table->unique(['order_item_id', 'affiliate_id'], 'aff_comm_order_item_affiliate_unique');
        });

        if (!Schema::hasTable('commission_users') || !Schema::hasTable('order_items')) {
            return;
        }

        DB::table('commission_users')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $orderItemIds = collect($rows)->pluck('order_item_id')->filter()->values()->all();
                $orderItems = DB::table('order_items')
                    ->whereIn('id', $orderItemIds)
                    ->get()
                    ->keyBy('id');
                $orderIds = $orderItems->pluck('order_id')->filter()->unique()->values()->all();
                $orders = !empty($orderIds)
                    ? DB::table('orders')->whereIn('id', $orderIds)->get()->keyBy('id')
                    : collect();
                $userIds = collect($rows)
                    ->pluck('user_id')
                    ->push(...$orders->pluck('user_id')->filter()->all())
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
                $users = !empty($userIds)
                    ? DB::table('users')->whereIn('id', $userIds)->pluck('id')->map(fn ($id) => (int) $id)->all()
                    : [];
                $existingUserIds = array_flip($users);

                foreach ($rows as $row) {
                    if (empty($existingUserIds[(int) $row->user_id])) {
                        continue;
                    }

                    $orderItem = $orderItems->get($row->order_item_id);
                    $order = $orderItem ? $orders->get($orderItem->order_id) : null;
                    $createdAt = $row->created_at ?? now();
                    $amount = (float) ($row->amount ?? 0);
                    $status = strtoupper((string) ($row->status ?? 'pending'));
                    $mappedStatus = AffiliateCommission::STATUS_WAITING_ORDER_COMPLETE;

                    if (in_array(strtolower((string) ($order->status ?? '')), ['cancelled', 'canceled', 'returned', 'refunded'], true) || $status === 'CANCEL') {
                        $mappedStatus = AffiliateCommission::STATUS_CANCELLED;
                    } elseif ($status === 'PAID') {
                        $mappedStatus = AffiliateCommission::STATUS_PAID;
                    } else {
                        $paymentMethod = strtoupper(trim((string) ($order->payment_method ?? '')));
                        $paymentStatus = strtolower(trim((string) ($order->status_payment ?? '')));
                        $orderStatus = strtolower(trim((string) ($order->status ?? '')));
                        $isPaid = $paymentStatus === 'paid' || !in_array($paymentMethod, ['COD', 'CASH'], true);
                        $isCompleted = in_array($orderStatus, ['completed', 'delivered'], true) || !empty($order->delivered_at);
                        $mappedStatus = !$isPaid
                            ? AffiliateCommission::STATUS_PENDING
                            : ($isCompleted ? AffiliateCommission::STATUS_ELIGIBLE : AffiliateCommission::STATUS_WAITING_ORDER_COMPLETE);
                    }

                    $baseAmount = (float) ($orderItem->total_price ?? (($orderItem->unit_price ?? $orderItem->price ?? 0) * ($orderItem->quantity ?? 1)));
                    $commissionType = (string) ($orderItem->commission_rate_mode ?? 'percent');
                    $commissionValue = $commissionType === 'percent' && $baseAmount > 0
                        ? round(($amount / $baseAmount) * 100, 2)
                        : $amount;

                    $assignmentId = null;
                    if (Schema::hasTable('affiliate_customers') && !empty($order?->user_id)) {
                        $assignmentId = DB::table('affiliate_customers')
                            ->where('affiliate_id', $row->user_id)
                            ->where('customer_id', $order->user_id)
                            ->orderByDesc('assigned_at')
                            ->value('id');
                    }

                    DB::table('affiliate_commissions')->updateOrInsert(
                        [
                            'order_item_id' => $row->order_item_id,
                            'affiliate_id' => $row->user_id,
                        ],
                        [
                            'customer_id' => !empty($existingUserIds[(int) ($order->user_id ?? 0)]) ? (int) $order->user_id : null,
                            'order_id' => $orderItem->order_id ?? null,
                            'product_id' => $orderItem->product_id ?? null,
                            'seller_id' => $order->store_id ?? null,
                            'commission_type' => $commissionType,
                            'commission_value' => $commissionValue,
                            'commission_base_amount' => $baseAmount,
                            'pending_amount' => $amount,
                            'approved_amount' => $mappedStatus === AffiliateCommission::STATUS_PAID ? $amount : 0,
                            'tax_withholding_amount' => 0,
                            'payout_amount' => $mappedStatus === AffiliateCommission::STATUS_PAID ? $amount : 0,
                            'status' => $mappedStatus,
                            'reason_cancelled' => $mappedStatus === AffiliateCommission::STATUS_CANCELLED ? 'Backfilled from legacy commission status/order status.' : null,
                            'held_reason' => null,
                            'approved_by' => null,
                            'approved_at' => $mappedStatus === AffiliateCommission::STATUS_PAID ? ($row->updated_at ?? $createdAt) : null,
                            'eligible_at' => in_array($mappedStatus, [AffiliateCommission::STATUS_ELIGIBLE, AffiliateCommission::STATUS_PAID], true) ? ($row->updated_at ?? $createdAt) : null,
                            'paid_at' => $mappedStatus === AffiliateCommission::STATUS_PAID ? ($row->updated_at ?? $createdAt) : null,
                            'payout_batch_id' => null,
                            'source_assignment_id' => $assignmentId,
                            'policy_snapshot_json' => json_encode([
                                'legacy_commission_user_id' => $row->id,
                                'legacy_status' => $row->status,
                            ], JSON_UNESCAPED_UNICODE),
                            'created_at' => $createdAt,
                            'updated_at' => $row->updated_at ?? $createdAt,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
    }
};
