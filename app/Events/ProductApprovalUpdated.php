<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductApprovalUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $storeId;
    public int $pendingApprovalCount;
    public ?string $productId;
    public string $action;
    public string $status;
    public int $globalPendingCount;

    public function __construct(string $storeId, int $pendingApprovalCount, string $action, ?string $productId = null, string $status = '')
    {
        $this->storeId = $storeId;
        $this->pendingApprovalCount = $pendingApprovalCount;
        $this->action = $action;
        $this->productId = $productId;
        $this->status = $status;
        $this->globalPendingCount = \App\Models\Product::where('status', 'pending_approval')->count();
    }

    public function broadcastOn(): array
    {
        $channels = [new Channel('product-approvals')];

        if ($this->storeId !== '') {
            $channels[] = new Channel('product-approvals.store.' . $this->storeId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'product.approval.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'product.approval.updated',
            'store_id' => $this->storeId,
            'pending_count' => $this->pendingApprovalCount,
            'action' => $this->action,
            'product_id' => $this->productId,
            'status' => $this->status,
            'global_pending_count' => $this->globalPendingCount,
        ];
    }
}
