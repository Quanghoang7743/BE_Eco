<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderSlaWarningEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;
    public int $remainingMinutes;

    public function __construct(Order $order, int $remainingMinutes)
    {
        $this->order = $order;
        $this->remainingMinutes = $remainingMinutes;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('store.' . $this->order->store_id . '.orders');
    }

    public function broadcastAs(): string
    {
        return 'order.sla.warning';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'remaining_minutes' => $this->remainingMinutes,
            'message' => "Bạn còn {$this->remainingMinutes} phút để xác nhận đơn #{$this->order->order_code}",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
