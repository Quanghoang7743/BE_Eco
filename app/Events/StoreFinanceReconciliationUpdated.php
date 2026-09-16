<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreFinanceReconciliationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $storeId,
        public string $period,
        public string $date,
        public string $action,
        public int $step,
        public array $reconciliation,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('finance.reconciliations'),
            new Channel('finance.store.' . $this->storeId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'finance.reconciliation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'finance.reconciliation.updated',
            'store_id' => $this->storeId,
            'period' => $this->period,
            'date' => $this->date,
            'action' => $this->action,
            'step' => $this->step,
            'reconciliation' => $this->reconciliation,
        ];
    }
}