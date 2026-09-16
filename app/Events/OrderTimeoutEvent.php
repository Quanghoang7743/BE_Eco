<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderTimeoutEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;
    public bool $creditLock;
    public ?string $reasonCode;

    public function __construct(Order $order, bool $creditLock = false, ?string $reasonCode = null)
    {
        $this->order = $order;
        $this->creditLock = $creditLock;
        $this->reasonCode = $reasonCode;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('user.' . $this->order->user_id),
            new Channel('store.' . $this->order->store_id . '.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.timeout';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'store_id' => $this->order->store_id,
            'credit_locked' => $this->creditLock,
            'reason_code' => $this->reasonCode,
            'message' => "Đơn hàng #{$this->order->order_code} của bạn đã bị hủy do cửa hàng không xác nhận kịp thời. Vé đã được hoàn lại ví của bạn.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
