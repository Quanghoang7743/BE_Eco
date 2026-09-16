<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ComplaintMessageBroadcasted implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public string $complaintId;
    public array $messageData;

    public function __construct(string $complaintId, array $messageData)
    {
        $this->complaintId = $complaintId;
        $this->messageData = $messageData;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('complaints'),
            new Channel('store-admin.complaints'),
            new Channel('client.complaints'),
            new Channel('complaint.' . $this->complaintId),
            new Channel('complaints.' . $this->complaintId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'complaint.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'complaint.message.created',
            'complaint_id' => $this->complaintId,
            'complaintId' => $this->complaintId,
            'message' => $this->messageData,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
