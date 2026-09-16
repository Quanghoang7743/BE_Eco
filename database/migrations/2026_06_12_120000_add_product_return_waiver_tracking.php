<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets') && !Schema::hasColumn('tickets', 'source_order_item_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->uuid('source_order_item_id')->nullable()->after('source_id');
                $table->index('source_order_item_id');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table): void {
                if (!Schema::hasColumn('order_items', 'return_blocked_at')) {
                    $table->timestamp('return_blocked_at')->nullable()->after('ticket_reward_quantity');
                }
                if (!Schema::hasColumn('order_items', 'return_blocked_by_ticket_id')) {
                    $table->uuid('return_blocked_by_ticket_id')->nullable()->after('return_blocked_at');
                    $table->index('return_blocked_by_ticket_id');
                }
            });
        }

        $this->backfillAvailableRewardTickets();
    }

    private function backfillAvailableRewardTickets(): void
    {
        if (
            !Schema::hasTable('orders')
            || !Schema::hasTable('order_items')
            || !Schema::hasTable('tickets')
            || !Schema::hasColumn('tickets', 'source_order_item_id')
            || !Schema::hasColumn('order_items', 'ticket_reward_quantity')
        ) {
            return;
        }

        DB::table('orders')
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), ['delivered', 'completed'])
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $baseAt = $order->delivered_at ?? null;

                    if (!$baseAt && Schema::hasTable('order_status_history')) {
                        $baseAt = DB::table('order_status_history')
                            ->where('order_id', $order->id)
                            ->whereRaw('LOWER(new_status) in (?, ?)', ['delivered', 'completed'])
                            ->latest('created_at')
                            ->value('created_at');
                    }

                    $baseAt ??= $order->updated_at ?? $order->created_at ?? null;
                    if (!$baseAt || Carbon::parse($baseAt)->addHours(72)->lte(now())) {
                        continue;
                    }

                    $tickets = DB::table('tickets')
                        ->where('source_type', 'ORDER_REWARD')
                        ->where('source_id', $order->id)
                        ->whereRaw('UPPER(status) = ?', ['AVAILABLE'])
                        ->whereNull('source_order_item_id')
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get(['id']);

                    if ($tickets->isEmpty()) {
                        continue;
                    }

                    $items = DB::table('order_items')
                        ->where('order_id', $order->id)
                        ->where('ticket_reward_quantity', '>', 0)
                        ->orderBy('id')
                        ->get(['id', 'ticket_reward_quantity']);

                    $ticketIndex = 0;
                    foreach ($items as $item) {
                        $rewardCount = max(0, (int) $item->ticket_reward_quantity);
                        for ($i = 0; $i < $rewardCount && $ticketIndex < $tickets->count(); $i++, $ticketIndex++) {
                            DB::table('tickets')
                                ->where('id', $tickets[$ticketIndex]->id)
                                ->update(['source_order_item_id' => $item->id]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table): void {
                if (Schema::hasColumn('order_items', 'return_blocked_by_ticket_id')) {
                    $table->dropIndex(['return_blocked_by_ticket_id']);
                    $table->dropColumn('return_blocked_by_ticket_id');
                }
                if (Schema::hasColumn('order_items', 'return_blocked_at')) {
                    $table->dropColumn('return_blocked_at');
                }
            });
        }

        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'source_order_item_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->dropIndex(['source_order_item_id']);
                $table->dropColumn('source_order_item_id');
            });
        }
    }
};
