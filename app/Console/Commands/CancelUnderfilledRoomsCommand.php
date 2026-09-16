<?php

namespace App\Console\Commands;

use App\Events\DrawRoomUpdated;
use App\Models\Room;
use App\Models\RoomTicket;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CancelUnderfilledRoomsCommand extends Command
{
    protected $signature = 'ecoluck:cancel-underfilled-rooms
        {--date= : Ngày mục tiêu (Y-m-d), chỉ dùng cho chạy thủ công}
        {--reason= : Lý do hủy tùy chỉnh}';

    protected $description = 'Tự động hủy phòng quá giờ đóng nhưng chưa đủ vé, sau đó hoàn vé';

    public function handle(): int
    {
        $runId = (string) Str::uuid();
        $timezone = 'Asia/Ho_Chi_Minh';
        $now = Carbon::now($timezone);
        $targetDate = $this->resolveTargetDate($timezone);

        if ($this->option('date') !== null && $targetDate === null) {
            Log::warning('cancel_underfilled_rooms.invalid_target_date', [
                'run_id' => $runId,
                'timezone' => $timezone,
                'date_option' => $this->option('date'),
            ]);
            return self::FAILURE;
        }

        $defaultReason = 'Phòng quá giờ đóng nhưng chưa đủ số vé tối thiểu, hệ thống tự động hủy và hoàn vé.';
        $baseReason = trim((string) ($this->option('reason') ?: $defaultReason));

        Log::info('cancel_underfilled_rooms.started', [
            'run_id' => $runId,
            'target_date' => $targetDate,
            'now' => $now->toIso8601String(),
            'timezone' => $timezone,
            'date_option' => $this->option('date'),
        ]);

        $candidateQuery = Room::query()
            ->whereIn(DB::raw('LOWER(status)'), ['waiting', 'filling'])
            ->whereNotNull('close_at')
            ->where('close_at', '<=', $now)
            ->orderBy('close_at')
            ->orderBy('room_number');

        if ($targetDate !== null) {
            $candidateQuery->whereDate('room_date', $targetDate);
        }

        $candidateRooms = $candidateQuery->get();

        if ($candidateRooms->isEmpty()) {
            $this->info('Không có phòng nào quá giờ đóng cần hủy.');
            Log::info('cancel_underfilled_rooms.no_candidates', [
                'run_id' => $runId,
                'target_date' => $targetDate,
            ]);
            return self::SUCCESS;
        }

        $summary = [
            'checked_rooms' => $candidateRooms->count(),
            'cancelled_rooms' => 0,
            'refunded_tickets' => 0,
            'affected_users' => 0,
            'skipped_rooms' => 0,
        ];

        foreach ($candidateRooms as $room) {
            try {
                $result = DB::transaction(function () use ($room, $baseReason, $timezone) {
                    $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->first();

                    if (! $lockedRoom) {
                        return [
                            'status' => 'skipped',
                            'reason' => 'room_not_found',
                        ];
                    }

                    $status = strtolower((string) $lockedRoom->status);
                    if (! in_array($status, ['waiting', 'filling'], true)) {
                        return [
                            'status' => 'skipped',
                            'reason' => 'room_status_changed',
                            'room_code' => (string) ($lockedRoom->room_code ?? $lockedRoom->id),
                        ];
                    }

                    if (! empty($lockedRoom->draw_started_at)) {
                        return [
                            'status' => 'skipped',
                            'reason' => 'room_already_started',
                            'room_code' => (string) ($lockedRoom->room_code ?? $lockedRoom->id),
                        ];
                    }

                    $requiredVotes = max(1, (int) ($lockedRoom->required_votes ?: 1000));
                    $actualVotes = (int) RoomTicket::query()
                        ->where('room_id', $lockedRoom->id)
                        ->where('is_refunded', false)
                        ->count();

                    if ($actualVotes >= $requiredVotes) {
                        return [
                            'status' => 'skipped',
                            'reason' => 'room_already_full',
                            'room_code' => (string) ($lockedRoom->room_code ?? $lockedRoom->id),
                        ];
                    }

                    $now = Carbon::now($timezone);
                    $roomCode = (string) ($lockedRoom->room_code ?? $lockedRoom->id);
                    $reason = $baseReason
                        . ' (room_code=' . $roomCode
                        . ', current_votes=' . $actualVotes
                        . ', required_votes=' . $requiredVotes
                        . ', close_at=' . (string) optional($lockedRoom->close_at)->toDateTimeString() . ').';

                    $refundSummary = $this->refundRoomTickets($lockedRoom, $reason, $requiredVotes, $now);

                    $lockedRoom->status = 'CANCELED';
                    $lockedRoom->current_votes = 0;
                    $lockedRoom->draw_started_at = null;
                    $lockedRoom->draw_completed_at = null;
                    $lockedRoom->save();

                    return [
                        'status' => 'cancelled',
                        'room_id' => (string) $lockedRoom->id,
                        'room_code' => $roomCode,
                        'refunded_tickets' => (int) ($refundSummary['refunded_tickets'] ?? 0),
                        'affected_users' => (int) ($refundSummary['affected_users'] ?? 0),
                    ];
                }, 3);
            } catch (\Throwable $e) {
                report($e);
                $summary['skipped_rooms']++;
                Log::error('cancel_underfilled_rooms.room_failed', [
                    'run_id' => $runId,
                    'room_id' => (string) ($room->id ?? ''),
                    'room_code' => (string) ($room->room_code ?? ''),
                    'error' => $e->getMessage(),
                ]);
                $this->error('Không thể xử lý phòng ' . (string) ($room->room_code ?? $room->id) . ': ' . $e->getMessage());
                continue;
            }

            if (($result['status'] ?? 'skipped') !== 'cancelled') {
                $summary['skipped_rooms']++;
                Log::info('cancel_underfilled_rooms.room_skipped', [
                    'run_id' => $runId,
                    'room_id' => (string) ($room->id ?? ''),
                    'room_code' => (string) ($result['room_code'] ?? ($room->room_code ?? '')),
                    'reason' => (string) ($result['reason'] ?? 'unknown'),
                ]);
                continue;
            }

            $summary['cancelled_rooms']++;
            $summary['refunded_tickets'] += (int) ($result['refunded_tickets'] ?? 0);
            $summary['affected_users'] += (int) ($result['affected_users'] ?? 0);

            $this->broadcastCancelledRoom((string) $result['room_id']);

            $this->info(
                'Đã hủy phòng ' . (string) ($result['room_code'] ?? '-')
                . ', hoan ' . (int) ($result['refunded_tickets'] ?? 0)
                . ' vé cho ' . (int) ($result['affected_users'] ?? 0)
                . ' người dùng.'
            );

            Log::info('cancel_underfilled_rooms.room_cancelled', [
                'run_id' => $runId,
                'room_id' => (string) ($result['room_id'] ?? ''),
                'room_code' => (string) ($result['room_code'] ?? ''),
                'refunded_tickets' => (int) ($result['refunded_tickets'] ?? 0),
                'affected_users' => (int) ($result['affected_users'] ?? 0),
            ]);
        }

        $this->info(sprintf(
            'Hoàn tất: checked=%d, cancelled=%d, skipped=%d, refunded_tickets=%d, affected_users=%d',
            $summary['checked_rooms'],
            $summary['cancelled_rooms'],
            $summary['skipped_rooms'],
            $summary['refunded_tickets'],
            $summary['affected_users']
        ));

        Log::info('cancel_underfilled_rooms.finished', [
            'run_id' => $runId,
            'target_date' => $targetDate,
            'summary' => $summary,
        ]);

        return self::SUCCESS;
    }

    private function resolveTargetDate(string $timezone): ?string
    {
        $inputDate = $this->option('date');

        if ($inputDate === null || trim((string) $inputDate) === '') {
            return null;
        }

        $inputDate = trim((string) $inputDate);

        try {
            $date = Carbon::createFromFormat('Y-m-d', $inputDate, $timezone);
            if ($date->format('Y-m-d') !== $inputDate) {
                throw new \InvalidArgumentException('Invalid date format');
            }

            return $date->toDateString();
        } catch (\Throwable $e) {
            $this->error('Giá trị --date không hợp lệ. Định dạng đúng: Y-m-d');
            return null;
        }
    }

    private function refundRoomTickets(Room $room, string $reason, int $requiredVotes, Carbon $now): array
    {
        $roomTickets = RoomTicket::query()
            ->where('room_id', $room->id)
            ->where('is_refunded', false)
            ->get(['id', 'ticket_id', 'user_id']);

        if ($roomTickets->isEmpty()) {
            return [
                'refunded_tickets' => 0,
                'affected_users' => 0,
            ];
        }

        $roomTicketIds = $roomTickets
            ->pluck('id')
            ->filter()
            ->map(static fn ($id) => (string) $id)
            ->values()
            ->all();

        $ticketIds = $roomTickets
            ->pluck('ticket_id')
            ->filter()
            ->map(static fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        $ticketOwnerMap = [];
        if (! empty($ticketIds)) {
            $ticketOwnerMap = DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->pluck('user_id', 'id')
                ->toArray();

            DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->update([
                    'status' => 'AVAILABLE',
                    'used_in_room_id' => null,
                    'used_at' => null,
                    'updated_at' => $now,
                ]);
        }

        if (! empty($roomTicketIds)) {
            DB::table('room_tickets')
                ->whereIn('id', $roomTicketIds)
                ->update(['is_refunded' => true]);
        }

        $groupedByUser = [];

        foreach ($roomTickets as $roomTicket) {
            $ticketId = $roomTicket->ticket_id ? (string) $roomTicket->ticket_id : null;
            $userId = $roomTicket->user_id ? (string) $roomTicket->user_id : '';

            if ($userId === '' && $ticketId !== null) {
                $userId = (string) ($ticketOwnerMap[$ticketId] ?? '');
            }

            if ($userId === '') {
                continue;
            }

            if (! isset($groupedByUser[$userId])) {
                $groupedByUser[$userId] = [
                    'count' => 0,
                    'ticket_ids' => [],
                ];
            }

            $groupedByUser[$userId]['count']++;

            if ($ticketId !== null && $ticketId !== '') {
                $groupedByUser[$userId]['ticket_ids'][] = $ticketId;
            }
        }

        $hasRefundsTable = Schema::hasTable('refunds');
        $hasLedgerTable = Schema::hasTable('ticket_ledger');
        $hasNotificationsTable = Schema::hasTable('notifications');
        $hasNotificationsOrderId = $hasNotificationsTable && Schema::hasColumn('notifications', 'order_id');
        $hasNotificationsUserId = $hasNotificationsTable && Schema::hasColumn('notifications', 'user_id');
        $hasNotificationsAudienceScope = $hasNotificationsTable && Schema::hasColumn('notifications', 'audience_scope');
        $hasNotificationsStoreId = $hasNotificationsTable && Schema::hasColumn('notifications', 'store_id');
        $hasNotificationsType = $hasNotificationsTable && Schema::hasColumn('notifications', 'type');
        $hasNotificationsTitle = $hasNotificationsTable && Schema::hasColumn('notifications', 'title');
        $hasNotificationsCreatedAt = $hasNotificationsTable && Schema::hasColumn('notifications', 'created_at');
        $hasNotificationsUpdatedAt = $hasNotificationsTable && Schema::hasColumn('notifications', 'updated_at');

        $totalRefunded = 0;
        $affectedUsers = 0;

        foreach ($groupedByUser as $userId => $userData) {
            $ticketCount = (int) ($userData['count'] ?? 0);
            if ($ticketCount < 1) {
                continue;
            }

            $ticketIdList = array_values(array_unique((array) ($userData['ticket_ids'] ?? [])));
            $firstTicketId = (string) ($ticketIdList[0] ?? '');

            $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $balance) {
                DB::table('user_balances')->insert([
                    'user_id' => $userId,
                    'available_balance' => 0,
                    'locked_balance' => 0,
                    'used_balance' => 0,
                    'expired_balance' => 0,
                    'total_lifetime_purchased' => 0,
                    'total_lifetime_used' => 0,
                    'last_updated' => $now,
                    'version' => 0,
                ]);

                $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
            }

            $balanceBefore = (int) ($balance->available_balance ?? 0);
            $balanceAfter = $balanceBefore + $ticketCount;

            DB::table('user_balances')
                ->where('user_id', $userId)
                ->update([
                    'available_balance' => $balanceAfter,
                    'used_balance' => max(0, (int) ($balance->used_balance ?? 0) - $ticketCount),
                    'last_updated' => $now,
                    'version' => (int) ($balance->version ?? 0) + 1,
                ]);

            $refundId = null;

            if ($hasRefundsTable) {
                $refundId = (string) Str::uuid();

                DB::table('refunds')->insert([
                    'id' => $refundId,
                    'refund_code' => $this->generateRefundCode($now),
                    'user_id' => $userId,
                    'ticket_id' => $firstTicketId !== '' ? $firstTicketId : null,
                    'refund_type' => 'ticket',
                    'reason' => $reason,
                    'reason_code' => 'ROOM_UNDERFILLED_MIDNIGHT',
                    'old_status' => 'USED_IN_ROOM',
                    'new_status' => 'REFUNDED',
                    'source_type' => 'room_auto_cancellation',
                    'source_id' => $room->id,
                    'processed_by' => null,
                    'processed_at' => $now,
                    'metadata' => json_encode([
                        'room_id' => (string) $room->id,
                        'room_code' => (string) ($room->room_code ?? ''),
                        'ticket_count' => $ticketCount,
                        'required_votes' => $requiredVotes,
                        'cancelled_at' => $now->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                ]);
            }

            if ($hasLedgerTable && ! empty($ticketIdList)) {
                $runningBefore = $balanceBefore;

                foreach ($ticketIdList as $ticketId) {
                    DB::table('ticket_ledger')->insert([
                        'id' => (string) Str::uuid(),
                        'ticket_id' => $ticketId,
                        'user_id' => $userId,
                        'action_type' => 'REFUND',
                        'amount_change' => 1,
                        'balance_before' => $runningBefore,
                        'balance_after' => $runningBefore + 1,
                        'reference_id' => $refundId ?: (string) $room->id,
                        'reference_type' => 'ROOM_CANCELLATION',
                        'metadata' => json_encode([
                            'room_id' => (string) $room->id,
                            'room_code' => (string) ($room->room_code ?? ''),
                            'reason' => $reason,
                            'is_auto_cancel_job' => true,
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                    ]);

                    $runningBefore++;
                }
            }

            if ($hasNotificationsTable) {
                $message = 'Phòng ' . (string) ($room->room_code ?? $room->id)
                    . ' chưa đủ ' . $requiredVotes . ' vé lúc 00:00 nên đã hủy. '
                    . 'Hệ thống đã hoàn ' . $ticketCount . ' vé cho user ' . $userId . '.';

                $notificationPayload = [
                    'message' => $message,
                ];

                if ($hasNotificationsOrderId) {
                    $notificationPayload['order_id'] = null;
                }

                if ($hasNotificationsUserId) {
                    $notificationPayload['user_id'] = $userId;
                }

                if ($hasNotificationsStoreId) {
                    $notificationPayload['store_id'] = (string) $room->store_id;
                }

                if ($hasNotificationsAudienceScope) {
                    $notificationPayload['audience_scope'] = $hasNotificationsUserId ? 'user_private' : 'admin_shared';
                }

                if ($hasNotificationsType) {
                    $notificationPayload['type'] = 'ticket';
                }

                if ($hasNotificationsTitle) {
                    $notificationPayload['title'] = 'Hoàn vé do hủy phòng';
                }

                if ($hasNotificationsCreatedAt) {
                    $notificationPayload['created_at'] = $now;
                }

                if ($hasNotificationsUpdatedAt) {
                    $notificationPayload['updated_at'] = $now;
                }

                DB::table('notifications')->insert($notificationPayload);
            }

            $totalRefunded += $ticketCount;
            $affectedUsers++;
        }

        return [
            'refunded_tickets' => $totalRefunded,
            'affected_users' => $affectedUsers,
        ];
    }

    private function broadcastCancelledRoom(string $roomId): void
    {
        $room = Room::query()->find($roomId);

        if (! $room) {
            return;
        }

        try {
            event(new DrawRoomUpdated([
                'id' => (string) $room->id,
                'name' => sprintf('Phòng %02d hôm nay', (int) $room->room_number),
                'description' => 'Phòng quay hôm nay, mỗi phòng cần 1000 vé để mở kết quả.',
                'maxTickets' => max(1, (int) ($room->required_votes ?: 1000)),
                'currentTickets' => (int) ($room->current_votes ?? 0),
                'prizeDescription' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                'prizeValue' => (float) ($room->prize_value ?: 0),
                'secondaryPrizes' => is_array($room->secondary_prizes ?? null) ? $room->secondary_prizes : [],
                'status' => 'cancelled',
                'participants' => [],
                'drawResult' => null,
                'createdAt' => optional($room->created_at)->toIso8601String(),
                'scheduledDrawAt' => optional($room->draw_started_at ?? $room->created_at)
                    ->setTimezone('Asia/Ho_Chi_Minh')
                    ->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function generateRefundCode(Carbon $now): string
    {
        return 'RF-RM-AUTO-' . $now->format('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
