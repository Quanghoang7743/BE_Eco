<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerCanceledOrderEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $storeId,
        public string $orderId,
        public ?string $orderCode,
        public string $reason,
        public string $customerName,
        public string $canceledAt,
    ) {
    }

    public function broadcastOn(): array
    {
        if ($this->storeId === '') {
            return [];
        }

        $channels = [new PrivateChannel('private-store.' . $this->storeId)];

        $channels[] = new Channel('orders.store.' . $this->storeId);

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'customer.order.canceled';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'customer.order.canceled',
            'store_id' => $this->storeId,
            'order_id' => $this->orderId,
            'order_code' => $this->orderCode,
            'reason' => $this->reason,
            'customer_name' => $this->customerName,
            'canceled_at' => $this->canceledAt,
            'status' => 'canceled',
        ];
    }
}
