<?php

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductReturnEligibilityService
{
    public const RETURN_WINDOW_HOURS = 72;

    public function returnDeadline(Order $order): ?Carbon
    {
        $status = strtolower(trim((string) ($order->status ?? '')));
        if (!in_array($status, ['delivered', 'completed'], true)) {
            return null;
        }

        $baseAt = $order->delivered_at;

        if (!$baseAt && $order->getKey() && Schema::hasTable('order_status_history')) {
            $baseAt = DB::table('order_status_history')
                ->where('order_id', $order->getKey())
                ->whereRaw('LOWER(new_status) in (?, ?)', ['delivered', 'completed'])
                ->latest('created_at')
                ->value('created_at');
        }

        $baseAt ??= $order->updated_at ?? $order->created_at;
        if (!$baseAt) {
            return null;
        }

        return Carbon::parse($baseAt)->addHours(self::RETURN_WINDOW_HOURS);
    }

    public function isWithinReturnWindow(Order $order, ?Carbon $now = null): bool
    {
        $deadline = $this->returnDeadline($order);

        return $deadline !== null && ($now ?? now())->lt($deadline);
    }

    /**
     * @return array<string, array<string, mixed>> keyed by ticket id
     */
    public function describeTickets(Collection $tickets, ?Carbon $now = null): array
    {
        if (
            $tickets->isEmpty()
            || !Schema::hasTable('order_items')
            || !Schema::hasTable('orders')
            || !Schema::hasColumn('tickets', 'source_order_item_id')
        ) {
            return [];
        }

        $ticketRows = $tickets
            ->filter(fn ($ticket) => strtoupper(trim((string) ($ticket->source_type ?? ''))) === 'ORDER_REWARD')
            ->filter(fn ($ticket) => !empty($ticket->source_order_item_id))
            ->values();

        if ($ticketRows->isEmpty()) {
            return [];
        }

        $itemIds = $ticketRows->pluck('source_order_item_id')->map(fn ($id) => (string) $id)->unique()->values();
        $select = [
            'oi.id as order_item_id',
            'oi.order_id',
            'oi.product_id',
            'o.order_code',
            'o.status as order_status',
            'o.created_at as order_created_at',
            'o.updated_at as order_updated_at',
        ];

        if (Schema::hasColumn('orders', 'delivered_at')) {
            $select[] = 'o.delivered_at';
        }
        if (Schema::hasColumn('order_items', 'return_blocked_at')) {
            $select[] = 'oi.return_blocked_at';
        }
        if (Schema::hasColumn('order_items', 'return_blocked_by_ticket_id')) {
            $select[] = 'oi.return_blocked_by_ticket_id';
        }
        if (Schema::hasColumn('order_items', 'product_name')) {
            $select[] = 'oi.product_name';
        }
        if (Schema::hasColumn('order_items', 'item_name')) {
            $select[] = 'oi.item_name';
        }

        $items = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.id', $itemIds->all())
            ->get($select)
            ->keyBy(fn ($row) => (string) $row->order_item_id);

        $currentTime = $now ?? now();
        $result = [];

        foreach ($ticketRows as $ticket) {
            $item = $items->get((string) $ticket->source_order_item_id);
            if (!$item) {
                continue;
            }

            $order = new Order();
            $order->forceFill([
                'id' => $item->order_id,
                'status' => $item->order_status ?? null,
                'delivered_at' => $item->delivered_at ?? null,
                'created_at' => $item->order_created_at ?? null,
                'updated_at' => $item->order_updated_at ?? null,
            ]);
            $deadline = $this->returnDeadline($order);
            $blockedAt = $item->return_blocked_at ?? null;

            $result[(string) $ticket->id] = [
                'ticket_id' => (string) $ticket->id,
                'order_item_id' => (string) $item->order_item_id,
                'order_id' => (string) $item->order_id,
                'order_code' => $item->order_code ? (string) $item->order_code : null,
                'product_id' => isset($item->product_id) ? (string) $item->product_id : null,
                'product_name' => trim((string) ($item->product_name ?? $item->item_name ?? 'Sản phẩm')),
                'return_deadline_at' => $deadline?->toIso8601String(),
                'return_blocked_at' => $blockedAt ? Carbon::parse($blockedAt)->toIso8601String() : null,
                'return_blocked_by_ticket_id' => $item->return_blocked_by_ticket_id ?? null,
                'requires_return_waiver' => $deadline !== null && $currentTime->lt($deadline) && empty($blockedAt),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function waiverProducts(Collection $tickets, ?Carbon $now = null): array
    {
        return collect($this->describeTickets($tickets, $now))
            ->filter(fn (array $item) => (bool) ($item['requires_return_waiver'] ?? false))
            ->unique('order_item_id')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string> blocked order item ids
     */
    public function blockReturnsForTickets(Collection $tickets, ?Carbon $now = null): array
    {
        if (
            !Schema::hasColumn('order_items', 'return_blocked_at')
            || !Schema::hasColumn('order_items', 'return_blocked_by_ticket_id')
        ) {
            return [];
        }

        $blockedItemIds = [];
        $blockedAt = $now ?? now();

        foreach ($this->waiverProducts($tickets, $blockedAt) as $item) {
            $ticketId = collect($tickets)->first(function ($ticket) use ($item) {
                return (string) ($ticket->source_order_item_id ?? '') === (string) $item['order_item_id'];
            })?->id;

            if (!$ticketId) {
                continue;
            }

            DB::table('order_items')
                ->where('id', $item['order_item_id'])
                ->whereNull('return_blocked_at')
                ->update([
                    'return_blocked_at' => $blockedAt,
                    'return_blocked_by_ticket_id' => (string) $ticketId,
                ]);

            $blockedItemIds[] = (string) $item['order_item_id'];
        }

        return array_values(array_unique($blockedItemIds));
    }
}
