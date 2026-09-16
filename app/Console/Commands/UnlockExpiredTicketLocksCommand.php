<?php

namespace App\Console\Commands;

use App\Services\TicketLockService;
use Illuminate\Console\Command;

class UnlockExpiredTicketLocksCommand extends Command
{
    protected $signature = 'ecoluck:unlock-expired-ticket-locks';

    protected $description = 'Release ticket locks that have exceeded TTL';

    public function handle(TicketLockService $ticketLockService): int
    {
        $result = $ticketLockService->cleanupExpiredLocks();

        $this->info(sprintf(
            'Released %d ticket locks and deleted %d lock rows.',
            (int) ($result['released_tickets'] ?? 0),
            (int) ($result['deleted_lock_rows'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
