<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\RoomBanner;

class Room extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $room): void {
            if (!self::usesUuidPrimaryKeyInDatabase()) {
                return;
            }

            $keyName = $room->getKeyName();
            if (!empty($room->getAttribute($keyName))) {
                return;
            }

            $room->setAttribute($keyName, (string) Str::uuid());
        });
    }

    protected $fillable = [
        'room_code',
        'room_date',
        'room_number',
        'room_name',
        'status',
        'current_votes',
        'required_votes',
        'ticket_type_code',
        'open_at',
        'close_at',
        'is_template',
        'prize_type',
        'prize_value',
        'prize_description',
        'prize_image_url',
        'prize_template_id',
        'secondary_prizes',
        'winner_id',
        'winner_ticket_id',
        'winner_name',
        'draw_started_at',
        'draw_completed_at',
        'draw_seed',
        'block_hash',
        'tx_hash',
    ];

    protected $casts = [
        'room_date' => 'date:Y-m-d',
        'prize_value' => 'decimal:2',
        'secondary_prizes' => 'array',
        'open_at' => 'datetime',
        'close_at' => 'datetime',
        'is_template' => 'boolean',
        'draw_started_at' => 'datetime',
        'draw_completed_at' => 'datetime',
    ];

    public function prizeTemplate()
    {
        return $this->belongsTo(PrizeTemplate::class, 'prize_template_id');
    }

    public function banners()
    {
        return $this->hasMany(RoomBanner::class, 'room_id')->orderBy('order');
    }

    public function roomTickets()
    {
        return $this->hasMany(RoomTicket::class);
    }

    public function draws()
    {
        return $this->hasMany(Draw::class);
    }

    public function getKeyType()
    {
        return self::usesUuidPrimaryKeyInDatabase() ? 'string' : 'int';
    }

    public function getIncrementing()
    {
        return !self::usesUuidPrimaryKeyInDatabase();
    }

    public static function bulkCreateByDate(string $date, int $count, ?int $requiredVotes = null, array $defaultPrize = [], ?string $ticketTypeCode = null)
    {
        if ($count < 1) {
            throw ValidationException::withMessages([
                'count' => 'Số lượng phòng phải lớn hơn hoặc bằng 1.',
            ]);
        }

        if ($requiredVotes === null) {
            $requiredVotes = RoomSchedule::getRequiredVotesConfig();
        }

        if ($requiredVotes < 1) {
            throw ValidationException::withMessages([
                'required_votes' => 'Số vé tối thiểu mỗi phòng phải lớn hơn hoặc bằng 1.',
            ]);
        }

        $roomDate = self::normalizeDate($date);

        $existingRoomNumbers = self::withTrashed()
            ->whereDate('room_date', $roomDate)
            ->whereBetween('room_number', [1, $count])
            ->pluck('room_number');

        if ($existingRoomNumbers->isNotEmpty()) {
            throw ValidationException::withMessages([
                'date' => 'Ngày ' . $roomDate . ' đã có phòng quay trong dải số yêu cầu.',
                'room_numbers' => $existingRoomNumbers->values()->all(),
            ]);
        }

        return DB::transaction(function () use ($roomDate, $count, $requiredVotes, $defaultPrize, $ticketTypeCode) {
            $resolvedTicketTypeCode = strtoupper(trim((string) ($ticketTypeCode ?? 'ALL_TYPES')));
            if ($resolvedTicketTypeCode === '') {
                $resolvedTicketTypeCode = 'ALL_TYPES';
            }

            $now = now();
            $payload = [];
            $useUuidPrimary = self::usesUuidPrimaryKeyInDatabase();

            $prizeResolverContext = self::buildPrizeResolverContext($defaultPrize);

            $secondaryPrizes = is_array($defaultPrize['secondary_prizes'] ?? null)
                ? array_values($defaultPrize['secondary_prizes'])
                : [];

            $hasSecondaryPrizesColumn = Schema::hasColumn('rooms', 'secondary_prizes');
            $secondaryPrizesJson = !empty($secondaryPrizes)
                ? json_encode($secondaryPrizes, JSON_UNESCAPED_UNICODE)
                : null;

            for ($i = 1; $i <= $count; $i++) {
                $resolvedPrize = self::resolvePrizePayloadForRoomNumber($i, $prizeResolverContext);
                $entry = [
                    'room_code' => self::makeRoomCode($roomDate, $i),
                    'room_date' => $roomDate,
                    'room_number' => $i,
                    'status' => 'WAITING',
                    'current_votes' => 0,
                    'required_votes' => $requiredVotes,
                    'ticket_type_code' => $resolvedTicketTypeCode,
                    'prize_type' => $resolvedPrize['prize_type'],
                    'prize_value' => $resolvedPrize['prize_value'],
                    'prize_description' => $resolvedPrize['prize_description'],
                    'prize_image_url' => $resolvedPrize['prize_image_url'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('rooms', 'prize_template_id')) {
                    $entry['prize_template_id'] = $resolvedPrize['prize_template_id'];
                }

                if ($hasSecondaryPrizesColumn) {
                    $entry['secondary_prizes'] = $secondaryPrizesJson;
                }

                if ($useUuidPrimary) {
                    $entry['id'] = (string) Str::uuid();
                }

                $payload[] = $entry;
            }

            self::query()->insert($payload);

            return self::query()
                ->whereDate('room_date', $roomDate)
                ->whereBetween('room_number', [1, $count])
                ->orderBy('room_number')
                ->get();
        });
    }

    public static function appendRoomsByDate(string $date, int $count, ?int $requiredVotes = null, array $defaultPrize = [], ?string $ticketTypeCode = null)
    {
        if ($count < 1) {
            throw ValidationException::withMessages([
                'count' => 'Số lượng phòng phải lớn hơn hoặc bằng 1.',
            ]);
        }

        if ($requiredVotes === null) {
            $requiredVotes = RoomSchedule::getRequiredVotesConfig();
        }

        if ($requiredVotes < 1) {
            throw ValidationException::withMessages([
                'required_votes' => 'Số vé tối thiểu mỗi phòng phải lớn hơn hoặc bằng 1.',
            ]);
        }

        $roomDate = self::normalizeDate($date);

        return DB::transaction(function () use ($roomDate, $count, $requiredVotes, $defaultPrize, $ticketTypeCode) {
            $resolvedTicketTypeCode = strtoupper(trim((string) ($ticketTypeCode ?? 'ALL_TYPES')));
            if ($resolvedTicketTypeCode === '') {
                $resolvedTicketTypeCode = 'ALL_TYPES';
            }

            $now = now();
            $payload = [];
            $useUuidPrimary = self::usesUuidPrimaryKeyInDatabase();
            $maxRoomNumber = (int) (self::withTrashed()
                ->whereDate('room_date', $roomDate)
                ->lockForUpdate()
                ->max('room_number') ?? 0);
            $startRoomNumber = $maxRoomNumber + 1;
            $endRoomNumber = $startRoomNumber + $count - 1;

            $prizeResolverContext = self::buildPrizeResolverContext($defaultPrize);

            $secondaryPrizes = is_array($defaultPrize['secondary_prizes'] ?? null)
                ? array_values($defaultPrize['secondary_prizes'])
                : [];

            $hasSecondaryPrizesColumn = Schema::hasColumn('rooms', 'secondary_prizes');
            $secondaryPrizesJson = !empty($secondaryPrizes)
                ? json_encode($secondaryPrizes, JSON_UNESCAPED_UNICODE)
                : null;

            for ($roomNumber = $startRoomNumber; $roomNumber <= $endRoomNumber; $roomNumber++) {
                $resolvedPrize = self::resolvePrizePayloadForRoomNumber($roomNumber, $prizeResolverContext);
                $entry = [
                    'room_code' => self::makeRoomCode($roomDate, $roomNumber),
                    'room_date' => $roomDate,
                    'room_number' => $roomNumber,
                    'status' => 'WAITING',
                    'current_votes' => 0,
                    'required_votes' => $requiredVotes,
                    'ticket_type_code' => $resolvedTicketTypeCode,
                    'prize_type' => $resolvedPrize['prize_type'],
                    'prize_value' => $resolvedPrize['prize_value'],
                    'prize_description' => $resolvedPrize['prize_description'],
                    'prize_image_url' => $resolvedPrize['prize_image_url'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('rooms', 'prize_template_id')) {
                    $entry['prize_template_id'] = $resolvedPrize['prize_template_id'];
                }

                if ($hasSecondaryPrizesColumn) {
                    $entry['secondary_prizes'] = $secondaryPrizesJson;
                }

                if ($useUuidPrimary) {
                    $entry['id'] = (string) Str::uuid();
                }

                $payload[] = $entry;
            }

            self::query()->insert($payload);

            return self::query()
                ->whereDate('room_date', $roomDate)
                ->whereBetween('room_number', [$startRoomNumber, $endRoomNumber])
                ->orderBy('room_number')
                ->get();
        });
    }

    public static function makeRoomCode(string $date, int $roomNumber): string
    {
        return sprintf('R%s-%03d', str_replace('-', '', self::normalizeDate($date)), $roomNumber);
    }

    private static function normalizeDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('Y-m-d');
    }

    public static function buildPrizeResolverContext(array $defaultPrize): array
    {
        $fallbackType = trim((string) ($defaultPrize['prize_type'] ?? ''));
        if ($fallbackType === '') {
            $fallbackType = 'electric_bike';
        }

        $fallbackValue = $defaultPrize['prize_value'] ?? null;
        if ($fallbackValue !== null && $fallbackValue !== '') {
            $fallbackValue = max(0, (float) $fallbackValue);
        } else {
            $fallbackValue = null;
        }

        $fallbackDescription = $defaultPrize['prize_description'] ?? null;
        if ($fallbackDescription !== null) {
            $fallbackDescription = trim((string) $fallbackDescription);
            if ($fallbackDescription === '') {
                $fallbackDescription = null;
            }
        }

        $fallbackImage = $defaultPrize['prize_image_url'] ?? null;
        if ($fallbackImage !== null) {
            $fallbackImage = trim((string) $fallbackImage);
            if ($fallbackImage === '') {
                $fallbackImage = null;
            }
        }

        $defaultTemplateId = (int) ($defaultPrize['default_prize_template_id'] ?? 0);
        if ($defaultTemplateId < 1) {
            $defaultTemplateId = null;
        }

        $allocationRows = collect((array) ($defaultPrize['prize_template_allocations'] ?? []))
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
            ->values();

        $templateIds = $allocationRows->pluck('prize_template_id')
            ->push($defaultTemplateId)
            ->filter(static fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $templateMap = empty($templateIds)
            ? collect()
            : PrizeTemplate::query()
                ->whereIn('id', $templateIds)
                ->where('is_active', true)
                ->get(['id', 'prize_name', 'monetary_value', 'description', 'image_path'])
                ->keyBy('id');

        return [
            'default_template_id' => $defaultTemplateId,
            'allocations' => $allocationRows->all(),
            'template_map' => $templateMap,
            'fallback' => [
                'prize_type' => $fallbackType,
                'prize_value' => $fallbackValue,
                'prize_description' => $fallbackDescription,
                'prize_image_url' => $fallbackImage,
            ],
        ];
    }

    public static function resolvePrizePayloadForRoomNumber(int $roomNumber, array $context): array
    {
        $selectedTemplateId = $context['default_template_id'] ?? null;

        foreach ((array) ($context['allocations'] ?? []) as $rule) {
            $start = (int) ($rule['start_room'] ?? 0);
            $end = (int) ($rule['end_room'] ?? 0);

            if ($start > 0 && $end >= $start && $roomNumber >= $start && $roomNumber <= $end) {
                $selectedTemplateId = (int) ($rule['prize_template_id'] ?? 0);
                break;
            }
        }

        $templateMap = $context['template_map'] ?? collect();
        $template = ($selectedTemplateId && $templateMap->has($selectedTemplateId))
            ? $templateMap->get($selectedTemplateId)
            : null;

        if ($template) {
            return [
                'prize_template_id' => (int) $template->id,
                'prize_type' => trim((string) ($template->prize_name ?? 'electric_bike')),
                'prize_value' => $template->monetary_value !== null ? max(0, (float) $template->monetary_value) : null,
                'prize_description' => (($template->description ?? null) !== null && trim((string) $template->description) !== '')
                    ? trim((string) $template->description)
                    : null,
                'prize_image_url' => (($template->image_path ?? null) !== null && trim((string) $template->image_path) !== '')
                    ? trim((string) $template->image_path)
                    : null,
            ];
        }

        $fallback = (array) ($context['fallback'] ?? []);

        return [
            'prize_template_id' => null,
            'prize_type' => trim((string) ($fallback['prize_type'] ?? 'electric_bike')),
            'prize_value' => $fallback['prize_value'] ?? null,
            'prize_description' => $fallback['prize_description'] ?? null,
            'prize_image_url' => $fallback['prize_image_url'] ?? null,
        ];
    }

    /**
     * Detect whether rooms.id is UUID-like in the current database.
     * Supports environments still using integer IDs for legacy schemas.
     */
    private static function usesUuidPrimaryKeyInDatabase(): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        try {
            if (!Schema::hasTable('rooms') || !Schema::hasColumn('rooms', 'id')) {
                $cached = true;
                return $cached;
            }

            $columnType = strtolower((string) Schema::getColumnType('rooms', 'id'));
            $cached = in_array($columnType, ['uuid', 'string', 'char'], true);
            return $cached;
        } catch (\Throwable $e) {
            // Default to UUID behavior if schema introspection fails.
            $cached = true;
            return $cached;
        }
    }
}
