<?php

namespace App\Console\Commands;

use App\Models\SpecialistTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarkSpecialistTasksOverdueCommand extends Command
{
    protected $signature = 'ecoluck:mark-specialist-tasks-overdue';

    protected $description = 'Mark specialist tasks as overdue when their deadline has passed.';

    public function handle(): int
    {
        if (! Schema::hasTable('specialist_tasks') || ! Schema::hasColumn('specialist_tasks', 'status')) {
            $this->warn('specialist_tasks table is not ready.');

            return self::SUCCESS;
        }

        $deadlineColumn = Schema::hasColumn('specialist_tasks', 'deadline')
            ? 'deadline'
            : (Schema::hasColumn('specialist_tasks', 'due_at') ? 'due_at' : null);

        if ($deadlineColumn === null) {
            $this->warn('No deadline column found.');

            return self::SUCCESS;
        }

        $affectedRows = DB::table('specialist_tasks')
            ->whereNotIn('status', [SpecialistTask::STATUS_COMPLETED, SpecialistTask::STATUS_OVERDUE])
            ->whereNotNull($deadlineColumn)
            ->where($deadlineColumn, '<', now('Asia/Ho_Chi_Minh')->toDateTimeString())
            ->update([
                'status' => SpecialistTask::STATUS_OVERDUE,
                'updated_at' => now(),
            ]);

        $this->info('Marked ' . $affectedRows . ' specialist task(s) as overdue.');

        return self::SUCCESS;
    }
}
