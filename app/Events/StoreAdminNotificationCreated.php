<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreAdminNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $storeId;
    public array $notificationPayload;

    public function __construct(string $storeId, array $notificationPayload)
    {
        $this->storeId = $storeId;
        $this->notificationPayload = $notificationPayload;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('orders.store.' . $this->storeId);
    }

    public function broadcastAs(): string
    {
        return 'store.admin.notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'store.admin.notification.created',
            'store_id' => $this->storeId,
            'notification' => $this->notificationPayload,
        ];
    }
}
