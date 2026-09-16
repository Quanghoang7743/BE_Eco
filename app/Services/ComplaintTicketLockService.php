<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ComplaintTicketLockService
{
    public function lockAvailableOrderRewardTickets(Complaint $complaint, Order $order, string $userId): array
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('complaint_ticket_locks')) {
            return [];
        }

        $query = DB::table('tickets as t')
            ->whereIn('t.status', ['AVAILABLE', 'available']);

        if (Schema::hasColumn('tickets', 'source_id')) {
            $query->where('t.source_id', (string) $order->id);
            if (Schema::hasColumn('tickets', 'source_type')) {
                $query->whereRaw("UPPER(COALESCE(t.source_type, '')) = ?", ['ORDER_REWARD']);
            }
        } elseif (Schema::hasTable('order_tickets') && Schema::hasColumn('order_tickets', 'order_id')) {
            $query->join('order_tickets as ot', 'ot.ticket_id', '=', 't.id')
                ->where('ot.order_id', (string) $order->id);
        } else {
            return [];
        }

        if (Schema::hasColumn('tickets', 'user_id')) {
            $query->where('t.user_id', $userId);
        }

        $tickets = $query->lockForUpdate()->get(['t.id', 't.status']);
        if ($tickets->isEmpty()) {
            return [];
        }

        $now = now();
        $ticketIds = $tickets->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        $rows = $tickets->map(fn ($ticket): array => [
            'id' => (string) Str::uuid(),
            'complaint_id' => (string) $complaint->id,
            'ticket_id' => (string) $ticket->id,
            'locked_from_status' => (string) ($ticket->status ?? 'AVAILABLE'),
            'created_at' => $now,
            'released_at' => null,
            'release_reason' => null,
        ])->all();

        DB::table('complaint_ticket_locks')->insert($rows);

        $payload = ['status' => 'LOCKED'];
        if (Schema::hasColumn('tickets', 'locked_at')) {
            $payload['locked_at'] = $now;
        }
        if (Schema::hasColumn('tickets', 'locked_by')) {
            $payload['locked_by'] = 'complaint:' . $complaint->id;
        }
        if (Schema::hasColumn('tickets', 'lock_expires_at')) {
            $payload['lock_expires_at'] = null;
        }
        if (Schema::hasColumn('tickets', 'updated_at')) {
            $payload['updated_at'] = $now;
        }

        DB::table('tickets')
            ->whereIn('id', $ticketIds)
            ->whereIn('status', ['AVAILABLE', 'available'])
            ->update($payload);

        // Update user balance and record ledger entries
        $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
        if (!$balance) {
            $this->createUserBalance($userId, 0, $now);
            $balanceBefore = 0;
        } else {
            $balanceBefore = (int) $balance->available_balance;
            DB::table('user_balances')
                ->where('user_id', $userId)
                ->update([
                    'available_balance' => max(0, $balanceBefore - count($ticketIds)),
                    'last_updated' => $now,
                    'version' => DB::raw('version + 1'),
                ]);
        }

        $ledgerService = app(TicketLedgerService::class);
        $pricingService = app(TicketPricingService::class);
        $balanceCurrent = $balanceBefore;

        foreach ($ticketIds as $ticketId) {
            $ticketObj = DB::table('tickets')->where('id', $ticketId)->first();
            $ticketTypeCode = $ticketObj ? strtoupper(trim((string) ($ticketObj->ticket_type_code ?? ''))) : '';
            $resolvedType = $pricingService->resolveTicketType($ticketTypeCode);
            $ticketTypeCode = strtoupper(trim((string) ($resolvedType['code'] ?? $pricingService->getDefaultTicketTypeCode())));
            $ticketTypeName = trim((string) ($resolvedType['name'] ?? $pricingService->getDefaultTicketTypeName()));

            $ledgerService->record([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'action_type' => 'LOCKED',
                'amount_change' => -1,
                'balance_before' => $balanceCurrent,
                'balance_after' => $balanceCurrent - 1,
                'reference_id' => (string) $complaint->id,
                'reference_type' => 'COMPLAINT',
                'ticket_type_code' => $ticketTypeCode,
                'ticket_type_name' => $ticketTypeName,
                'metadata' => [
                    'source_type' => 'COMPLAINT',
                    'order_id' => (string) $order->id,
                    'order_code' => $order->order_code ?? null,
                    'complaint_id' => (string) $complaint->id,
                    'ticket_type_code' => $ticketTypeCode,
                    'ticket_type_name' => $ticketTypeName,
                ],
                'created_at' => $now,
            ]);
            $balanceCurrent--;
        }

        return $ticketIds;
    }

    public function release(Complaint|string $complaint, string $reason, string|int|null $actorId = null): array
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('complaint_ticket_locks')) {
            return ['released_count' => 0, 'ticket_ids' => []];
        }

        $complaintId = (string) ($complaint instanceof Complaint ? $complaint->id : $complaint);
        $lockRows = DB::table('complaint_ticket_locks')
            ->where('complaint_id', $complaintId)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->get();

        if ($lockRows->isEmpty()) {
            return ['released_count' => 0, 'ticket_ids' => []];
        }

        $ticketIds = $lockRows->pluck('ticket_id')->map(fn ($id): string => (string) $id)->values()->all();
        $tickets = DB::table('tickets')->whereIn('id', $ticketIds)->lockForUpdate()->get()->keyBy('id');
        $releasedIds = [];
        $skippedIds = [];
        $now = now();

        foreach ($lockRows as $lockRow) {
            $ticketId = (string) $lockRow->ticket_id;
            $ticket = $tickets->get($ticketId);
            if (! $ticket) {
                $skippedIds[] = $ticketId;
                continue;
            }

            $status = strtoupper(trim((string) ($ticket->status ?? '')));
            $lockedBy = (string) ($ticket->locked_by ?? '');
            if ($status !== 'LOCKED' || (Schema::hasColumn('tickets', 'locked_by') && $lockedBy !== 'complaint:' . $complaintId)) {
                $skippedIds[] = $ticketId;
                continue;
            }

            $expired = ! empty($ticket->expired_at) && now()->greaterThanOrEqualTo($ticket->expired_at);
            $restoreStatus = strtoupper(trim((string) ($lockRow->locked_from_status ?? 'AVAILABLE')));
            if ($restoreStatus !== 'AVAILABLE') {
                $restoreStatus = 'AVAILABLE';
            }

            $payload = ['status' => $expired ? 'EXPIRED' : $restoreStatus];
            foreach (['locked_at', 'locked_by', 'lock_expires_at'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $payload[$column] = null;
                }
            }
            if (Schema::hasColumn('tickets', 'updated_at')) {
                $payload['updated_at'] = $now;
            }

            DB::table('tickets')->where('id', $ticketId)->update($payload);
            $releasedIds[] = $ticketId;
        }

        $allProcessedIds = array_merge($releasedIds, $skippedIds);
        if ($allProcessedIds !== []) {
            $releasePayload = [
                'released_at' => $now,
                'release_reason' => $reason,
            ];
            if (Schema::hasColumn('complaint_ticket_locks', 'released_by')) {
                $releasePayload['released_by'] = $actorId !== null ? (string) $actorId : null;
            }

            DB::table('complaint_ticket_locks')
                ->where('complaint_id', $complaintId)
                ->whereNull('released_at')
                ->whereIn('ticket_id', $allProcessedIds)
                ->update($releasePayload);
        }

        // Update user balance and record ledger entries for released tickets
        if (!empty($releasedIds)) {
            $firstTicket = $tickets->first();
            $userId = $firstTicket ? $firstTicket->user_id : null;
            if ($userId) {
                $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
                if (!$balance) {
                    $this->createUserBalance((string) $userId, count($releasedIds), $now);
                    $balanceBefore = 0;
                } else {
                    $balanceBefore = (int) $balance->available_balance;
                    DB::table('user_balances')
                        ->where('user_id', $userId)
                        ->update([
                            'available_balance' => $balanceBefore + count($releasedIds),
                            'last_updated' => $now,
                            'version' => DB::raw('version + 1'),
                        ]);
                }

                $ledgerService = app(TicketLedgerService::class);
                $pricingService = app(TicketPricingService::class);
                $balanceCurrent = $balanceBefore;

                $orderCode = null;
                $complaintModel = Complaint::find($complaintId);
                if ($complaintModel && $complaintModel->order) {
                    $orderCode = $complaintModel->order->order_code;
                }

                foreach ($releasedIds as $ticketId) {
                    $ticketObj = $tickets->get($ticketId);
                    $ticketTypeCode = $ticketObj ? strtoupper(trim((string) ($ticketObj->ticket_type_code ?? ''))) : '';
                    $resolvedType = $pricingService->resolveTicketType($ticketTypeCode);
                    $ticketTypeCode = strtoupper(trim((string) ($resolvedType['code'] ?? $pricingService->getDefaultTicketTypeCode())));
                    $ticketTypeName = trim((string) ($resolvedType['name'] ?? $pricingService->getDefaultTicketTypeName()));

                    $ledgerService->record([
                        'ticket_id' => $ticketId,
                        'user_id' => $userId,
                        'action_type' => 'RELEASED',
                        'amount_change' => 1,
                        'balance_before' => $balanceCurrent,
                        'balance_after' => $balanceCurrent + 1,
                        'reference_id' => $complaintId,
                        'reference_type' => 'COMPLAINT',
                        'ticket_type_code' => $ticketTypeCode,
                        'ticket_type_name' => $ticketTypeName,
                        'metadata' => [
                            'source_type' => 'COMPLAINT',
                            'complaint_id' => $complaintId,
                            'order_code' => $orderCode,
                            'ticket_type_code' => $ticketTypeCode,
                            'ticket_type_name' => $ticketTypeName,
                        ],
                        'created_at' => $now,
                    ]);
                    $balanceCurrent++;
                }
            }
        }

        return ['released_count' => count($releasedIds), 'ticket_ids' => $releasedIds];
    }

    public function revokeForRefund(Complaint|string $complaint, string|int|null $actorId = null): array
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('complaint_ticket_locks')) {
            return ['revoked_count' => 0, 'ticket_ids' => []];
        }

        $complaintId = (string) ($complaint instanceof Complaint ? $complaint->id : $complaint);
        $lockRows = DB::table('complaint_ticket_locks')
            ->where('complaint_id', $complaintId)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->get();
        $ticketIds = $lockRows->pluck('ticket_id')->map(fn ($id): string => (string) $id)->values()->all();
        if ($ticketIds === []) {
            return ['revoked_count' => 0, 'ticket_ids' => []];
        }

        $query = DB::table('tickets')
            ->whereIn('id', $ticketIds)
            ->whereIn('status', ['LOCKED', 'locked']);
        if (Schema::hasColumn('tickets', 'locked_by')) {
            $query->where('locked_by', 'complaint:' . $complaintId);
        }
        $revokedIds = $query->lockForUpdate()->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        if ($revokedIds === []) {
            return ['revoked_count' => 0, 'ticket_ids' => []];
        }

        $payload = ['status' => 'CANCELLED'];
        foreach (['locked_at', 'locked_by', 'lock_expires_at'] as $column) {
            if (Schema::hasColumn('tickets', $column)) {
                $payload[$column] = null;
            }
        }
        if (Schema::hasColumn('tickets', 'updated_at')) {
            $payload['updated_at'] = now();
        }
        DB::table('tickets')->whereIn('id', $revokedIds)->update($payload);

        $releasePayload = [
            'released_at' => now(),
            'release_reason' => 'refund_processed',
        ];
        if (Schema::hasColumn('complaint_ticket_locks', 'released_by')) {
            $releasePayload['released_by'] = $actorId !== null ? (string) $actorId : null;
        }
        DB::table('complaint_ticket_locks')
            ->where('complaint_id', $complaintId)
            ->whereNull('released_at')
            ->whereIn('ticket_id', $revokedIds)
            ->update($releasePayload);

        // Update the existing LOCKED ledger entries to REVOKED for this complaint
        if (Schema::hasTable('ticket_ledger')) {
            DB::table('ticket_ledger')
                ->where('reference_type', 'COMPLAINT')
                ->where('reference_id', $complaintId)
                ->where('action_type', 'LOCKED')
                ->update([
                    'action_type' => 'REVOKED',
                ]);
        }

        return ['revoked_count' => count($revokedIds), 'ticket_ids' => $revokedIds];
    }

    private function createUserBalance(string $userId, int $availableBalance, mixed $now): void
    {
        $payload = [
            'user_id' => $userId,
        ];

        if (Schema::hasColumn('user_balances', 'id')) {
            $payload['id'] = (string) Str::uuid();
        }

        $defaults = [
            'available_balance' => $availableBalance,
            'locked_balance' => 0,
            'used_balance' => 0,
            'expired_balance' => 0,
            'total_lifetime_purchased' => 0,
            'total_lifetime_used' => 0,
            'last_updated' => $now,
            'version' => 0,
        ];

        foreach ($defaults as $column => $value) {
            if (Schema::hasColumn('user_balances', $column)) {
                $payload[$column] = $value;
            }
        }

        DB::table('user_balances')->insert($payload);
    }
}
