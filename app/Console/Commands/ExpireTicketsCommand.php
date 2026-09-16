<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\UserBalance;
use App\Services\TicketLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpireTicketsCommand extends Command
{
    protected $signature = 'ecoluck:expire-tickets {--warning-days=2 : Số ngày cảnh báo trước hạn}';

    protected $description = 'S16/S28: Cập nhật hạn sử dụng vé, chuyển EXPIRED, cập nhật số dư và ledger';

    public function handle(): int
    {
        $settings = \App\Models\appSetting::query()->first();
        $expiryDays = (int) ($settings?->ticket_expiry_days ?? \App\Models\appSetting::DEFAULT_TICKET_EXPIRY_DAYS);
        if ($expiryDays < 1) {
            $expiryDays = \App\Models\appSetting::DEFAULT_TICKET_EXPIRY_DAYS;
        }

        $warningDays = (int) $this->option('warning-days');
        if ($warningDays < 0) {
            $warningDays = 0;
        }

        $hasNotificationsTable = Schema::hasTable('notifications');
        $hasNotificationsOrderId = $hasNotificationsTable && Schema::hasColumn('notifications', 'order_id');
        $hasNotificationsUserId = $hasNotificationsTable && Schema::hasColumn('notifications', 'user_id');
        $hasNotificationsAudienceScope = $hasNotificationsTable && Schema::hasColumn('notifications', 'audience_scope');
        $hasNotificationsType = $hasNotificationsTable && Schema::hasColumn('notifications', 'type');
        $hasNotificationsTitle = $hasNotificationsTable && Schema::hasColumn('notifications', 'title');

        // Bước 1: đảm bảo mọi vé hợp lệ đều có expired_at = purchased_at + số ngày cấu hình.
        $updatedExpiredAt = DB::table('tickets')
            ->whereNull('expired_at')
            ->whereNotNull('purchased_at')
            ->update([
                'expired_at' => DB::raw("DATE_ADD(purchased_at, INTERVAL {$expiryDays} DAY)"),
                'updated_at' => now(),
            ]);

        // Bước 2: gửi cảnh báo tùy chọn cho các vé sắp hết hạn.
        $warningCount = 0;
        if ($warningDays > 0) {
            $warningTickets = DB::table('tickets as t')
                ->leftJoin('user_settings as us', 'us.user_id', '=', 't.user_id')
                ->where('t.status', 'AVAILABLE')
                ->whereNull('t.used_in_order_id')
                ->whereDate('t.expired_at', '>=', now()->toDateString())
                ->whereDate('t.expired_at', '<=', now()->copy()->addDays($warningDays)->toDateString())
                ->where(function ($q) {
                    $q->whereNull('us.user_id')->orWhere('us.notif_ticket_expiry', 1);
                })
                ->select('t.ticket_code', 't.user_id', 't.expired_at')
                ->limit(200)
                ->get();

            foreach ($warningTickets as $ticket) {
                $expiredAtText = $ticket->expired_at ? \Carbon\Carbon::parse((string) $ticket->expired_at)->format('Y-m-d H:i:s') : 'N/A';
                if (!$hasNotificationsTable) {
                    continue;
                }

                $warningPayload = [
                    'message' => 'Vé ' . ($ticket->ticket_code ?? 'N/A') . ' của người dùng ' . ($ticket->user_id ?? 'N/A') . ' sắp hết hạn vào ' . $expiredAtText . '.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasNotificationsOrderId) {
                    $warningPayload['order_id'] = null;
                }

                if ($hasNotificationsUserId) {
                    $warningPayload['user_id'] = (string) ($ticket->user_id ?? '');
                }

                if ($hasNotificationsAudienceScope) {
                    $warningPayload['audience_scope'] = $hasNotificationsUserId ? 'user_private' : 'admin_shared';
                }

                if ($hasNotificationsType) {
                    $warningPayload['type'] = 'ticket';
                }

                if ($hasNotificationsTitle) {
                    $warningPayload['title'] = 'Vé sắp hết hạn';
                }

                Notification::query()->create($warningPayload);
                $warningCount++;
            }
        }

        // Bước 3: chuyển vé sang hết hạn và cập nhật số dư/ledger một cách nguyên tử.
        $expiredTickets = DB::table('tickets')
            ->where('status', 'AVAILABLE')
            ->whereNull('used_in_order_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', now())
            ->select('id', 'user_id', 'ticket_code')
            ->get();

        if ($expiredTickets->isEmpty()) {
            $this->info('Không có vé nào hết hạn. expired_at_cap_nhat=' . $updatedExpiredAt . ', warnings=' . $warningCount);

            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $expiredTickets,
            $hasNotificationsTable,
            $hasNotificationsOrderId,
            $hasNotificationsUserId,
            $hasNotificationsAudienceScope,
            $hasNotificationsType,
            $hasNotificationsTitle
        ) {
            $ticketIds = $expiredTickets->pluck('id')->values()->all();

            DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => now(),
                ]);

            $countByUser = $expiredTickets
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->count());

            // Batch-load all existing balances once instead of UserBalance::find()
            // per user (fixes N+1). Missing balances are still created on demand.
            $balancesByUser = UserBalance::query()
                ->whereIn('user_id', $countByUser->keys()->map(fn ($id) => (string) $id)->all())
                ->get()
                ->keyBy(fn (UserBalance $balance) => (string) $balance->user_id);

            $ledgerService = app(TicketLedgerService::class);

            foreach ($countByUser as $userId => $expiredCount) {
                $balance = $balancesByUser->get((string) $userId);

                if (!$balance) {
                    $balance = UserBalance::query()->create([
                        'user_id' => (string) $userId,
                        'available_balance' => 0,
                        'locked_balance' => 0,
                        'used_balance' => 0,
                        'expired_balance' => 0,
                        'total_lifetime_purchased' => 0,
                        'total_lifetime_used' => 0,
                        'last_updated' => now(),
                        'version' => 0,
                    ]);
                }

                $before = (int) $balance->available_balance;
                $after = max(0, $before - (int) $expiredCount);

                $balance->available_balance = $after;
                $balance->expired_balance = (int) $balance->expired_balance + (int) $expiredCount;
                $balance->last_updated = now();
                $balance->version = (int) $balance->version + 1;
                $balance->save();

                $userTickets = $expiredTickets->where('user_id', (string) $userId)->values();
                $runningBefore = $before;

                foreach ($userTickets as $ticket) {
                    $runningAfter = max(0, $runningBefore - 1);
                    $ledgerService->record([
                        'ticket_id' => (string) $ticket->id,
                        'user_id' => (string) $userId,
                        'action_type' => 'EXPIRE',
                        'amount_change' => -1,
                        'balance_before' => $runningBefore,
                        'balance_after' => $runningAfter,
                        'reference_id' => null,
                        'reference_type' => 'ticket_expiry',
                        'metadata' => [
                            'ticket_code' => (string) ($ticket->ticket_code ?? ''),
                            'reason' => 'Ticket expired by scheduled job',
                        ],
                        'created_at' => now(),
                    ]);
                    $runningBefore = $runningAfter;
                }

                if (!$hasNotificationsTable) {
                    continue;
                }

                $expiredPayload = [
                    'message' => 'Người dùng ' . (string) $userId . ' có ' . (int) $expiredCount . ' vé vừa hết hạn.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasNotificationsOrderId) {
                    $expiredPayload['order_id'] = null;
                }

                if ($hasNotificationsUserId) {
                    $expiredPayload['user_id'] = (string) $userId;
                }

                if ($hasNotificationsAudienceScope) {
                    $expiredPayload['audience_scope'] = $hasNotificationsUserId ? 'user_private' : 'admin_shared';
                }

                if ($hasNotificationsType) {
                    $expiredPayload['type'] = 'ticket';
                }

                if ($hasNotificationsTitle) {
                    $expiredPayload['title'] = 'Vé đã hết hạn';
                }

                Notification::query()->create($expiredPayload);
            }
        });

        $this->info('Đã xử lý hết hạn cho ' . $expiredTickets->count() . ' vé. expired_at_cap_nhat=' . $updatedExpiredAt . ', warnings=' . $warningCount);

        return self::SUCCESS;
    }
}
