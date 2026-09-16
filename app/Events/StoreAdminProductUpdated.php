<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreAdminProductUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $storeId;
    public string $productId;
    public string $action;
    public array $context;

    public function __construct(string $storeId, string $productId, string $action = 'updated', array $context = [])
    {
        $this->storeId = $storeId;
        $this->productId = $productId;
        $this->action = $action;
        $this->context = $context;
    }

    public function broadcastOn(): array
    {
        $channels = [new Channel('product-updates')];

        if ($this->storeId !== '') {
            $channels[] = new Channel('product-updates.store.' . $this->storeId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'product.updated';
    }

    public function broadcastWith(): array
    {
        return array_merge([
            'event' => 'product.updated',
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'action' => $this->action,
            'updated_at' => now()->toIso8601String(),
        ], $this->context);
    }
}
