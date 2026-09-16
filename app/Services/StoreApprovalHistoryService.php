<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreApprovalHistory;
use Illuminate\Support\Facades\Schema;

class StoreApprovalHistoryService
{
    public function recordSubmission(Store $store, mixed $submittedBy = null): ?StoreApprovalHistory
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $storeId = (string) $store->id;
        if ($storeId === '') {
            return null;
        }

        $pendingHistory = StoreApprovalHistory::query()
            ->where('store_id', $storeId)
            ->where('status', 'pending')
            ->orderByDesc('attempt_number')
            ->first();

        if ($pendingHistory instanceof StoreApprovalHistory) {
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

        $nextAttemptNumber = ((int) StoreApprovalHistory::query()
            ->where('store_id', $storeId)
            ->max('attempt_number')) + 1;

        return StoreApprovalHistory::query()->create([
            'store_id' => $storeId,
            'attempt_number' => max(1, $nextAttemptNumber),
            'status' => 'pending',
            'submitted_by' => $this->toNullableUserId($submittedBy),
            'submitted_at' => now(),
        ]);
    }

    public function markReviewed(
        Store $store,
        string $status,
        mixed $reviewedBy = null,
        ?string $rejectionReason = null
    ): ?StoreApprovalHistory {
        if (!$this->isAvailable()) {
            return null;
        }

        $status = mb_strtolower(trim($status));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return null;
        }

        $history = StoreApprovalHistory::query()
            ->where('store_id', (string) $store->id)
            ->where('status', 'pending')
            ->orderByDesc('attempt_number')
            ->first();

        if (!$history instanceof StoreApprovalHistory) {
            $history = $this->recordSubmission($store, $store->owner_id ?? null);
        }

        if (!$history instanceof StoreApprovalHistory) {
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
        return Schema::hasTable('store_approval_histories');
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
