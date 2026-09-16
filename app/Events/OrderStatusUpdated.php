<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $orderId;
    public $oldStatus;
    public $newStatus;
    public $storeId;
    public $orderData;

    /**
     * Create a new event instance.
     */
    public function __construct($orderId, $oldStatus, $newStatus, $storeId, $orderData = null)
    {
        $this->orderId = $orderId;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->storeId = $storeId;
        $this->orderData = $orderData;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("orders"),
            new Channel("orders.store.{$this->storeId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'event' => 'order.status.updated',
            'order_id' => $this->orderId,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'store_id' => $this->storeId,
            'order_data' => $this->orderData,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
