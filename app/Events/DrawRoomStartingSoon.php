<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DrawRoomStartingSoon implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('draw-rooms');
    }

    public function broadcastAs(): string
    {
        return 'room:draw_soon';
    }

    public function broadcastWith(): array
    {
        return array_merge([
            'event' => 'room:draw_soon',
            'updatedAt' => now()->toIso8601String(),
        ], $this->payload);
    }
}
