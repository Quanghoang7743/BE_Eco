<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreWalletUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $storeId,
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('finance.store.' . $this->storeId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'finance.wallet.updated';
    }

    public function broadcastWith(): array
    {
        return array_merge($this->payload, [
            'event' => 'finance.wallet.updated',
            'store_id' => $this->storeId,
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}
