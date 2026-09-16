<?php

namespace App\Events;

use App\Models\StoreAdminNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NewOrderPlaced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    private ?string $storeId;

    public function __construct($order)
    {
        $this->order = $order;
        $this->storeId = $this->extractStoreId($order);
        $this->persistStoreAdminNotification();
    }

    public function broadcastOn(): array
    {
                $channels = [new Channel('orders')];

        if ($this->storeId !== null) {
            $channels[] = new Channel('orders.store.' . $this->storeId);
                    $channels[] = new Channel('store.' . $this->storeId . '.orders');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'new.order.placed';
    }

    public function broadcastWith(): array
    {
        $orderPayload = $this->order instanceof Model
            ? $this->order->toArray()
            : (is_array($this->order) ? $this->order : ['id' => (string) ($this->order ?? '')]);

        return [
            'event' => 'new.order.placed',
            'store_id' => $this->storeId,
            'order' => $orderPayload,
        ];
    }

    private function extractStoreId($order): ?string
    {
        $storeId = null;

        if ($order instanceof Model) {
            $storeId = $order->getAttribute('store_id');
        } elseif (is_array($order)) {
            $storeId = $order['store_id'] ?? null;
        } elseif (is_object($order) && isset($order->store_id)) {
            $storeId = $order->store_id;
        }

        $normalized = trim((string) ($storeId ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function extractOrderId($order): ?string
    {
        $orderId = null;

        if ($order instanceof Model) {
            $orderId = $order->getKey();
        } elseif (is_array($order)) {
            $orderId = $order['id'] ?? null;
        } elseif (is_object($order) && isset($order->id)) {
            $orderId = $order->id;
        }

        $normalized = trim((string) ($orderId ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function extractOrderCode($order): ?string
    {
        $orderCode = null;

        if ($order instanceof Model) {
            $orderCode = $order->getAttribute('order_code');
        } elseif (is_array($order)) {
            $orderCode = $order['order_code'] ?? null;
        } elseif (is_object($order) && isset($order->order_code)) {
            $orderCode = $order->order_code;
        }

        $normalized = trim((string) ($orderCode ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function extractAmount($order, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = null;

            if ($order instanceof Model) {
                $value = $order->getAttribute($key);
            } elseif (is_array($order)) {
                $value = $order[$key] ?? null;
            } elseif (is_object($order) && isset($order->{$key})) {
                $value = $order->{$key};
            }

            if ($value !== null && $value !== '' && is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    private function persistStoreAdminNotification(): void
    {
        if ($this->storeId === null || !Schema::hasTable('store_admin_notifications')) {
            return;
        }

        $orderId = $this->extractOrderId($this->order);
        $orderCode = $this->extractOrderCode($this->order);

        if ($orderId === null && $orderCode === null) {
            return;
        }

        try {
            $query = StoreAdminNotification::query()
                ->where('store_id', $this->storeId)
                ->where('type', 'order_new');

            if ($orderId !== null) {
                $query->where('order_id', $orderId);
            } elseif ($orderCode !== null) {
                $query->where('order_code', $orderCode);
            }

            if ($query->exists()) {
                return;
            }

            $displayOrderCode = $orderCode ?: ($orderId !== null ? strtoupper(substr($orderId, 0, 8)) : 'N/A');
            $finalAmount = $this->extractAmount($this->order, ['final_amount', 'finalAmount']);
            $totalAmount = $this->extractAmount($this->order, ['total_amount', 'totalAmount', 'total']);
            $amount = $finalAmount ?? $totalAmount;
            $data = [
                'store_id' => $this->storeId,
                'order_id' => $orderId,
                'order_code' => $orderCode,
            ];

            if ($finalAmount !== null) {
                $data['final_amount'] = $finalAmount;
            }

            if ($totalAmount !== null) {
                $data['total_amount'] = $totalAmount;
            }

            if ($amount !== null) {
                $data['amount'] = $amount;
            }

            StoreAdminNotification::query()->create([
                'id' => (string) Str::uuid(),
                'store_id' => $this->storeId,
                'order_id' => $orderId,
                'order_code' => $orderCode,
                'type' => 'order_new',
                'title' => 'Don hang moi',
                'message' => 'Don hang #' . $displayOrderCode . ' vua duoc dat.',
                'data' => $data,
                'is_read' => false,
                'read_at' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to persist store admin notification for new order.', [
                'store_id' => $this->storeId,
                'order_id' => $orderId,
                'order_code' => $orderCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
