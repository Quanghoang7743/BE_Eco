<?php

namespace App\Console\Commands;

use App\Jobs\ProductScoringJob;
use Illuminate\Console\Command;

class RecalculateProductRankingScoresCommand extends Command
{
    protected $signature = 'ecoluck:recalculate-product-ranking';

    protected $description = 'Recalculate product ranking scores for search.';

    public function handle(): int
    {
        app(ProductScoringJob::class)->handle();

        $this->info('Product ranking scores recalculated.');

        return self::SUCCESS;
    }
}
