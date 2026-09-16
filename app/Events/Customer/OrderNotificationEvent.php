<?php

namespace App\Events\Customer;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderNotificationEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly array $payload,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        // Keep consistent with routes/channels.php authorization callback
        // `Broadcast::channel('App.Models.User.{id}', ...)`
        return new PrivateChannel('App.Models.User.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'order.notification';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

