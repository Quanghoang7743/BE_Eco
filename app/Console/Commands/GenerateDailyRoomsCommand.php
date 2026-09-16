<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Models\RoomSchedule;
use App\Models\RoomTemplateSet;
use App\Services\RoomTemplateService;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateDailyRoomsCommand extends Command
{
    protected $signature = 'ecoluck:generate-daily-rooms {--date= : Ngày tạo phòng (Y-m-d)} {--force : Bỏ qua kiểm tra cron và is_active}';

    protected $description = 'Tự động tạo phòng quay mỗi ngày dựa trên cấu hình room_schedules (S03)';

    public function handle(): int
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $timezone = $schedule->timezone ?: 'Asia/Ho_Chi_Minh';
        $now = Carbon::now($timezone);
        $nextRunAt = $this->calculateNextRunAt($schedule, $now);

        $manualDate = $this->option('date');
        $forceRun = (bool) $this->option('force');

        if (!$manualDate && !$forceRun && !$schedule->is_active) {
            $schedule->markRunStatus('inactive', 'Lịch tạo phòng đang tắt (is_active=false).', $nextRunAt);
            $this->info('Lịch tạo phòng đang tắt.');

            return self::SUCCESS;
        }

        if (!$manualDate && !$forceRun && !$this->isDueNow($schedule, $now)) {
            // Không đến lịch chạy, bỏ qua để command scheduler gọi mỗi phút.
            return self::SUCCESS;
        }

        $targetDate = $manualDate ?: $now->toDateString();
        $roomsPerDay = max(1, (int) $schedule->rooms_per_day);
        $requiredVotes = (int) $schedule->required_votes;
        $defaultPrize = [
            'prize_type' => $schedule->default_prize_type,
            'prize_value' => $schedule->default_prize_value,
            'prize_description' => $schedule->default_prize_description,
            'prize_image_url' => $schedule->default_prize_image_url,
            'default_prize_template_id' => $schedule->default_prize_template_id,
            'prize_template_allocations' => $schedule->prize_template_allocations,
            'secondary_prizes' => $schedule->secondary_prizes,
        ];

        try {
            $rooms = $this->generateRoomsFromSelectedTemplateSet($schedule, $targetDate, $timezone);

            if ($rooms === null && !$manualDate && !$forceRun) {
                throw ValidationException::withMessages([
                    'template_set' => 'Chưa chọn bộ phòng mẫu mặc định. Vào CMS > Cấu hình phòng quay > Step 2 để chọn hoặc tạo bộ mẫu trước khi cron tự động tạo phòng.',
                ]);
            }

            if ($rooms === null) {
                $rooms = $this->generateRoomsFromTemplates($schedule, $targetDate, $requiredVotes, $timezone);
            }

            if ($rooms === null) {
                $rooms = Room::bulkCreateByDate($targetDate, $roomsPerDay, $requiredVotes, $defaultPrize);
            }

            $message = sprintf(
                'Đã tạo %d phòng cho ngày %s (required_votes=%d, prize_type=%s).',
                $rooms->count(),
                $targetDate,
                $requiredVotes,
                $schedule->default_prize_type
            );

            $schedule->markRunStatus('success', $message, $nextRunAt);
            $this->info($message);

            return self::SUCCESS;
        } catch (ValidationException $e) {
            $message = 'Bỏ qua tạo phòng: ' . $e->getMessage();
            $schedule->markRunStatus('skipped', $message, $nextRunAt);
            $this->warn($message);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $message = 'Lỗi tạo phòng tự động: ' . $e->getMessage();
            $schedule->markRunStatus('failed', $message, $nextRunAt);
            report($e);
            $this->error($message);

            return self::FAILURE;
        }
    }

    private function generateRoomsFromSelectedTemplateSet(RoomSchedule $schedule, string $targetDate, string $timezone): ?\Illuminate\Support\Collection
    {
        $templateSetId = trim((string) ($schedule->selected_template_set_id ?? ''));
        if ($templateSetId === '') {
            return null;
        }

        if (!Schema::hasTable('room_template_sets') || !Schema::hasTable('room_templates')) {
            return null;
        }

        $templateSet = RoomTemplateSet::query()->find($templateSetId);
        if (!$templateSet) {
            throw ValidationException::withMessages([
                'template_set' => 'Không tìm thấy bộ phòng mẫu mặc định.',
            ]);
        }

        return app(RoomTemplateService::class)->createRoomsFromTemplateSet($templateSet, $targetDate, $schedule, $timezone);
    }

    private function isDueNow(RoomSchedule $schedule, Carbon $now): bool
    {
        $expression = $schedule->cron_expression ?: '5 0 * * *';
        $cron = CronExpression::factory($expression);

        return $cron->isDue($now->toDateTimeString(), $schedule->timezone ?: 'Asia/Ho_Chi_Minh');
    }

    private function calculateNextRunAt(RoomSchedule $schedule, Carbon $now): ?Carbon
    {
        try {
            $expression = $schedule->cron_expression ?: '5 0 * * *';
            $timezone = $schedule->timezone ?: 'Asia/Ho_Chi_Minh';
            $cron = CronExpression::factory($expression);
            $nextRun = $cron->getNextRunDate($now->toDateTimeString(), 0, false, $timezone);

            return Carbon::instance($nextRun);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function generateRoomsFromTemplates(RoomSchedule $schedule, string $targetDate, int $defaultRequiredVotes, string $timezone): ?\Illuminate\Support\Collection
    {
        if (!Schema::hasColumn('rooms', 'is_template')) {
            return null;
        }

        $templateQuery = Room::query()
            ->where('is_template', true)
            ->orderBy('room_number')
            ->orderBy('created_at');

        if (!empty($schedule->template_room_id)) {
            $templateQuery->whereKey((string) $schedule->template_room_id);
        }

        $templates = $templateQuery->get();

        if ($templates->isEmpty()) {
            return null;
        }

        $targetDate = (new \DateTimeImmutable($targetDate))->format('Y-m-d');

        return DB::transaction(function () use ($templates, $targetDate, $defaultRequiredVotes, $timezone) {
            $now = Carbon::now($timezone);
            $useUuidPrimary = !(new Room())->getIncrementing();

            $existingNumbers = Room::query()
                ->whereDate('room_date', $targetDate)
                ->lockForUpdate()
                ->pluck('room_number')
                ->filter(static fn ($value) => is_numeric($value))
                ->map(static fn ($value) => (int) $value)
                ->all();

            $usedNumbers = array_fill_keys($existingNumbers, true);
            $nextRoomNumber = !empty($existingNumbers) ? (max($existingNumbers) + 1) : 1;
            $createdRoomNumbers = [];
            $payload = [];

            foreach ($templates as $template) {
                $candidateRoomNumber = max(1, (int) ($template->room_number ?? 0));
                if (isset($usedNumbers[$candidateRoomNumber])) {
                    while (isset($usedNumbers[$nextRoomNumber])) {
                        $nextRoomNumber++;
                    }
                    $candidateRoomNumber = $nextRoomNumber;
                }

                $usedNumbers[$candidateRoomNumber] = true;
                if ($candidateRoomNumber >= $nextRoomNumber) {
                    $nextRoomNumber = $candidateRoomNumber + 1;
                }

                $requiredVotes = (int) ($template->required_votes ?? 0);
                if ($requiredVotes < 1) {
                    $requiredVotes = max(1, $defaultRequiredVotes);
                }

                $entry = [
                    'room_code' => Room::makeRoomCode($targetDate, $candidateRoomNumber),
                    'room_date' => $targetDate,
                    'room_number' => $candidateRoomNumber,
                    'status' => 'WAITING',
                    'current_votes' => 0,
                    'required_votes' => $requiredVotes,
                    'prize_type' => $template->prize_type,
                    'prize_value' => $template->prize_value,
                    'prize_description' => $template->prize_description,
                    'prize_image_url' => $template->prize_image_url,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($useUuidPrimary) {
                    $entry['id'] = (string) Str::uuid();
                }

                if (Schema::hasColumn('rooms', 'prize_template_id')) {
                    $entry['prize_template_id'] = $template->prize_template_id;
                }

                if (Schema::hasColumn('rooms', 'secondary_prizes')) {
                    $entry['secondary_prizes'] = $template->secondary_prizes
                        ? json_encode($template->secondary_prizes, JSON_UNESCAPED_UNICODE)
                        : null;
                }

                if (Schema::hasColumn('rooms', 'ticket_type_code')) {
                    $entry['ticket_type_code'] = $template->ticket_type_code;
                }

                if (Schema::hasColumn('rooms', 'open_at')) {
                    $entry['open_at'] = $this->remapDateTimeToTargetDate($template->open_at, $targetDate, $timezone);
                }

                if (Schema::hasColumn('rooms', 'close_at')) {
                    $entry['close_at'] = $this->remapDateTimeToTargetDate($template->close_at, $targetDate, $timezone);
                }

                if (Schema::hasColumn('rooms', 'is_template')) {
                    $entry['is_template'] = false;
                }

                $payload[] = $entry;
                $createdRoomNumbers[] = $candidateRoomNumber;
            }

            if (empty($payload)) {
                throw ValidationException::withMessages([
                    'templates' => 'Không thể tạo phòng từ template.',
                ]);
            }

            Room::query()->insert($payload);

            return Room::query()
                ->whereDate('room_date', $targetDate)
                ->whereIn('room_number', $createdRoomNumbers)
                ->orderBy('room_number')
                ->get();
        });
    }

    private function remapDateTimeToTargetDate($dateTime, string $targetDate, string $timezone): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        $source = Carbon::parse($dateTime, $timezone);
        return Carbon::parse($targetDate . ' ' . $source->format('H:i:s'), $timezone)->toDateTimeString();
    }
}
