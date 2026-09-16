<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomSchedule;
use App\Models\RoomTemplateSet;
use App\Services\RoomTemplateService;
use App\Services\TicketPricingService;
use Illuminate\Http\Request;

class RoomScheduleController extends Controller
{
    public function index()
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $ticketTypes = app(TicketPricingService::class)->getTicketTypes();
        $defaultTicketTypeCode = app(TicketPricingService::class)->getDefaultTicketTypeCode();

        $previewDate = now()->format('Y-m-d');
        $previewRoom = $this->resolvePreviewRoom($schedule, $previewDate, $defaultTicketTypeCode);
        $activeTemplateSet = $schedule->selected_template_set_id
            ? RoomTemplateSet::query()
                ->with(['roomTemplates.prizeTemplate'])
                ->withCount('roomTemplates')
                ->find($schedule->selected_template_set_id)
            : null;
        $selectedTemplateSetId = $activeTemplateSet?->id;
        $templateSets = RoomTemplateSet::query()
            ->with(['roomTemplates.prizeTemplate'])
            ->withCount('roomTemplates')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        if ($activeTemplateSet && !$templateSets->contains(fn (RoomTemplateSet $set) => (string) $set->id === (string) $activeTemplateSet->id)) {
            $templateSets->prepend($activeTemplateSet);
        }

        $previewTemplateSet = $activeTemplateSet ?: $templateSets->first();
        $defaultTargetDate = now($schedule->timezone ?: 'Asia/Ho_Chi_Minh')->addDay()->toDateString();

        return view('admin.room_schedules.index', compact(
            'schedule',
            'ticketTypes',
            'defaultTicketTypeCode',
            'previewRoom',
            'activeTemplateSet',
            'selectedTemplateSetId',
            'templateSets',
            'previewTemplateSet',
            'defaultTargetDate'
        ));
    }

    private function resolvePreviewRoom(RoomSchedule $schedule, string $previewDate, ?string $defaultTicketTypeCode): array
    {
        $templateRoom = null;
        if (!empty($schedule->template_room_id)) {
            $templateRoom = Room::query()->whereKey((string) $schedule->template_room_id)->first();
        }

        if ($templateRoom === null) {
            $templateRoom = Room::query()
                ->where('is_template', true)
                ->orderByDesc('created_at')
                ->first();
        }

        if ($templateRoom === null) {
            $templateRoom = Room::query()
                ->orderByDesc('room_date')
                ->orderByDesc('room_number')
                ->first();
        }

        $ticketType = strtoupper(trim((string) ($defaultTicketTypeCode ?? 'ALL_TYPES')));
        if ($ticketType === '') {
            $ticketType = 'ALL_TYPES';
        }

        return [
            'room_code' => $templateRoom?->room_code ?: Room::makeRoomCode($previewDate, 1),
            'room_date' => $previewDate,
            'room_number' => (int) ($templateRoom?->room_number ?? 1),
            'status' => $templateRoom?->status ?: 'WAITING',
            'required_votes' => (int) ($schedule->required_votes ?? RoomSchedule::DEFAULT_REQUIRED_VOTES),
            'ticket_type_code' => $ticketType,
            'prize_type' => $templateRoom?->prize_type ?: $schedule->default_prize_type,
            'prize_value' => $templateRoom?->prize_value ?: $schedule->default_prize_value,
            'prize_description' => $templateRoom?->prize_description ?: $schedule->default_prize_description,
            'prize_image_url' => $templateRoom?->prize_image_url ?: $schedule->default_prize_image_url,
        ];
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'rooms_per_day' => ['required', 'integer', 'min:1', 'max:1000'],
            'required_votes' => ['required', 'integer', 'min:1', 'max:1000000'],
            'is_active' => ['nullable', 'boolean'],
            'cron_expression' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);
        $redirectStep = $this->resolveRedirectStep($request->input('redirect_step'), 1);

        $schedule = RoomSchedule::query()->findOrFail($id);
        $schedule->rooms_per_day = (int) $validated['rooms_per_day'];
        $schedule->required_votes = (int) $validated['required_votes'];
        $schedule->is_active = (bool) ($validated['is_active'] ?? false);

        if (array_key_exists('cron_expression', $validated)) {
            $schedule->cron_expression = $validated['cron_expression'];
        }

        if (array_key_exists('timezone', $validated)) {
            $schedule->timezone = $validated['timezone'];
        }

        $schedule->save();

        return redirect()
            ->route('admin.room-schedules.index', ['step' => $redirectStep])
            ->with('success', 'Cập nhật cấu hình phòng quay thành công.');
    }

    public function selectTemplateSet(Request $request, string $id)
    {
        $validated = $request->validate([
            'template_set_id' => ['required', 'string', 'max:64'],
        ]);

        $schedule = RoomSchedule::query()->findOrFail($id);
        $templateSet = RoomTemplateSet::query()
            ->with(['roomTemplates'])
            ->withCount('roomTemplates')
            ->findOrFail($validated['template_set_id']);

        $validationMessage = $this->validateTemplateSetForSchedule($templateSet, $schedule);
        if ($validationMessage !== null) {
            return redirect()
                ->route('admin.room-schedules.index', ['step' => 2])
                ->withErrors(['template_set_id' => $validationMessage]);
        }

        $schedule->selected_template_set_id = (string) $templateSet->id;
        $schedule->save();

        return redirect()
            ->route('admin.room-schedules.index', ['step' => $this->resolveRedirectStep($request->input('redirect_step'), 3)])
            ->with('success', 'Đã chọn bộ phòng mẫu "' . $templateSet->name . '" cho lịch tạo phòng tự động.');
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'ticket_type_code' => ['nullable', 'string', 'max:50'],
        ]);

        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $count = (int) ($validated['count'] ?? $schedule->rooms_per_day);
        $ticketCodes = collect(app(TicketPricingService::class)->getTicketTypes())
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values();

        $ticketCodes->push('ALL_TYPES');

        $ticketTypeCode = strtoupper(trim((string) ($validated['ticket_type_code'] ?? 'ALL_TYPES')));
        if (!$ticketCodes->contains($ticketTypeCode)) {
            return back()->withInput()->withErrors([
                'ticket_type_code' => 'Loại vé không hợp lệ trong cấu hình hệ thống.',
            ]);
        }
        $ticketTypeLabel = $ticketTypeCode === 'ALL_TYPES' ? 'Tất cả loại vé' : $ticketTypeCode;

        $defaultPrize = [
            'prize_type' => $schedule->default_prize_type,
            'prize_value' => $schedule->default_prize_value,
            'prize_description' => $schedule->default_prize_description,
            'prize_image_url' => $schedule->default_prize_image_url,
            'default_prize_template_id' => $schedule->default_prize_template_id,
            'prize_template_allocations' => $schedule->prize_template_allocations,
            'secondary_prizes' => $schedule->secondary_prizes,
        ];

        $activeTemplateSet = $schedule->selected_template_set_id
            ? RoomTemplateSet::query()->find($schedule->selected_template_set_id)
            : null;

        if ($activeTemplateSet) {
            $rooms = app(RoomTemplateService::class)->createRoomsFromTemplateSet($activeTemplateSet, $validated['date'], $schedule);
            $sourceLabel = 'bộ mẫu: ' . $activeTemplateSet->name;
            $activeTemplateTicketType = strtoupper(trim((string) ($activeTemplateSet->ticket_type_code ?? 'ALL_TYPES')));
            $ticketTypeLabel = $activeTemplateTicketType === 'ALL_TYPES' ? 'Tất cả loại vé' : $activeTemplateTicketType;
        } else {
            $rooms = Room::bulkCreateByDate($validated['date'], $count, $schedule->required_votes, $defaultPrize, $ticketTypeCode);
            $sourceLabel = 'cấu hình hiện tại';
        }

        return redirect()
            ->route('admin.room-schedules.index', ['step' => 3])
            ->with('success', 'Tạo phòng quay thành công cho ngày ' . $validated['date'] . ' (' . $rooms->count() . ' phòng, yêu cầu ' . $schedule->required_votes . ' vé/phòng, loại vé: ' . $ticketTypeLabel . ', nguồn: ' . $sourceLabel . ').');
    }

    private function validateTemplateSetForSchedule(RoomTemplateSet $templateSet, RoomSchedule $schedule): ?string
    {
        $expectedRooms = max(1, (int) ($schedule->rooms_per_day ?: RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $templates = $templateSet->roomTemplates
            ->sortBy('room_number')
            ->values();

        if ($templates->count() !== $expectedRooms) {
            return 'Bộ mẫu "' . $templateSet->name . '" có ' . $templates->count() . ' phòng, cấu hình hiện tại yêu cầu đúng ' . $expectedRooms . ' phòng.';
        }

        $roomNumbers = $templates
            ->pluck('room_number')
            ->map(static fn ($number) => (int) $number)
            ->unique()
            ->values()
            ->all();
        $missingNumbers = array_values(array_diff(range(1, $expectedRooms), $roomNumbers));

        if (!empty($missingNumbers)) {
            return 'Bộ mẫu "' . $templateSet->name . '" thiếu số phòng: ' . implode(', ', $missingNumbers) . '.';
        }

        return null;
    }

    private function resolveRedirectStep($value, int $fallback): int
    {
        $step = (int) $value;

        return in_array($step, [1, 2, 3], true) ? $step : $fallback;
    }
}
