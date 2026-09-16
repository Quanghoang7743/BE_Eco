<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('ecoluck:generate-daily-rooms')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:expire-tickets --warning-days=2')
            ->dailyAt('00:10')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:unlock-expired-ticket-locks')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:check-sla-warning --minutes=2 --limit=500')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('orders:check-sla')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:refill-empty-rooms')
            ->dailyAt('00:00')
            ->timezone('Asia/Ho_Chi_Minh')
            // ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:cancel-underfilled-rooms')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:generate-affiliate-withdrawals')
            ->daily()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:auto-submit-store-reconciliations')
            ->dailyAt('00:05')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:sync-affiliate-commissions --limit=1000')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:release-affiliate-eligible-commissions')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:recalculate-product-ranking')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:sync-specialist-notifications')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:mark-specialist-tasks-overdue')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:expire-specialist-fee-configs')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:auto-approve-order-returns')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('ecoluck:reconcile-money-wallets')
            ->dailyAt('02:30')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->runInBackground();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
