<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreApprovalUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $storeId;
    public string $status;
    public int $pendingCount;

    public function __construct(string $storeId, string $status, int $pendingCount)
    {
        $this->storeId = $storeId;
        $this->status = $status;
        $this->pendingCount = $pendingCount;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('store-approvals'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'store.approval.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'store.approval.updated',
            'store_id' => $this->storeId,
            'status' => $this->status,
            'pending_count' => $this->pendingCount,
        ];
    }
}
