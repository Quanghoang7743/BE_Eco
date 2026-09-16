<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderStockRestorationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RestoreOrderStockCommand extends Command
{
    protected $signature = 'orders:restore-stock
        {order : Order ID or order code}
        {--source-status= : Known status immediately before cancellation}';

    protected $description = 'Restore inventory once for a cancelled order that previously deducted stock';

    public function handle(OrderStockRestorationService $stockRestorationService): int
    {
        $reference = trim((string) $this->argument('order'));
        $order = Order::query()
            ->where('id', $reference)
            ->orWhere('order_code', $reference)
            ->first();

        if (!$order) {
            $this->error('Order not found: ' . $reference);
            return self::FAILURE;
        }

        $sourceStatus = trim((string) $this->option('source-status'));
        if ($sourceStatus === '') {
            $sourceStatus = $this->resolveSourceStatus($order);
        }

        if ($sourceStatus === '') {
            $this->error('Cannot determine whether this order deducted stock. Pass --source-status explicitly.');
            return self::FAILURE;
        }

        $restored = DB::transaction(function () use ($order, $sourceStatus, $stockRestorationService): bool {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            return $stockRestorationService->restoreForCancellation($lockedOrder, $sourceStatus);
        });

        $order->refresh();
        if (!$restored) {
            if ($order->stock_restored_at !== null) {
                $this->info('Order stock was already restored at ' . $order->stock_restored_at->toIso8601String() . '.');
                return self::SUCCESS;
            }

            $this->warn('No stock was restored because source status "' . $sourceStatus . '" did not deduct inventory.');
            return self::SUCCESS;
        }

        $this->info('Stock restored for order ' . ($order->order_code ?: $order->id) . '.');
        $this->line('Source status: ' . $sourceStatus);
        $this->line('Restored at: ' . $order->stock_restored_at->toIso8601String());

        return self::SUCCESS;
    }

    private function resolveSourceStatus(Order $order): string
    {
        $currentStatus = mb_strtolower(trim((string) $order->status));
        if (!in_array($currentStatus, ['canceled', 'cancelled'], true)) {
            return $currentStatus;
        }

        if (
            Schema::hasTable('order_status_history')
            && Schema::hasColumn('order_status_history', 'order_id')
            && Schema::hasColumn('order_status_history', 'old_status')
            && Schema::hasColumn('order_status_history', 'new_status')
            && Schema::hasColumn('order_status_history', 'created_at')
        ) {
            $history = DB::table('order_status_history')
                ->where('order_id', $order->getKey())
                ->whereIn('new_status', ['canceled', 'cancelled'])
                ->orderByDesc('created_at')
                ->first(['old_status']);

            $oldStatus = mb_strtolower(trim((string) ($history->old_status ?? '')));
            if ($oldStatus !== '') {
                return $oldStatus;
            }
        }

        $paymentMethod = mb_strtoupper(trim((string) $order->payment_method));
        if (in_array($paymentMethod, ['CASH', 'COD'], true)) {
            return 'pending';
        }

        $paymentStatus = mb_strtolower(trim((string) $order->status_payment));
        if (in_array($paymentStatus, ['paid', 'refunded'], true)) {
            return 'pending';
        }

        return '';
    }
}
