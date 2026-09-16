<?php

namespace App\Events;

use App\Models\OrderReturn;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderReturnUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly array $payload,
    ) {}

    public static function fromReturn(OrderReturn $return, string $action): self
    {
        $return->loadMissing(['order', 'items']);

        return new self($action, [
            'event' => 'order-return.updated',
            'action' => $action,
            'id' => (string) $return->id,
            'return_code' => $return->return_code,
            'order_id' => (string) $return->order_id,
            'order_code' => $return->order?->order_code,
            'store_id' => (string) $return->store_id,
            'user_id' => (string) $return->user_id,
            'status' => $return->status,
            'return_method' => $return->return_method,
            'tracking_code' => $return->tracking_code,
            'carrier_name' => $return->carrier_name,
            'shipping_receipt_images' => $return->shipping_receipt_images ?? [],
            'refund_amount_estimated' => (float) $return->refund_amount_estimated,
            'refund_amount_final' => (float) $return->refund_amount_final,
            'items' => $return->items->map(fn ($item): array => [
                'order_item_id' => (string) $item->order_item_id,
                'quantity' => (int) $item->quantity,
                'refund_amount' => (float) $item->refund_amount,
            ])->values()->all(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order-returns'),
            new PrivateChannel('order-returns.store.'.(string) ($this->payload['store_id'] ?? '')),
            new PrivateChannel('App.Models.User.'.(string) ($this->payload['user_id'] ?? '')),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order-return.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
