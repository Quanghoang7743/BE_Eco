<?php

namespace App\Http\Controllers;

use App\Models\PrizeTemplate;
use App\Models\RoomSchedule;
use App\Models\RoomTemplateSet;
use App\Services\RoomTemplateService;
use App\Services\TicketPricingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoomTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $ticketTypes = collect(app(TicketPricingService::class)->getTicketTypes());
        $defaultTicketTypeCode = app(TicketPricingService::class)->getDefaultTicketTypeCode();
        $selectedTemplateSetId = $schedule->selected_template_set_id;
        $defaultTargetDate = Carbon::now('Asia/Ho_Chi_Minh')->addDay()->toDateString();
        $expectedRooms = max(1, (int) ($schedule->rooms_per_day ?? RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'ticket_type_code' => strtoupper(trim((string) $request->query('ticket_type_code', ''))),
            'status' => strtoupper(trim((string) $request->query('status', ''))),
        ];

        $templateSetQuery = RoomTemplateSet::query()
            ->with(['roomTemplates.prizeTemplate'])
            ->withCount('roomTemplates')
            ->orderByDesc('created_at');

        if ($filters['q'] !== '') {
            $templateSetQuery->where('name', 'like', '%' . $filters['q'] . '%');
        }

        if ($filters['ticket_type_code'] !== '') {
            $templateSetQuery->where('ticket_type_code', $filters['ticket_type_code']);
        }

        if ($filters['status'] !== '') {
            $templateSetQuery->where('status', $filters['status']);
        }

        $templateSets = $templateSetQuery->paginate(10)->withQueryString();
        $latestTemplateUpdate = RoomTemplateSet::query()->max('updated_at');
        $selectedTemplateCount = $selectedTemplateSetId ? 1 : 0;

        return view('admin.room_templates.index', compact(
            'schedule',
            'ticketTypes',
            'defaultTicketTypeCode',
            'selectedTemplateSetId',
            'defaultTargetDate',
            'templateSets',
            'expectedRooms',
            'filters',
            'latestTemplateUpdate',
            'selectedTemplateCount'
        ));
    }

    public function store(Request $request, RoomTemplateService $roomTemplateService): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'ticket_type_code' => ['required', 'string', 'max:50'],
            'redirect_to' => ['nullable', 'string', 'in:room_schedules'],
            'redirect_step' => ['nullable', 'integer', 'in:2,3'],
            'select_after_create' => ['nullable', 'boolean'],
        ]);

        $ticketTypeCode = $this->validateTicketTypeCode((string) $validated['ticket_type_code']);
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            $name = 'Mẫu phòng ' . Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i');
        }

        $templateSet = $roomTemplateService->createSetFromSchedule(
            $schedule,
            $name,
            $ticketTypeCode,
            $request->user()?->id !== null ? (string) $request->user()->id : null
        );

        if ($request->boolean('select_after_create')) {
            $schedule->selected_template_set_id = (string) $templateSet->id;
            $schedule->save();
        }

        if (($validated['redirect_to'] ?? null) === 'room_schedules') {
            $redirectStep = (int) ($validated['redirect_step'] ?? 2);
            $message = 'Đã tạo bộ phòng mẫu "' . $templateSet->name . '" với ' . $templateSet->roomTemplates->count() . ' phòng chờ.';

            if ($request->boolean('select_after_create')) {
                $message .= ' Bộ mẫu này đã được chọn cho lịch tạo phòng tự động.';
            }

            return redirect()
                ->route('admin.room-schedules.index', ['step' => in_array($redirectStep, [2, 3], true) ? $redirectStep : 2])
                ->with('success', $message);
        }

        return redirect()
            ->route('admin.room-templates.index')
            ->with('success', 'Đã tạo bộ phòng mẫu "' . $templateSet->name . '" với ' . $templateSet->roomTemplates->count() . ' phòng chờ.');
    }

    public function show(RoomTemplateSet $roomTemplate): View
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $selectedTemplateSetId = $schedule->selected_template_set_id;
        $expectedRooms = max(1, (int) ($schedule->rooms_per_day ?? RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $roomTemplate->load(['roomTemplates.prizeTemplate'])->loadCount('roomTemplates');

        return view('admin.room_templates.show', compact(
            'schedule',
            'selectedTemplateSetId',
            'expectedRooms',
            'roomTemplate'
        ));
    }

    public function edit(RoomTemplateSet $roomTemplate): View
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $ticketTypes = collect(app(TicketPricingService::class)->getTicketTypes());
        $selectedTemplateSetId = $schedule->selected_template_set_id;
        $roomTemplate->load(['roomTemplates.prizeTemplate']);
        $prizeTemplates = PrizeTemplate::query()
            ->orderByDesc('is_active')
            ->orderBy('prize_name')
            ->get(['id', 'prize_name', 'monetary_value', 'image_path', 'description', 'is_active']);

        return view('admin.room_templates.edit', compact(
            'schedule',
            'ticketTypes',
            'selectedTemplateSetId',
            'roomTemplate',
            'prizeTemplates'
        ));
    }

    public function update(Request $request, RoomTemplateSet $roomTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ticket_type_code' => ['required', 'string', 'max:50'],
            'required_votes' => ['required', 'integer', 'min:1', 'max:1000000'],
            'templates' => ['required', 'array'],
            'templates.*.id' => ['required', 'string', 'max:64'],
            'templates.*.room_name' => ['nullable', 'string', 'max:255'],
            'templates.*.status' => ['required', 'string', 'in:WAITING,READY,DRAFT'],
            'templates.*.required_votes' => ['required', 'integer', 'min:1', 'max:1000000'],
            'templates.*.ticket_type_code' => ['required', 'string', 'max:50'],
            'templates.*.prize_template_id' => ['nullable', 'integer', 'min:1'],
            'templates.*.prize_type' => ['required', 'string', 'max:100'],
            'templates.*.prize_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'templates.*.prize_description' => ['nullable', 'string', 'max:2000'],
        ]);

        $setTicketTypeCode = $this->validateTicketTypeCode((string) $validated['ticket_type_code']);
        $templateRows = collect($validated['templates']);
        $ticketCodes = $this->ticketTypeCodes();

        foreach ($templateRows as $index => $row) {
            $rowTicketTypeCode = strtoupper(trim((string) ($row['ticket_type_code'] ?? '')));
            if (!$ticketCodes->contains($rowTicketTypeCode)) {
                throw ValidationException::withMessages([
                    'templates.' . $index . '.ticket_type_code' => 'Loại vé không hợp lệ trong cấu hình hệ thống.',
                ]);
            }
        }

        $roomTemplate->load(['roomTemplates']);
        $templateMap = $roomTemplate->roomTemplates->keyBy(fn ($template) => (string) $template->id);
        $submittedTemplateIds = $templateRows
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();

        if ($submittedTemplateIds->unique()->count() !== $templateRows->count()) {
            throw ValidationException::withMessages([
                'templates' => 'Danh sách phòng mẫu có dòng bị trùng.',
            ]);
        }

        foreach ($submittedTemplateIds as $templateId) {
            if (!$templateMap->has($templateId)) {
                throw ValidationException::withMessages([
                    'templates' => 'Có phòng mẫu không thuộc bộ mẫu đang sửa.',
                ]);
            }
        }

        $prizeTemplateIds = $templateRows
            ->pluck('prize_template_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $prizeTemplateMap = $prizeTemplateIds->isEmpty()
            ? collect()
            : PrizeTemplate::query()
                ->whereIn('id', $prizeTemplateIds->all())
                ->get(['id', 'prize_name', 'monetary_value', 'image_path', 'description'])
                ->keyBy('id');

        foreach ($prizeTemplateIds as $prizeTemplateId) {
            if (!$prizeTemplateMap->has($prizeTemplateId)) {
                throw ValidationException::withMessages([
                    'templates' => 'Có mẫu giải thưởng không tồn tại hoặc đã bị xóa.',
                ]);
            }
        }

        DB::transaction(function () use ($roomTemplate, $validated, $setTicketTypeCode, $templateRows, $templateMap, $prizeTemplateMap): void {
            $roomTemplate->name = trim((string) $validated['name']);
            $roomTemplate->ticket_type_code = $setTicketTypeCode;
            $roomTemplate->required_votes = (int) $validated['required_votes'];
            $roomTemplate->rooms_per_day = $templateRows->count();
            $roomTemplate->save();

            foreach ($templateRows as $row) {
                $template = $templateMap->get((string) $row['id']);
                $prizeTemplateId = !empty($row['prize_template_id']) ? (int) $row['prize_template_id'] : null;
                $prizeTemplate = $prizeTemplateId !== null ? $prizeTemplateMap->get($prizeTemplateId) : null;

                $template->room_name = trim((string) ($row['room_name'] ?? '')) ?: ('Phòng mẫu ' . (int) $template->room_number);
                $template->status = strtoupper(trim((string) $row['status']));
                $template->required_votes = (int) $row['required_votes'];
                $template->ticket_type_code = $this->validateTicketTypeCode((string) $row['ticket_type_code']);

                if ($prizeTemplate) {
                    $template->prize_template_id = (int) $prizeTemplate->id;
                    $template->prize_type = (string) $prizeTemplate->prize_name;
                    $template->prize_value = $prizeTemplate->monetary_value !== null ? (float) $prizeTemplate->monetary_value : null;
                    $template->prize_description = $prizeTemplate->description;
                    $template->prize_image_url = $prizeTemplate->image_path;
                } else {
                    $template->prize_template_id = null;
                    $template->prize_type = trim((string) $row['prize_type']);
                    $template->prize_value = ($row['prize_value'] ?? null) !== null && $row['prize_value'] !== ''
                        ? (float) $row['prize_value']
                        : null;
                    $template->prize_description = isset($row['prize_description']) && trim((string) $row['prize_description']) !== ''
                        ? trim((string) $row['prize_description'])
                        : null;
                }

                $template->save();
            }
        });

        return redirect()
            ->route('admin.room-templates.edit', $roomTemplate->id)
            ->with('success', 'Đã cập nhật bộ phòng mẫu "' . $roomTemplate->name . '".');
    }

    public function select(RoomTemplateSet $roomTemplate): RedirectResponse
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $roomTemplate->load(['roomTemplates']);
        $expectedRooms = max(1, (int) ($schedule->rooms_per_day ?: RoomSchedule::DEFAULT_ROOMS_PER_DAY));

        if ($roomTemplate->roomTemplates->count() !== $expectedRooms) {
            return redirect()
                ->route('admin.room-templates.index')
                ->withErrors([
                    'template_set' => 'Bộ mẫu "' . $roomTemplate->name . '" có ' . $roomTemplate->roomTemplates->count() . ' phòng, cấu hình hiện tại yêu cầu đúng ' . $expectedRooms . ' phòng.',
                ]);
        }

        $roomNumbers = $roomTemplate->roomTemplates
            ->pluck('room_number')
            ->map(fn ($number) => (int) $number)
            ->unique()
            ->values()
            ->all();
        $missingNumbers = array_values(array_diff(range(1, $expectedRooms), $roomNumbers));

        if (!empty($missingNumbers)) {
            return redirect()
                ->route('admin.room-templates.index')
                ->withErrors([
                    'template_set' => 'Bộ mẫu "' . $roomTemplate->name . '" thiếu số phòng: ' . implode(', ', $missingNumbers) . '.',
                ]);
        }

        $schedule->selected_template_set_id = (string) $roomTemplate->id;
        $schedule->save();

        return redirect()
            ->route('admin.room-templates.index')
            ->with('success', 'Đã chọn bộ phòng mẫu "' . $roomTemplate->name . '" làm mặc định.');
    }

    public function run(Request $request, RoomTemplateSet $roomTemplate, RoomTemplateService $roomTemplateService): RedirectResponse
    {
        $validated = $request->validate([
            'target_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $targetDate = $validated['target_date'] ?? Carbon::now('Asia/Ho_Chi_Minh')->addDay()->toDateString();
        $schedule = RoomSchedule::getOrCreateDailyConfig();

        try {
            $rooms = $roomTemplateService->createRoomsFromTemplateSet($roomTemplate, $targetDate, $schedule);
        } catch (ValidationException $e) {
            throw $e;
        }

        $schedule->selected_template_set_id = (string) $roomTemplate->id;
        $schedule->save();

        return redirect()
            ->route('admin.room-templates.index')
            ->with('success', 'Đã chạy bộ mẫu "' . $roomTemplate->name . '" để tạo ' . $rooms->count() . ' phòng quay ngày ' . $targetDate . '. Bộ mẫu này đã được đặt làm mặc định.');
    }

    public function destroy(RoomTemplateSet $roomTemplate): RedirectResponse
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        if ((string) $schedule->selected_template_set_id === (string) $roomTemplate->id) {
            $schedule->selected_template_set_id = null;
            $schedule->save();
        }

        $name = $roomTemplate->name;
        $roomTemplate->delete();

        return redirect()
            ->route('admin.room-templates.index')
            ->with('success', 'Đã xóa bộ phòng mẫu "' . $name . '".');
    }

    private function validateTicketTypeCode(string $ticketTypeCode): string
    {
        $ticketCodes = $this->ticketTypeCodes();
        $ticketTypeCode = strtoupper(trim($ticketTypeCode));

        if (!$ticketCodes->contains($ticketTypeCode)) {
            throw ValidationException::withMessages([
                'ticket_type_code' => 'Loại vé không hợp lệ trong cấu hình hệ thống.',
            ]);
        }

        return $ticketTypeCode;
    }

    private function ticketTypeCodes()
    {
        $ticketCodes = collect(app(TicketPricingService::class)->getTicketTypes())
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values();

        $ticketCodes->push('ALL_TYPES');

        return $ticketCodes->unique()->values();
    }
}
