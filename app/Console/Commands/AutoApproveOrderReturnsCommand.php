<?php

namespace App\Console\Commands;

use App\Services\OrderReturnService;
use Illuminate\Console\Command;

class AutoApproveOrderReturnsCommand extends Command
{
    protected $signature = 'ecoluck:auto-approve-order-returns';

    protected $description = 'Auto approve pending shop returns and auto close expired customer disputes.';

    public function handle(OrderReturnService $service): int
    {
        $approvedCount = $service->autoApprovePendingReturns();
        $this->info("Auto-approved {$approvedCount} order return request(s).");

        $closedCount = $service->autoCloseExpiredCustomerDisputes();
        $this->info("Auto-closed {$closedCount} expired rejected/disputed return request(s).");

        return self::SUCCESS;
    }
}
