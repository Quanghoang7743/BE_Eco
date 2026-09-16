<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Models\RoomSchedule;
use App\Models\RoomTemplateSet;
use App\Models\RoomTicket;
use App\Services\RoomTemplateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RefillEmptyRoomsCommand extends Command
{
    protected $signature = 'ecoluck:refill-empty-rooms
        {--date= : Ngày mục tiêu (Y-m-d), mặc định là hôm nay theo múi giờ đã chọn}
        {--timezone=Asia/Ho_Chi_Minh : Múi giờ để xác định ngày}
        {--dry-run : Chỉ in tóm tắt, không tạo phòng mới}';

    protected $description = 'Giữ số phòng theo ngày đúng mục tiêu bằng cách thay phòng trống vào lúc 00:00';

    public function handle(): int
    {
        $runId = (string) Str::uuid();
        $timezone = trim((string) $this->option('timezone')) ?: 'Asia/Ho_Chi_Minh';
        $targetDate = $this->resolveTargetDate($timezone);

        if ($targetDate === null) {
            Log::warning('refill_empty_rooms.invalid_target_date', [
                'run_id' => $runId,
                'timezone' => $timezone,
                'date_option' => $this->option('date'),
            ]);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $targetRooms = max(1, (int) ($schedule->rooms_per_day ?: RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $requiredVotes = max(1, (int) ($schedule->required_votes ?: RoomSchedule::DEFAULT_REQUIRED_VOTES));
        $defaultPrize = [
            'prize_type' => $schedule->default_prize_type,
            'prize_value' => $schedule->default_prize_value,
            'prize_description' => $schedule->default_prize_description,
            'prize_image_url' => $schedule->default_prize_image_url,
            'default_prize_template_id' => $schedule->default_prize_template_id,
            'prize_template_allocations' => $schedule->prize_template_allocations,
            'secondary_prizes' => $schedule->secondary_prizes,
        ];

        Log::info('refill_empty_rooms.started', [
            'run_id' => $runId,
            'target_date' => $targetDate,
            'timezone' => $timezone,
            'target_rooms' => $targetRooms,
            'dry_run' => $dryRun,
        ]);

        $rooms = Room::query()
            ->whereDate('room_date', $targetDate)
            ->orderBy('room_number')
            ->get(['id', 'room_number', 'required_votes', 'status']);

        $visibleRooms = $rooms->filter(function (Room $room): bool {
            return strtolower((string) $room->status) !== 'cancelled';
        })->values();

        $visibleRoomIds = $visibleRooms
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->values()
            ->all();

        $ticketCountsByRoomId = [];
        if (!empty($visibleRoomIds)) {
            $ticketCountsByRoomId = RoomTicket::query()
                ->select('room_id', DB::raw('COUNT(*) as ticket_count'))
                ->whereIn('room_id', $visibleRoomIds)
                ->where('is_refunded', false)
                ->groupBy('room_id')
                ->pluck('ticket_count', 'room_id')
                ->map(static fn ($count) => (int) $count)
                ->toArray();
        }

        $replaceCandidates = $visibleRooms
            ->filter(function (Room $room) use ($ticketCountsByRoomId): bool {
                $status = strtolower((string) $room->status);
                if (in_array($status, ['completed', 'cancelled', 'drawing'], true)) {
                    return false;
                }

                $roomId = (string) $room->id;
                return ((int) ($ticketCountsByRoomId[$roomId] ?? 0)) <= 0;
            })
            ->sortByDesc('room_number')
            ->values();

        $visibleBefore = $visibleRooms->count();
        $overflow = max(0, $visibleBefore - $targetRooms);
        $trimCount = min($overflow, $replaceCandidates->count());

        $trimRoomIds = $replaceCandidates
            ->take($trimCount)
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->values()
            ->all();

        $replaceRoomIds = $replaceCandidates
            ->slice($trimCount)
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->values()
            ->all();

        $roomsToCancel = array_values(array_unique(array_merge($trimRoomIds, $replaceRoomIds)));

        $createForReplacement = count($replaceRoomIds);
        $createForDeficit = max(0, $targetRooms - $visibleBefore + count($trimRoomIds));
        $roomsToCreate = $createForReplacement + $createForDeficit;

        $projectedVisibleAfter = $visibleBefore - count($roomsToCancel) + $roomsToCreate;
        $overflowLocked = max(0, $projectedVisibleAfter - $targetRooms);

        $summary = [
            'target_rooms' => $targetRooms,
            'visible_rooms_before' => $visibleBefore,
            'replace_candidates' => $replaceCandidates->count(),
            'trimmed_rooms' => count($trimRoomIds),
            'replaced_rooms' => count($replaceRoomIds),
            'overflow_locked_rooms' => $overflowLocked,
            'created_rooms' => 0,
            'visible_rooms_after' => $projectedVisibleAfter,
        ];

        if ($dryRun) {
            $this->info(sprintf(
                '[DRY RUN] target=%d, visible_before=%d, replace_candidates=%d, trimmed=%d, replaced=%d, create=%d, overflow_locked=%d, visible_after=%d.',
                $summary['target_rooms'],
                $summary['visible_rooms_before'],
                $summary['replace_candidates'],
                $summary['trimmed_rooms'],
                $summary['replaced_rooms'],
                $roomsToCreate,
                $summary['overflow_locked_rooms'],
                $summary['visible_rooms_after']
            ));

            Log::info('refill_empty_rooms.dry_run', [
                'run_id' => $runId,
                'target_date' => $targetDate,
                'summary' => $summary,
                'rooms_to_create' => $roomsToCreate,
            ]);

            return self::SUCCESS;
        }

        if (!empty($roomsToCancel)) {
            Room::query()
                ->whereIn('id', $roomsToCancel)
                ->update([
                    'status' => 'CANCELLED',
                    'current_votes' => 0,
                    'updated_at' => now(),
                ]);
        }

        if ($roomsToCreate > 0) {
            $createdRooms = null;
            $isInitialDailyCreation = $visibleBefore === 0 && empty($roomsToCancel) && $roomsToCreate === $targetRooms;

            if ($isInitialDailyCreation) {
                $createdRooms = $this->createInitialRoomsFromSelectedTemplateSet($schedule, $targetDate, $timezone);

                if ($createdRooms === null) {
                    $this->warn('Bỏ qua refill đầu ngày vì chưa chọn bộ phòng mẫu mặc định.');
                    Log::warning('refill_empty_rooms.skipped_missing_template_set', [
                        'run_id' => $runId,
                        'target_date' => $targetDate,
                        'target_rooms' => $targetRooms,
                    ]);

                    return self::SUCCESS;
                }
            }

            if ($createdRooms === null) {
                $createdRooms = Room::appendRoomsByDate(
                    $targetDate,
                    $roomsToCreate,
                    $requiredVotes,
                    $defaultPrize
                );
            }

            $summary['created_rooms'] = $createdRooms->count();
        }

        $summary['visible_rooms_after'] = (int) Room::query()
            ->whereDate('room_date', $targetDate)
            ->whereNotIn(DB::raw('LOWER(status)'), ['cancelled'])
            ->count();

        $summary['overflow_locked_rooms'] = max(0, $summary['visible_rooms_after'] - $summary['target_rooms']);

        Cache::forget('draw_rooms_today_payload');

        if ($summary['overflow_locked_rooms'] > 0) {
            $this->warn('Còn ' . $summary['overflow_locked_rooms'] . ' phòng vượt mục tiêu đang có vé/đã quay, bỏ qua ẩn tự động để tránh mất dữ liệu.');
        }

        $this->info(sprintf(
            'Hoàn tất refill: target=%d, visible_before=%d, replace_candidates=%d, trimmed=%d, replaced=%d, created=%d, overflow_locked=%d, visible_after=%d.',
            $summary['target_rooms'],
            $summary['visible_rooms_before'],
            $summary['replace_candidates'],
            $summary['trimmed_rooms'],
            $summary['replaced_rooms'],
            $summary['created_rooms'],
            $summary['overflow_locked_rooms'],
            $summary['visible_rooms_after']
        ));

        Log::info('refill_empty_rooms.finished', [
            'run_id' => $runId,
            'target_date' => $targetDate,
            'summary' => $summary,
        ]);

        return self::SUCCESS;
    }

    private function createInitialRoomsFromSelectedTemplateSet(RoomSchedule $schedule, string $targetDate, string $timezone): ?\Illuminate\Support\Collection
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
            return null;
        }

        return app(RoomTemplateService::class)->createRoomsFromTemplateSet($templateSet, $targetDate, $schedule, $timezone);
    }

    private function resolveTargetDate(string $timezone): ?string
    {
        $inputDate = trim((string) ($this->option('date') ?? ''));

        if ($inputDate === '') {
            return Carbon::now($timezone)->toDateString();
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $inputDate, $timezone);

            if ($date->format('Y-m-d') !== $inputDate) {
                throw new \InvalidArgumentException('Invalid date format');
            }

            return $date->toDateString();
        } catch (\Throwable $e) {
            $this->error('Giá trị --date không hợp lệ. Định dạng đúng: Y-m-d');

            return null;
        }
    }
}
