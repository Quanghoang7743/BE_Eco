<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DrawRoomUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $roomData;

    public function __construct(array $roomData)
    {
        $this->roomData = $roomData;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('draw-rooms');
    }

    public function broadcastAs(): string
    {
        return 'room:update_votes';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'room:update_votes',
            'updatedAt' => now()->toIso8601String(),
            'room' => $this->roomData,
        ];
    }
}
