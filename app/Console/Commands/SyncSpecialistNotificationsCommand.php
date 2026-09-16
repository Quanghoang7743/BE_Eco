<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SpecialistNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncSpecialistNotificationsCommand extends Command
{
    protected $signature = 'ecoluck:sync-specialist-notifications {--specialist-id= : Chỉ đồng bộ một chuyên viên}';

    protected $description = 'Sync specialist system notifications for sale admins';

    public function handle(SpecialistNotificationService $specialistNotificationService): int
    {
        if (! Schema::hasTable('specialist_notifications')) {
            $this->warn('specialist_notifications table is not available.');

            return self::SUCCESS;
        }

        $specialistId = (int) $this->option('specialist-id');
        $query = User::query()->where('role', 'sale')->select(['id', 'role']);

        if ($specialistId > 0) {
            $query->where('id', $specialistId);
        }

        $total = 0;
        $query->orderBy('id')->chunkById(100, function ($specialists) use ($specialistNotificationService, &$total): void {
            foreach ($specialists as $specialist) {
                $specialistNotificationService->syncForSpecialist((int) $specialist->id);
                $total++;
            }
        });

        $this->info('Synced specialist notifications for ' . $total . ' sale admin(s).');

        return self::SUCCESS;
    }
}
