<?php

namespace App\Console\Commands;

use App\Services\OrderSlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOrderSlaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:check-sla';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check SLA timeouts for pending orders and send warnings/auto-cancel';

    /**
     * Execute the console command.
     */
    public function handle(OrderSlaService $slaService)
    {
        $this->line('Starting SLA check for orders...');
        
        try {
            $result = $slaService->checkAllSlaTimeouts();

            $this->info('SLA check completed successfully!');
            $this->line("Warnings sent: {$result['warnings_sent']}");
            $this->line("Orders cancelled: {$result['orders_cancelled']}");
            
            if ($result['errors'] > 0) {
                $this->warn("Errors encountered: {$result['errors']}");
            }

            Log::info('Orders SLA check command executed', $result);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during SLA check: ' . $e->getMessage());
            Log::error('Orders SLA check command failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
