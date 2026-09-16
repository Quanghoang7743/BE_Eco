<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaymentSuccess implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $paymentRequestId,
        public readonly string $orderId,
        public readonly string $orderCode,
        public readonly string $statusPayment,
        public readonly string $orderStatus,
        public readonly float $finalAmount,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('order.'.$this->paymentRequestId);
    }

    public function broadcastAs(): string
    {
        return 'PaymentConfirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'payment_request_id' => $this->paymentRequestId,
            'order_id' => $this->orderId,
            'order_code' => $this->orderCode,
            'status_payment' => $this->statusPayment,
            'order_status' => $this->orderStatus,
            'final_amount' => $this->finalAmount,
            'confirmed_at' => now()->toIso8601String(),
        ];
    }
}
