<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomSchedule extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    public const DEFAULT_ROOMS_PER_DAY = 40;
    public const DEFAULT_REQUIRED_VOTES = 1000;
    public const DEFAULT_PRIZE_TYPE = 'electric_bike';
    public const DAILY_ROOM_GENERATION = 'daily_room_generation';

    protected $fillable = [
        'schedule_type',
        'is_active',
        'cron_expression',
        'timezone',
        'prize_template_id',
        'template_room_id',
        'last_run_at',
        'next_run_at',
        'last_run_status',
        'last_run_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'template_room_id' => 'string',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public static function getOrCreateDailyConfig(): self
    {
        $schedule = self::query()->firstOrCreate(
            ['schedule_type' => self::DAILY_ROOM_GENERATION],
            [
                'is_active' => true,
                'cron_expression' => '5 0 * * *',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'last_run_message' => json_encode([
                    'rooms_per_day' => self::DEFAULT_ROOMS_PER_DAY,
                    'required_votes' => self::DEFAULT_REQUIRED_VOTES,
                    'default_prize_type' => self::DEFAULT_PRIZE_TYPE,
                    'prize_template_allocations' => [],
                ]),
            ]
        );

        if ($schedule->rooms_per_day < 1) {
            $schedule->rooms_per_day = self::DEFAULT_ROOMS_PER_DAY;
        }

        if ($schedule->required_votes < 1) {
            $schedule->required_votes = self::DEFAULT_REQUIRED_VOTES;
        }

        if ($schedule->default_prize_type === '') {
            $schedule->default_prize_type = self::DEFAULT_PRIZE_TYPE;
        }

        $schedule->save();

        return $schedule;
    }

    public static function getRequiredVotesConfig(): int
    {
        return self::getOrCreateDailyConfig()->required_votes;
    }

    public function markRunStatus(string $status, string $message, ?\DateTimeInterface $nextRunAt = null): void
    {
        $this->last_run_at = now();
        $this->last_run_status = $status;

        if ($nextRunAt !== null) {
            $this->next_run_at = $nextRunAt;
        }

        $payload = $this->getConfigPayload();
        $payload['last_run_message'] = $message;
        $payload['last_run_status'] = $status;
        $payload['last_run_at'] = (string) optional($this->last_run_at)->toDateTimeString();

        if ($nextRunAt !== null) {
            $payload['next_run_at'] = (new \DateTimeImmutable($nextRunAt->format('Y-m-d H:i:s')))->format('Y-m-d H:i:s');
        }

        $this->attributes['last_run_message'] = json_encode($payload);
        $this->save();
    }

    public function getRoomsPerDayAttribute(): int
    {
        $payload = $this->getConfigPayload();

        if (is_array($payload) && isset($payload['rooms_per_day']) && is_numeric($payload['rooms_per_day'])) {
            return max(1, (int) $payload['rooms_per_day']);
        }

        return self::DEFAULT_ROOMS_PER_DAY;
    }

    public function setRoomsPerDayAttribute(int $value): void
    {
        $this->setConfigValue('rooms_per_day', max(1, (int) $value));
    }

    public function getRequiredVotesAttribute(): int
    {
        $payload = $this->getConfigPayload();

        if (isset($payload['required_votes']) && is_numeric($payload['required_votes'])) {
            return max(1, (int) $payload['required_votes']);
        }

        return self::DEFAULT_REQUIRED_VOTES;
    }

    public function setRequiredVotesAttribute(int $value): void
    {
        $this->setConfigValue('required_votes', max(1, (int) $value));
    }

    public function getSelectedTemplateSetIdAttribute(): ?string
    {
        $payload = $this->getConfigPayload();
        $value = isset($payload['selected_template_set_id'])
            ? trim((string) $payload['selected_template_set_id'])
            : '';

        return $value !== '' ? $value : null;
    }

    public function setSelectedTemplateSetIdAttribute($value): void
    {
        $payload = $this->getConfigPayload();
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            unset($payload['selected_template_set_id']);
        } else {
            $payload['selected_template_set_id'] = $value;
        }

        $this->attributes['last_run_message'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function getDefaultPrizeTypeAttribute(): string
    {
        $payload = $this->getConfigPayload();
        $value = isset($payload['default_prize_type']) ? trim((string) $payload['default_prize_type']) : '';

        return $value !== '' ? $value : self::DEFAULT_PRIZE_TYPE;
    }

    public function setDefaultPrizeTypeAttribute(string $value): void
    {
        $normalized = trim($value) !== '' ? trim($value) : self::DEFAULT_PRIZE_TYPE;
        $this->setConfigValue('default_prize_type', $normalized);
    }

    public function getDefaultPrizeValueAttribute(): ?float
    {
        $payload = $this->getConfigPayload();

        if (!isset($payload['default_prize_value']) || $payload['default_prize_value'] === '') {
            return null;
        }

        return (float) $payload['default_prize_value'];
    }

    public function setDefaultPrizeValueAttribute($value): void
    {
        $payload = $this->getConfigPayload();

        if ($value === null || $value === '') {
            unset($payload['default_prize_value']);
        } else {
            $payload['default_prize_value'] = max(0, (float) $value);
        }

        $this->attributes['last_run_message'] = json_encode($payload);
    }

    public function getDefaultPrizeDescriptionAttribute(): ?string
    {
        $payload = $this->getConfigPayload();

        if (!isset($payload['default_prize_description'])) {
            return null;
        }

        $value = trim((string) $payload['default_prize_description']);

        return $value !== '' ? $value : null;
    }

    public function setDefaultPrizeDescriptionAttribute($value): void
    {
        $payload = $this->getConfigPayload();

        if ($value === null || trim((string) $value) === '') {
            unset($payload['default_prize_description']);
        } else {
            $payload['default_prize_description'] = trim((string) $value);
        }

        $this->attributes['last_run_message'] = json_encode($payload);
    }

    public function getDefaultPrizeImageUrlAttribute(): ?string
    {
        $payload = $this->getConfigPayload();

        if (!isset($payload['default_prize_image_url'])) {
            return null;
        }

        $value = trim((string) $payload['default_prize_image_url']);

        return $value !== '' ? $value : null;
    }

    public function getDefaultPrizeTemplateIdAttribute(): ?int
    {
        if (!isset($this->attributes['prize_template_id']) || $this->attributes['prize_template_id'] === null || $this->attributes['prize_template_id'] === '') {
            return null;
        }

        return max(1, (int) $this->attributes['prize_template_id']);
    }

    public function setDefaultPrizeTemplateIdAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['prize_template_id'] = null;
            return;
        }

        $this->attributes['prize_template_id'] = max(1, (int) $value);
    }

    public function getPrizeTemplateAllocationsAttribute(): array
    {
        $payload = $this->getConfigPayload();
        $rows = $payload['prize_template_allocations'] ?? [];

        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $start = (int) ($row['start_room'] ?? 0);
                $end = (int) ($row['end_room'] ?? 0);
                $templateId = (int) ($row['prize_template_id'] ?? 0);

                if ($start < 1 || $end < $start || $templateId < 1) {
                    return null;
                }

                return [
                    'start_room' => $start,
                    'end_room' => $end,
                    'prize_template_id' => $templateId,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function setPrizeTemplateAllocationsAttribute($value): void
    {
        $rows = is_array($value) ? $value : [];

        $normalized = collect($rows)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $start = (int) ($row['start_room'] ?? 0);
                $end = (int) ($row['end_room'] ?? 0);
                $templateId = (int) ($row['prize_template_id'] ?? 0);

                if ($start < 1 || $end < $start || $templateId < 1) {
                    return null;
                }

                return [
                    'start_room' => $start,
                    'end_room' => $end,
                    'prize_template_id' => $templateId,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $payload = $this->getConfigPayload();
        $payload['prize_template_allocations'] = $normalized;
        $this->attributes['last_run_message'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function defaultPrizeTemplate()
    {
        return $this->belongsTo(PrizeTemplate::class, 'prize_template_id');
    }

    public function setDefaultPrizeImageUrlAttribute($value): void
    {
        $payload = $this->getConfigPayload();

        if ($value === null || trim((string) $value) === '') {
            unset($payload['default_prize_image_url']);
        } else {
            $payload['default_prize_image_url'] = trim((string) $value);
        }

        $this->attributes['last_run_message'] = json_encode($payload);
    }

    public function getSecondaryPrizesAttribute(): array
    {
        $payload = $this->getConfigPayload();
        $rows = $payload['secondary_prizes'] ?? [];

        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $type = strtolower(trim((string) ($row['type'] ?? '')));
                $id = $row['id'] ?? null;
                $qty = $row['qty'] ?? null;
                $name = isset($row['name']) ? trim((string) $row['name']) : null;

                if (!in_array($type, ['voucher', 'reward'], true)) {
                    return null;
                }

                if (!is_numeric($id) || (int) $id < 1 || !is_numeric($qty) || (int) $qty < 1) {
                    return null;
                }

                return [
                    'type' => $type,
                    'id' => (int) $id,
                    'qty' => (int) $qty,
                    'name' => $name !== '' ? $name : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function setSecondaryPrizesAttribute($value): void
    {
        $payload = $this->getConfigPayload();
        $rows = is_array($value) ? $value : [];

        $payload['secondary_prizes'] = collect($rows)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $type = strtolower(trim((string) ($row['type'] ?? '')));
                $id = $row['id'] ?? null;
                $qty = $row['qty'] ?? null;
                $name = isset($row['name']) ? trim((string) $row['name']) : null;

                if (!in_array($type, ['voucher', 'reward'], true)) {
                    return null;
                }

                if (!is_numeric($id) || (int) $id < 1 || !is_numeric($qty) || (int) $qty < 1) {
                    return null;
                }

                return [
                    'type' => $type,
                    'id' => (int) $id,
                    'qty' => (int) $qty,
                    'name' => $name !== '' ? $name : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $this->attributes['last_run_message'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function getConfigPayload(): array
    {
        $payload = json_decode((string) $this->last_run_message, true);

        return is_array($payload) ? $payload : [];
    }

    private function setConfigValue(string $key, $value): void
    {
        $payload = $this->getConfigPayload();
        $payload[$key] = $value;

        $this->attributes['last_run_message'] = json_encode($payload);
    }
}
