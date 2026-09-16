<?php

namespace App\Console\Commands;

use App\Services\StoreFinanceReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoSubmitStoreReconciliationsCommand extends Command
{
    protected $signature = 'ecoluck:auto-submit-store-reconciliations {--date=} {--period=day}';

    protected $description = 'Auto-submit store reconciliations to super admin approval for the previous day.';

    public function handle(StoreFinanceReconciliationService $service): int
    {
        $period = (string) $this->option('period');
        if (! in_array($period, ['day', 'month', 'year'], true)) {
            $this->error('Invalid period. Allowed: day, month, year.');

            return self::FAILURE;
        }

        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'), 'Asia/Ho_Chi_Minh')
            : Carbon::now('Asia/Ho_Chi_Minh')->subDay();

        $result = $service->autoSubmitForDate($date, $period);
        $this->info(sprintf(
            'Store reconciliations auto-submitted for %s: submitted=%d skipped=%d',
            $date->toDateString(),
            $result['submitted'] ?? 0,
            $result['skipped'] ?? 0
        ));

        return self::SUCCESS;
    }
}
