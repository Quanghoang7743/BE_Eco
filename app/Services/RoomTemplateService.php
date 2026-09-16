<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomSchedule;
use App\Models\RoomTemplateSet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomTemplateService
{
    public function createSetFromSchedule(
        RoomSchedule $schedule,
        string $name,
        string $ticketTypeCode,
        ?string $createdBy = null
    ): RoomTemplateSet {
        $roomsPerDay = max(1, (int) ($schedule->rooms_per_day ?: RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $requiredVotes = max(1, (int) ($schedule->required_votes ?: RoomSchedule::DEFAULT_REQUIRED_VOTES));
        $ticketTypeCode = $this->normalizeTicketTypeCode($ticketTypeCode);

        $defaultPrize = [
            'prize_type' => $schedule->default_prize_type,
            'prize_value' => $schedule->default_prize_value,
            'prize_description' => $schedule->default_prize_description,
            'prize_image_url' => $schedule->default_prize_image_url,
            'default_prize_template_id' => $schedule->default_prize_template_id,
            'prize_template_allocations' => $schedule->prize_template_allocations,
            'secondary_prizes' => $schedule->secondary_prizes,
        ];

        $prizeResolverContext = Room::buildPrizeResolverContext($defaultPrize);
        $secondaryPrizes = is_array($defaultPrize['secondary_prizes'] ?? null)
            ? array_values($defaultPrize['secondary_prizes'])
            : [];

        return DB::transaction(function () use ($name, $roomsPerDay, $requiredVotes, $ticketTypeCode, $createdBy, $prizeResolverContext, $secondaryPrizes): RoomTemplateSet {
            $templateSet = RoomTemplateSet::query()->create([
                'name' => trim($name) !== '' ? trim($name) : ('Mẫu phòng ' . now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i')),
                'rooms_per_day' => $roomsPerDay,
                'required_votes' => $requiredVotes,
                'ticket_type_code' => $ticketTypeCode,
                'status' => 'WAITING',
                'created_by' => $createdBy,
            ]);

            $rows = [];
            for ($roomNumber = 1; $roomNumber <= $roomsPerDay; $roomNumber++) {
                $resolvedPrize = Room::resolvePrizePayloadForRoomNumber($roomNumber, $prizeResolverContext);

                $rows[] = [
                    'room_number' => $roomNumber,
                    'room_name' => 'Phòng mẫu ' . $roomNumber,
                    'status' => 'WAITING',
                    'required_votes' => $requiredVotes,
                    'ticket_type_code' => $ticketTypeCode,
                    'prize_template_id' => $resolvedPrize['prize_template_id'],
                    'prize_type' => $resolvedPrize['prize_type'],
                    'prize_value' => $resolvedPrize['prize_value'],
                    'prize_description' => $resolvedPrize['prize_description'],
                    'prize_image_url' => $resolvedPrize['prize_image_url'],
                    'secondary_prizes' => $secondaryPrizes,
                ];
            }

            $templateSet->roomTemplates()->createMany($rows);

            return $templateSet->load(['roomTemplates.prizeTemplate']);
        });
    }

    public function createRoomsFromTemplateSet(
        RoomTemplateSet $templateSet,
        string $targetDate,
        RoomSchedule $schedule,
        string $timezone = 'Asia/Ho_Chi_Minh'
    ): Collection {
        $targetDate = (new \DateTimeImmutable($targetDate))->format('Y-m-d');
        $expectedRooms = max(1, (int) ($schedule->rooms_per_day ?: RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $templateSet->loadMissing(['roomTemplates']);
        $templates = $templateSet->roomTemplates
            ->sortBy('room_number')
            ->values();

        $this->assertTemplateSetMatchesSchedule($templates, $expectedRooms, $templateSet);

        $roomNumbers = $templates
            ->pluck('room_number')
            ->map(static fn ($number) => (int) $number)
            ->values()
            ->all();

        return DB::transaction(function () use ($templateSet, $templates, $targetDate, $timezone, $roomNumbers): Collection {
            $now = Carbon::now($timezone);
            $payload = [];
            $useUuidPrimary = !(new Room())->getIncrementing();
            $hasRoomNameColumn = Schema::hasColumn('rooms', 'room_name');
            $hasTicketTypeColumn = Schema::hasColumn('rooms', 'ticket_type_code');
            $hasOpenAtColumn = Schema::hasColumn('rooms', 'open_at');
            $hasCloseAtColumn = Schema::hasColumn('rooms', 'close_at');
            $hasIsTemplateColumn = Schema::hasColumn('rooms', 'is_template');
            $hasPrizeTemplateColumn = Schema::hasColumn('rooms', 'prize_template_id');
            $hasSecondaryPrizesColumn = Schema::hasColumn('rooms', 'secondary_prizes');
            $hasDrawStartedAtColumn = Schema::hasColumn('rooms', 'draw_started_at');

            $existingRoomNumbers = Room::withTrashed()
                ->whereDate('room_date', $targetDate)
                ->whereIn('room_number', $roomNumbers)
                ->lockForUpdate()
                ->pluck('room_number')
                ->map(static fn ($number) => (int) $number)
                ->values()
                ->all();

            if (!empty($existingRoomNumbers)) {
                throw ValidationException::withMessages([
                    'target_date' => 'Ngày ' . $targetDate . ' đã có phòng trong dải số mẫu: ' . implode(', ', $existingRoomNumbers) . '.',
                ]);
            }

            foreach ($templates as $template) {
                $roomNumber = (int) $template->room_number;
                $secondaryPrizes = is_array($template->secondary_prizes ?? null)
                    ? array_values($template->secondary_prizes)
                    : [];

                $entry = [
                    'room_code' => Room::makeRoomCode($targetDate, $roomNumber),
                    'room_date' => $targetDate,
                    'room_number' => $roomNumber,
                    'status' => 'WAITING',
                    'current_votes' => 0,
                    'required_votes' => max(1, (int) $template->required_votes),
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

                if ($hasRoomNameColumn) {
                    $entry['room_name'] = $template->room_name;
                }

                if ($hasTicketTypeColumn) {
                    $entry['ticket_type_code'] = $this->normalizeTicketTypeCode($template->ticket_type_code);
                }

                if ($hasOpenAtColumn) {
                    $entry['open_at'] = $this->combineDateAndTime($targetDate, $template->open_time, $timezone);
                }

                if ($hasCloseAtColumn) {
                    $entry['close_at'] = $this->combineDateAndTime($targetDate, $template->close_time, $timezone);
                }

                if ($hasDrawStartedAtColumn) {
                    $entry['draw_started_at'] = $this->combineDateAndTime($targetDate, $template->draw_time, $timezone);
                }

                if ($hasIsTemplateColumn) {
                    $entry['is_template'] = false;
                }

                if ($hasPrizeTemplateColumn) {
                    $entry['prize_template_id'] = $template->prize_template_id;
                }

                if ($hasSecondaryPrizesColumn) {
                    $entry['secondary_prizes'] = !empty($secondaryPrizes)
                        ? json_encode($secondaryPrizes, JSON_UNESCAPED_UNICODE)
                        : null;
                }

                $payload[] = $entry;
            }

            Room::query()->insert($payload);

            $templateSet->last_run_at = $now;
            $templateSet->last_target_date = $targetDate;
            $templateSet->save();

            return Room::query()
                ->whereDate('room_date', $targetDate)
                ->whereIn('room_number', $roomNumbers)
                ->orderBy('room_number')
                ->get();
        });
    }

    private function assertTemplateSetMatchesSchedule(Collection $templates, int $expectedRooms, RoomTemplateSet $templateSet): void
    {
        if ($templates->count() !== $expectedRooms) {
            throw ValidationException::withMessages([
                'template_set' => 'Bộ mẫu "' . $templateSet->name . '" có ' . $templates->count() . ' phòng, cấu hình hiện tại yêu cầu đúng ' . $expectedRooms . ' phòng.',
            ]);
        }

        $numbers = $templates
            ->pluck('room_number')
            ->map(static fn ($number) => (int) $number)
            ->values()
            ->all();

        $missingNumbers = array_values(array_diff(range(1, $expectedRooms), $numbers));
        if (!empty($missingNumbers)) {
            throw ValidationException::withMessages([
                'template_set' => 'Bộ mẫu "' . $templateSet->name . '" thiếu số phòng: ' . implode(', ', $missingNumbers) . '.',
            ]);
        }
    }

    private function normalizeTicketTypeCode(?string $ticketTypeCode): string
    {
        $ticketTypeCode = strtoupper(trim((string) ($ticketTypeCode ?? 'ALL_TYPES')));

        return $ticketTypeCode !== '' ? $ticketTypeCode : 'ALL_TYPES';
    }

    private function combineDateAndTime(string $targetDate, ?string $time, string $timezone): ?string
    {
        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }

        return Carbon::parse($targetDate . ' ' . $time, $timezone)
            ->setTimezone('UTC')
            ->toDateTimeString();
    }
}
