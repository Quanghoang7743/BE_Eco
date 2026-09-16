<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ComplaintStoreLockService
{
    public function lock(Complaint $complaint, User|string|int|null $actor = null): void
    {
        DB::transaction(function () use ($complaint, $actor): void {
            if (! Schema::hasTable('complaint_store_locks')) {
                throw new RuntimeException('COMPLAINT_STORE_LOCK_SCHEMA_NOT_READY');
            }

            $store = Store::query()->whereKey((string) $complaint->store_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('complaint_store_locks')
                ->where('complaint_id', (string) $complaint->id)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                DB::table('complaint_store_locks')->insert([
                    'id' => (string) Str::uuid(),
                    'complaint_id' => (string) $complaint->id,
                    'store_id' => (string) $store->id,
                    'locked_by' => $this->actorId($actor),
                    'created_at' => now(),
                    'released_at' => null,
                    'release_reason' => null,
                    'released_by' => null,
                ]);
            } elseif ($existing->released_at !== null) {
                throw new RuntimeException('COMPLAINT_STORE_LOCK_ALREADY_RELEASED');
            }

            $updates = [
                'is_debt_locked' => true,
                'debt_locked_at' => $store->debt_locked_at ?? now(),
                'updated_at' => now(),
            ];
            if (trim((string) ($store->debt_lock_reason ?? '')) === '') {
                $updates['debt_lock_reason'] = 'complaint_lock:' . $complaint->id;
            }
            $store->forceFill($updates)->save();
        });
    }

    public function release(Complaint $complaint, string $reason, User|string|int|null $actor = null): void
    {
        DB::transaction(function () use ($complaint, $reason, $actor): void {
            if (! Schema::hasTable('complaint_store_locks')) {
                return;
            }

            $lock = DB::table('complaint_store_locks')
                ->where('complaint_id', (string) $complaint->id)
                ->lockForUpdate()
                ->first();
            if (! $lock || $lock->released_at !== null) {
                return;
            }

            DB::table('complaint_store_locks')->where('id', $lock->id)->update([
                'released_at' => now(),
                'release_reason' => $reason,
                'released_by' => $this->actorId($actor),
            ]);

            $store = Store::query()->whereKey((string) $lock->store_id)->lockForUpdate()->first();
            if (! $store) {
                return;
            }

            $otherActiveLock = DB::table('complaint_store_locks')
                ->where('store_id', (string) $store->id)
                ->whereNull('released_at')
                ->orderBy('created_at')
                ->first();
            $ticketDebt = max(0, (float) ($store->ticket_debt ?? 0));
            $currentReason = trim((string) ($store->debt_lock_reason ?? ''));
            $belongsToThisComplaint = $currentReason === 'complaint_lock:' . $complaint->id;
            $hasUnrelatedLockReason = (bool) ($store->is_debt_locked ?? false)
                && $currentReason !== ''
                && ! $belongsToThisComplaint;

            if ($otherActiveLock) {
                $store->forceFill([
                    'is_debt_locked' => true,
                    'debt_lock_reason' => 'complaint_lock:' . $otherActiveLock->complaint_id,
                    'updated_at' => now(),
                ])->save();
                return;
            }

            if ($ticketDebt > 0 || $hasUnrelatedLockReason) {
                $payload = ['is_debt_locked' => true, 'updated_at' => now()];
                if ($ticketDebt > 0 && $belongsToThisComplaint) {
                    $payload['debt_lock_reason'] = 'ticket_debt_exceeded';
                }
                $store->forceFill($payload)->save();
                return;
            }

            $store->forceFill([
                'is_debt_locked' => false,
                'debt_locked_at' => null,
                'debt_lock_reason' => null,
                'updated_at' => now(),
            ])->save();
        });
    }

    public function hasActiveLock(Complaint|string $complaint): bool
    {
        return Schema::hasTable('complaint_store_locks')
            && DB::table('complaint_store_locks')
                ->where('complaint_id', (string) ($complaint instanceof Complaint ? $complaint->id : $complaint))
                ->whereNull('released_at')
                ->exists();
    }

    private function actorId(User|string|int|null $actor): ?string
    {
        if ($actor instanceof User) {
            return (string) $actor->id;
        }

        return $actor !== null && trim((string) $actor) !== '' ? (string) $actor : null;
    }
}
