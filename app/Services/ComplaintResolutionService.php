<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ComplaintResolutionService
{
    public function __construct(
        private readonly OrderReturnService $orderReturnService,
        private readonly ComplaintTicketLockService $ticketLockService,
        private readonly ComplaintStoreLockService $storeLockService,
    ) {}

    public function createReturnForStore(Complaint $complaint, User $actor): OrderReturn
    {
        return DB::transaction(function () use ($complaint, $actor): OrderReturn {
            $complaint = $this->lockComplaint($complaint);
            $this->assertNotTerminal($complaint);

            $return = $this->orderReturnService->createComplaintReturn(
                $complaint,
                $actor,
                OrderReturn::STATUS_PENDING
            );

            $fromStatus = (string) $complaint->status;
            $complaint->forceFill([
                'status' => 'awaiting_store_action',
                'resolution_type' => 'return_requested',
                'resolution_note' => 'Đã tạo yêu cầu hoàn toàn bộ đơn hàng và chờ Store xác nhận.',
                'updated_at' => now(),
            ])->save();
            $this->log($complaint, $actor, 'return_requested', $fromStatus, 'awaiting_store_action', [
                'order_return_id' => (string) $return->id,
            ]);
            $this->broadcastStatusUpdated($complaint);

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function reject(Complaint $complaint, User $actor, string $reason): Complaint
    {
        return DB::transaction(function () use ($complaint, $actor, $reason): Complaint {
            $complaint = $this->lockComplaint($complaint);
            $this->assertNotTerminal($complaint);
            $fromStatus = (string) $complaint->status;
            $release = $this->ticketLockService->release($complaint, 'admin_rejected', $actor->id);

            $complaint->forceFill([
                'status' => 'closed',
                'resolution_type' => 'rejected',
                'resolution_note' => $reason,
                'resolved_by' => (string) $actor->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ])->save();
            $this->storeLockService->release($complaint, 'admin_rejected', $actor);
            $this->log($complaint, $actor, 'rejected', $fromStatus, 'closed', [
                'reason' => $reason,
                'released_ticket_count' => $release['released_count'],
            ]);
            $this->broadcastStatusUpdated($complaint);

            return $complaint->fresh();
        });
    }

    public function lockStore(Complaint $complaint, User $actor, string $reason): Complaint
    {
        return DB::transaction(function () use ($complaint, $actor, $reason): Complaint {
            $complaint = $this->lockComplaint($complaint);
            $this->assertNotTerminal($complaint);
            $fromStatus = (string) $complaint->status;

            $this->storeLockService->lock($complaint, $actor);
            $complaint->forceFill([
                'status' => 'awaiting_store_action',
                'resolution_type' => 'store_locked',
                'resolution_note' => $reason,
                'updated_at' => now(),
            ])->save();
            $this->log($complaint, $actor, 'store_locked', $fromStatus, 'awaiting_store_action', [
                'reason' => $reason,
            ]);
            $this->broadcastStatusUpdated($complaint);

            return $complaint->fresh();
        });
    }

    public function forceRefund(Complaint $complaint, User $actor, string $reason): OrderReturn
    {
        return DB::transaction(function () use ($complaint, $actor, $reason): OrderReturn {
            $complaint = $this->lockComplaint($complaint);
            if ((string) $complaint->status === 'closed' && (string) $complaint->resolution_type === 'forced_refund') {
                $existing = OrderReturn::query()->where('complaint_id', (string) $complaint->id)->first();
                if ($existing) {
                    return $existing->load(['items.orderItem', 'order']);
                }
            }
            $this->assertNotTerminal($complaint);
            $fromStatus = (string) $complaint->status;

            $return = $this->orderReturnService->createComplaintReturn(
                $complaint,
                $actor,
                OrderReturn::STATUS_REFUND_PROCESSING
            );
            if ($return->status !== OrderReturn::STATUS_REFUNDED) {
                if (! in_array($return->status, [
                    OrderReturn::STATUS_PENDING,
                    OrderReturn::STATUS_APPROVED,
                    OrderReturn::STATUS_RETURNING,
                    OrderReturn::STATUS_RECEIVED,
                    OrderReturn::STATUS_REFUND_PROCESSING,
                    OrderReturn::STATUS_REJECTED,
                ], true)) {
                    throw new RuntimeException('COMPLAINT_RETURN_STATE_CONFLICT');
                }
                $return->forceFill(['status' => OrderReturn::STATUS_REFUND_PROCESSING])->save();
                $return = $this->orderReturnService->processRefund($return->fresh(), $actor, true);
            }

            $ticketRevoke = $this->ticketLockService->revokeForRefund($complaint, $actor->id);
            $this->storeLockService->release($complaint, 'refund_processed', $actor);

            $complaint->forceFill([
                'status' => 'closed',
                'resolution_type' => 'forced_refund',
                'resolution_note' => $reason,
                'resolved_by' => (string) $actor->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ])->save();
            $this->log($complaint, $actor, 'forced_refund', $fromStatus, 'closed', [
                'reason' => $reason,
                'order_return_id' => (string) $return->id,
                'refund_amount' => (float) $return->refund_amount_final,
                'revoked_ticket_count' => (int) ($ticketRevoke['revoked_count'] ?? 0),
                'revoked_ticket_ids' => $ticketRevoke['ticket_ids'] ?? [],
            ]);
            $this->broadcastStatusUpdated($complaint);

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function resolveManual(Complaint $complaint, User $actor, string $note): Complaint
    {
        return DB::transaction(function () use ($complaint, $actor, $note): Complaint {
            $complaint = $this->lockComplaint($complaint);
            $this->assertNotTerminal($complaint);
            $fromStatus = (string) $complaint->status;
            $release = $this->ticketLockService->release($complaint, 'manual_resolution', $actor->id);
            $this->storeLockService->release($complaint, 'manual_resolution', $actor);

            $complaint->forceFill([
                'status' => 'closed',
                'resolution_type' => 'manual',
                'resolution_note' => $note,
                'resolved_by' => (string) $actor->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ])->save();
            $this->log($complaint, $actor, 'manual_resolution', $fromStatus, 'closed', [
                'note' => $note,
                'released_ticket_count' => $release['released_count'],
            ]);
            $this->broadcastStatusUpdated($complaint);

            return $complaint->fresh();
        });
    }

    public function acknowledge(Complaint $complaint, User $actor): Complaint
    {
        return DB::transaction(function () use ($complaint, $actor): Complaint {
            $complaint = $this->lockComplaint($complaint);
            $this->assertNotTerminal($complaint);
            $fromStatus = (string) $complaint->status;
            
            $release = $this->ticketLockService->release($complaint, 'acknowledged', $actor->id);
            $this->storeLockService->release($complaint, 'acknowledged', $actor);

            $complaint->forceFill([
                'status' => 'closed',
                'resolution_type' => 'acknowledged',
                'resolution_note' => 'Đã tiếp nhận khiếu nại.',
                'resolved_by' => (string) $actor->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ])->save();

            $this->log($complaint, $actor, 'acknowledged', $fromStatus, 'closed', [
                'released_ticket_count' => $release['released_count'],
            ]);
            $this->broadcastStatusUpdated($complaint);

            return $complaint->fresh();
        });
    }

    public function acceptStoreAction(Complaint $complaint, User $storeUser, string $storeId): OrderReturn
    {
        return DB::transaction(function () use ($complaint, $storeUser, $storeId): OrderReturn {
            $complaint = $this->lockComplaint($complaint);
            if ((string) $complaint->store_id !== $storeId) {
                throw new RuntimeException('COMPLAINT_STORE_FORBIDDEN');
            }

            if ((string) $complaint->resolution_type === 'store_locked') {
                return $this->forceRefund($complaint, $storeUser, 'Store chấp nhận hoàn tiền theo quyết định khiếu nại.');
            }

            if ((string) $complaint->resolution_type !== 'return_requested') {
                throw new RuntimeException('COMPLAINT_ACTION_NOT_AVAILABLE');
            }

            $return = OrderReturn::query()->where('complaint_id', (string) $complaint->id)->lockForUpdate()->first();
            if (! $return) {
                throw new RuntimeException('COMPLAINT_RETURN_NOT_FOUND');
            }
            if (in_array($return->status, [OrderReturn::STATUS_APPROVED, OrderReturn::STATUS_RETURNING], true)) {
                return $return->load(['items.orderItem', 'order']);
            }

            $return = $this->orderReturnService->approveReturn($storeUser, $return, null, null, $storeId);

            return $return;
        });
    }

    public function handleStoreReturnResponse(OrderReturn $return, User $actor, string $action, string $reason = ''): void
    {
        $action = strtolower(trim($action));
        if (! in_array($action, ['approved', 'rejected'], true)) {
            throw new RuntimeException('COMPLAINT_RETURN_RESPONSE_ACTION_INVALID');
        }

        if (! $return->complaint_id) {
            return;
        }

        DB::transaction(function () use ($return, $actor, $action, $reason) {
            $complaint = Complaint::query()->whereKey((string) $return->complaint_id)->lockForUpdate()->first();
            if (! $complaint) {
                return;
            }

            $fromStatus = (string) $complaint->status;
            if (in_array(strtolower($fromStatus), ['closed', 'cancelled'], true)) {
                return;
            }

            $resolutionType = $action === 'approved' ? 'store_approved_return' : 'store_rejected_return';
            $note = $action === 'approved'
                ? 'Store đã chấp nhận yêu cầu hoàn hàng.'
                : 'Store đã từ chối yêu cầu hoàn hàng.' . ($reason !== '' ? ' Lý do: ' . $reason : '');

            $complaint->forceFill([
                'status' => 'processing',
                'resolution_type' => $resolutionType,
                'resolution_note' => $note,
                'updated_at' => now(),
            ])->save();

            $this->log($complaint, $actor, $resolutionType, $fromStatus, 'processing', [
                'order_return_id' => (string) $return->id,
                'reason' => $reason,
            ]);

            if ($complaint->assigned_to) {
                try {
                    app(\App\Services\SpecialistNotificationService::class)->create([
                        'specialist_id' => $complaint->assigned_to,
                        'type' => 'complaint_return_update',
                        'title' => 'Cập nhật hoàn hàng',
                        'message' => $note . " (Mã: " . ($complaint->complaint_code ?? $complaint->code) . ")",
                        'data' => [
                            'complaint_id' => (string) $complaint->id,
                            'order_return_id' => (string) $return->id,
                            'action' => $action,
                            'resolution_type' => $resolutionType,
                        ],
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to notify specialist about store return response.', [
                        'complaint_id' => $complaint->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->broadcastStatusUpdated($complaint);
        });
    }

    private function lockComplaint(Complaint $complaint): Complaint
    {
        return Complaint::query()->whereKey((string) $complaint->id)->lockForUpdate()->firstOrFail();
    }

    private function assertNotTerminal(Complaint $complaint): void
    {
        if (in_array(strtolower((string) $complaint->status), ['closed', 'cancelled'], true)) {
            throw new RuntimeException('COMPLAINT_STATE_CONFLICT');
        }
    }

    private function log(
        Complaint $complaint,
        User $actor,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        array $metadata = []
    ): void {
        if (! Schema::hasTable('complaint_action_logs')) {
            return;
        }

        DB::table('complaint_action_logs')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'complaint_id' => (string) $complaint->id,
            'actor_id' => (string) $actor->id,
            'actor_role' => (string) ($actor->role ?? ''),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'idempotency_key' => 'complaint:' . $complaint->id . ':' . $action,
            'created_at' => now(),
        ]);
    }

    private function broadcastStatusUpdated(Complaint $complaint): void
    {
        try {
            event(new \App\Events\ComplaintStatusUpdated((string) $complaint->id, [
                'event' => 'complaint.status.updated',
                'id' => (string) $complaint->id,
                'code' => $complaint->complaint_code ?? $complaint->code ?? '',
                'status' => $complaint->status,
                'resolution_type' => $complaint->resolution_type,
                'resolution_note' => $complaint->resolution_note,
                'updated_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to broadcast complaint status update.', [
                'id' => $complaint->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
