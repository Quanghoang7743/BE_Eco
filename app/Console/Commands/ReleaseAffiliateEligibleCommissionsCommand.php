<?php

namespace App\Console\Commands;

use App\Services\AffiliateCommissionService;
use Illuminate\Console\Command;

class ReleaseAffiliateEligibleCommissionsCommand extends Command
{
    protected $signature = 'ecoluck:release-affiliate-eligible-commissions';

    protected $description = 'Release eligible affiliate commissions after hold period and credit affiliate wallets.';

    public function handle(AffiliateCommissionService $commissionService): int
    {
        $released = $commissionService->transitionWaitingReturnPeriodToEligible();
        $this->info('Released ' . $released . ' affiliate commission(s) into wallets.');

        return self::SUCCESS;
    }
}
