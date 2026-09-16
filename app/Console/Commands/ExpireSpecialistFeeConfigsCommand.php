<?php

namespace App\Console\Commands;

use App\Services\SpecialistFeeConfigService;
use Illuminate\Console\Command;

class ExpireSpecialistFeeConfigsCommand extends Command
{
    protected $signature = 'ecoluck:expire-specialist-fee-configs';

    protected $description = 'Expire active specialist fee configs whose effective window has ended.';

    public function handle(SpecialistFeeConfigService $feeConfigService): int
    {
        $affectedRows = $feeConfigService->expirePastActiveConfigs();

        $this->info('Expired ' . $affectedRows . ' specialist fee config(s).');

        return self::SUCCESS;
    }
}
