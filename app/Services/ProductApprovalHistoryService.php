<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductApprovalHistory;
use Illuminate\Support\Facades\Schema;

class ProductApprovalHistoryService
{
    public function recordSubmission(Product $product, mixed $submittedBy = null): ?ProductApprovalHistory
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $productId = (int) $product->id;
        if ($productId <= 0) {
            return null;
        }

        $pendingHistory = ProductApprovalHistory::query()
            ->where('product_id', $productId)
            ->where('status', 'pending')
            ->orderByDesc('attempt_number')
            ->first();

        if ($pendingHistory instanceof ProductApprovalHistory) {
            $updates = [];
            $submittedById = $this->toNullableUserId($submittedBy);
            if ($pendingHistory->submitted_at === null) {
                $updates['submitted_at'] = now();
            }
            if ($pendingHistory->submitted_by === null && $submittedById !== null) {
                $updates['submitted_by'] = $submittedById;
            }

            if (!empty($updates)) {
                $pendingHistory->update($updates);
            }

            return $pendingHistory;
        }

        $nextAttemptNumber = ((int) ProductApprovalHistory::query()
            ->where('product_id', $productId)
            ->max('attempt_number')) + 1;

        return ProductApprovalHistory::query()->create([
            'product_id' => $productId,
            'attempt_number' => max(1, $nextAttemptNumber),
            'status' => 'pending',
            'submitted_by' => $this->toNullableUserId($submittedBy),
            'submitted_at' => now(),
        ]);
    }

    public function markReviewed(
        Product $product,
        string $status,
        mixed $reviewedBy = null,
        ?string $rejectionReason = null
    ): ?ProductApprovalHistory {
        if (!$this->isAvailable()) {
            return null;
        }

        $status = mb_strtolower(trim($status));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return null;
        }

        $history = ProductApprovalHistory::query()
            ->where('product_id', (int) $product->id)
            ->where('status', 'pending')
            ->orderByDesc('attempt_number')
            ->first();

        if (!$history instanceof ProductApprovalHistory) {
            $history = $this->recordSubmission($product);
        }

        if (!$history instanceof ProductApprovalHistory) {
            return null;
        }

        $history->update([
            'status' => $status,
            'reviewed_by' => $this->toNullableUserId($reviewedBy),
            'reviewed_at' => now(),
            'rejection_reason' => $status === 'rejected' ? $rejectionReason : null,
        ]);

        return $history;
    }

    private function isAvailable(): bool
    {
        return Schema::hasTable('product_approval_histories');
    }

    private function toNullableUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
