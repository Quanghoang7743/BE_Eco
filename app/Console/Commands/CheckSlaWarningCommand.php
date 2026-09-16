<?php

namespace App\Console\Commands;

use App\Events\OrderSlaWarningEvent;
use App\Models\Order;
use App\Models\StoreAdminNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CheckSlaWarningCommand extends Command
{
    protected $signature = 'ecoluck:check-sla-warning {--minutes=2 : Warning threshold in minutes} {--limit=500 : Max orders processed per run}';

    protected $description = 'C24: Send warning notifications when pending orders are close to SLA timeout';

    public function handle(): int
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'timeout_at')) {
            $this->warn('Skip: orders.timeout_at does not exist.');

            return self::SUCCESS;
        }

        if (!Schema::hasColumn('orders', 'sla_warning_sent')) {
            $this->warn('Skip: orders.sla_warning_sent does not exist.');

            return self::SUCCESS;
        }

        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        $now = now();
        $deadline = now()->copy()->addMinutes($minutes);

        // Lấy Order models thay vì raw query
        $candidates = Order::where('status', 'pending')
            ->whereNotNull('timeout_at')
            ->where('sla_warning_sent', false)
            ->where('timeout_at', '>', $now)
            ->where('timeout_at', '<=', $deadline)
            ->orderBy('timeout_at')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No pending orders are close to SLA timeout.');

            return self::SUCCESS;
        }

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($candidates as $order) {
            DB::transaction(function () use ($order, &$sentCount, &$skippedCount) {
                $updated = DB::table('orders')
                    ->where('id', $order->id)
                    ->where('status', 'pending')
                    ->where('sla_warning_sent', false)
                    ->update([
                        'sla_warning_sent' => true,
                        'updated_at' => now(),
                    ]);

                if ($updated < 1) {
                    $skippedCount++;

                    return;
                }

                // Refresh order untuk lấy sla_warning_sent = true
                $order->refresh();
                $message = $this->buildWarningMessage($order->order_code, $order->timeout_at);

                if (Schema::hasTable('store_admin_notifications')) {
                    StoreAdminNotification::query()->firstOrCreate(
                        [
                            'store_id' => (string) $order->store_id,
                            'order_id' => (string) $order->id,
                            'type' => 'order_sla_warning',
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'order_code' => (string) ($order->order_code ?? $order->id),
                            'title' => 'Cảnh báo SLA đơn hàng',
                            'message' => $message,
                            'data' => [
                                'event' => 'order.timeout_warning',
                                'timeout_at' => optional($order->timeout_at)->toIso8601String(),
                                'minutes_left' => max(0, now()->diffInMinutes($order->timeout_at, false)),
                            ],
                            'is_read' => false,
                            'read_at' => null,
                        ]
                    );
                }

                $remainingMinutes = max(0, now()->diffInMinutes($order->timeout_at, false));
                Event::dispatch(new OrderSlaWarningEvent($order, $remainingMinutes));

                $sentCount++;
            });
        }

        $this->info(sprintf(
            'Processed SLA warnings: %d sent, %d skipped due to idempotency.',
            $sentCount,
            $skippedCount
        ));

        return self::SUCCESS;
    }

    private function resolveRecipientUserIds(string $storeId): array
    {
        $recipientIds = [];

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'owner_id')) {
            $ownerId = DB::table('stores')->where('id', $storeId)->value('owner_id');
            if (!empty($ownerId)) {
                $recipientIds[] = (string) $ownerId;
            }
        }

        if (Schema::hasTable('user_role_bindings') && Schema::hasTable('roles')) {
            $bindingIds = DB::table('user_role_bindings as urb')
                ->join('roles as r', 'r.id', '=', 'urb.role_id')
                ->where('urb.entity_type', 'store')
                ->where('urb.entity_id', $storeId)
                ->whereIn('r.name', ['admin_store', 'store_staff', 'store_manager'])
                ->pluck('urb.user_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $recipientIds = array_merge($recipientIds, $bindingIds);
        }

        return array_values(array_unique(array_filter($recipientIds, fn ($id) => $id !== '')));
    }

    private function hasAnyRecipientWithNotifOrderEnabled(array $recipientUserIds): bool
    {
        if (empty($recipientUserIds)) {
            return true;
        }

        if (!Schema::hasTable('user_settings') || !Schema::hasColumn('user_settings', 'notif_order')) {
            return true;
        }

        foreach ($recipientUserIds as $userId) {
            $setting = DB::table('user_settings')->where('user_id', $userId)->first();

            if (!$setting) {
                return true;
            }

            if ((bool) ($setting->notif_order ?? true)) {
                return true;
            }
        }

        return false;
    }

    private function buildWarningMessage(?string $orderCode, $timeoutAt): string
    {
        $code = trim((string) ($orderCode ?? ''));
        if ($code === '') {
            $code = 'N/A';
        }

        $minutesLeft = max(0, now()->diffInMinutes($timeoutAt, false));

        return sprintf('Don hang #%s sap het han SLA trong %d phut.', $code, $minutesLeft);
    }

}
