<?php

namespace App\Http\Controllers;

use App\Events\DrawRoomUpdated;
use App\Events\RoomDrawResult;
use App\Models\Draw;
use App\Models\PrizeTemplate;
use App\Models\Room;
use App\Models\RoomTicket;
use App\Services\NotificationService;
use App\Services\TicketPricingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'room_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:WAITING,FILLING,FULL,DRAWING,COMPLETED,CANCELED'],
            'trash' => ['nullable', 'in:active,with,only'],
        ]);

        $roomDateFilter = !empty($validated['room_date'])
            ? $validated['room_date']
            : now('Asia/Ho_Chi_Minh')->toDateString();
        $trashFilter = $validated['trash'] ?? 'active';

        $query = Room::query()
            ->withCount(['roomTickets' => fn ($query) => $query->where('is_refunded', false)])
            ->with('prizeTemplate');

        if ($trashFilter === 'with') {
            $query->withTrashed();
        } elseif ($trashFilter === 'only') {
            $query->onlyTrashed();
        }

        $query->whereDate('room_date', $roomDateFilter);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $rooms = $query
            ->orderByDesc('room_date')
            ->orderBy('room_number')
            ->paginate(20)
            ->appends([
                'room_date' => $roomDateFilter,
                'status' => $validated['status'] ?? null,
                'trash' => $trashFilter !== 'active' ? $trashFilter : null,
            ]);

        return view('admin.rooms.index', compact('rooms', 'roomDateFilter', 'trashFilter'));
    }

    public function create(): View
    {
        $prizeTemplates = PrizeTemplate::query()
            ->orderByDesc('is_active')
            ->orderBy('prize_name')
            ->get(['id', 'prize_name', 'monetary_value', 'image_path', 'description', 'is_active']);

        $ticketTypes = app(TicketPricingService::class)->getTicketTypes();
        $defaultTicketTypeCode = app(TicketPricingService::class)->getDefaultTicketTypeCode();

        $room = new Room();
        $room->status = 'WAITING';
        $room->room_date = now('Asia/Ho_Chi_Minh')->toDateString();
        $room->required_votes = 1000;
        $room->ticket_type_code = $defaultTicketTypeCode;
        $room->open_at = now('Asia/Ho_Chi_Minh')->startOfDay()->toDateTimeString();
        $room->close_at = now('Asia/Ho_Chi_Minh')->endOfDay()->toDateTimeString();
        $room->is_template = false;

        return view('admin.rooms.edit', [
            'room' => $room,
            'prizeTemplates' => $prizeTemplates,
            'ticketTypes' => $ticketTypes,
            'isCreate' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_date' => ['nullable', 'date_format:Y-m-d'],
            'room_number' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'room_name' => ['nullable', 'string', 'max:255'],
            'ticket_type_code' => ['required', 'string', 'max:50'],
            'open_at' => ['required', 'date'],
            'close_at' => ['required', 'date', 'after:open_at'],
            'draw_started_at' => ['nullable', 'date_format:H:i'],
            'required_votes' => ['required', 'integer', 'min:1', 'max:1000000'],
            'prize_template_id' => ['nullable', 'integer', 'min:1', 'exists:prize_templates,id'],
            'prize_type' => ['required', 'string', 'max:255'],
            'prize_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'prize_description' => ['required', 'string', 'max:5000'],
            'prize_image_file' => ['nullable', 'image', 'max:5120'],
            'banner_image_files' => ['nullable', 'array'],
            'banner_image_files.*' => ['nullable', 'image', 'max:5120'],
            'is_template' => ['nullable', 'boolean'],
        ], [
            'required' => ':attribute là bắt buộc.',
            'date' => ':attribute không đúng định dạng ngày tháng.',
            'date_format' => ':attribute không đúng định dạng :format.',
            'after' => ':attribute phải sau :date.',
            'integer' => ':attribute phải là số nguyên.',
            'min' => ':attribute tối thiểu là :min.',
            'max' => ':attribute tối đa là :max.',
            'numeric' => ':attribute phải là chữ số.',
            'image' => ':attribute phải là tệp ảnh.',
            'exists' => ':attribute không tồn tại trên hệ thống.',
        ], [
            'room_date' => 'Ngày phòng quay',
            'room_number' => 'Số phòng quay',
            'ticket_type_code' => 'Loại vé',
            'open_at' => 'Thời điểm mở phòng',
            'close_at' => 'Thời điểm đóng phòng',
            'draw_started_at' => 'Giờ quay dự kiến',
            'required_votes' => 'Số vé yêu cầu',
            'prize_template_id' => 'Mẫu giải thưởng',
            'prize_type' => 'Tên giải thưởng',
            'prize_value' => 'Giá trị giải thưởng',
            'prize_description' => 'Mô tả giải thưởng',
            'prize_image_file' => 'Ảnh giải thưởng',
            'banner_image_files' => 'Danh sách banner',
            'is_template' => 'Phòng mẫu',
            'room_name' => 'Tên phòng',
        ]);

        $ticketCodes = collect(app(TicketPricingService::class)->getTicketTypes())
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values();

        $ticketCodes->push('ALL_TYPES');

        if ($ticketCodes->isEmpty()) {
            return back()->withInput()->withErrors([
                'ticket_type_code' => 'Khong co loai ve hop le de gan cho phong.',
            ]);
        }

        $ticketTypeCode = strtoupper(trim((string) $validated['ticket_type_code']));
        if (!$ticketCodes->contains($ticketTypeCode)) {
            return back()->withInput()->withErrors([
                'ticket_type_code' => 'Loai ve khong hop le trong cau hinh he thong.',
            ]);
        }

        $openAt = Carbon::parse((string) $validated['open_at']);
        $roomDate = !empty($validated['room_date'])
            ? Carbon::parse((string) $validated['room_date'])->toDateString()
            : $openAt->toDateString();

        $room = DB::transaction(function () use ($request, $validated, $ticketTypeCode, $roomDate) {
            $requestedNumber = isset($validated['room_number']) ? (int) $validated['room_number'] : null;

            if ($requestedNumber === null) {
                $requestedNumber = (int) (Room::withTrashed()
                    ->whereDate('room_date', $roomDate)
                    ->lockForUpdate()
                    ->max('room_number') ?? 0) + 1;
            }

            $roomNumber = $requestedNumber;
            while (Room::withTrashed()->whereDate('room_date', $roomDate)->where('room_number', $roomNumber)->exists()) {
                $roomNumber++;
            }

            $selectedTemplate = null;
            if (!empty($validated['prize_template_id'])) {
                $selectedTemplate = PrizeTemplate::query()->find((int) $validated['prize_template_id']);
            }

            $imageUrl = '';
            if ($request->hasFile('prize_image_file')) {
                $imagePath = $request->file('prize_image_file')->store('uploads/room-prizes', 'public');
                $imageUrl = Storage::url($imagePath);
            } elseif ($selectedTemplate && !empty($selectedTemplate->image_path)) {
                $imageUrl = trim((string) $selectedTemplate->image_path);
            }

            $room = Room::query()->create([
                'room_code' => Room::makeRoomCode($roomDate, $roomNumber),
                'room_date' => $roomDate,
                'room_number' => $roomNumber,
                'room_name' => trim((string) ($validated['room_name'] ?? '')),
                'status' => 'WAITING',
                'current_votes' => 0,
                'required_votes' => (int) $validated['required_votes'],
                'ticket_type_code' => $ticketTypeCode,
                'open_at' => Carbon::parse((string) $validated['open_at'], 'Asia/Ho_Chi_Minh')->setTimezone('UTC'),
                'close_at' => Carbon::parse((string) $validated['close_at'], 'Asia/Ho_Chi_Minh')->setTimezone('UTC'),
                'is_template' => (bool) ($validated['is_template'] ?? false),
                'prize_template_id' => !empty($validated['prize_template_id']) ? (int) $validated['prize_template_id'] : null,
                'draw_started_at' => !empty($validated['draw_started_at'])
                    ? Carbon::parse($roomDate . ' ' . (string) $validated['draw_started_at'], 'Asia/Ho_Chi_Minh')->setTimezone('UTC')
                    : null,
                'prize_type' => trim((string) $validated['prize_type']),
                'prize_value' => $validated['prize_value'] ?? null,
                'prize_description' => trim((string) $validated['prize_description']),
                'prize_image_url' => $imageUrl !== '' ? $imageUrl : null,
            ]);

            $bannerRecords = $this->prepareBannerRecords($request);
            if (!empty($bannerRecords)) {
                $room->banners()->createMany($bannerRecords);
            }

            return $room;
        });

        return redirect()->route('admin.rooms.edit', $room->id)
            ->with('success', 'Đã tạo phòng quay mới thành công.');
    }

    private function prepareBannerRecords(Request $request, ?Room $room = null): array
    {
        $files = $request->file('banner_image_files', []);
        if ($files === null) {
            return [];
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        $startingOrder = 0;
        if ($room !== null) {
            $room->loadMissing('banners');
            $startingOrder = $room->banners->count();
        }

        $records = [];
        foreach ($files as $index => $file) {
            if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) {
                continue;
            }

            $imagePath = $file->store('uploads/room-banners', 'public');
            $imageUrl = Storage::url($imagePath);

            $records[] = [
                'image_url' => $imageUrl,
                'order' => $startingOrder + $index + 1,
            ];
        }

        return $records;
    }

    public function edit(Room $room): View
    {
        $room->load(['prizeTemplate', 'banners']);

        $prizeTemplates = PrizeTemplate::query()
            ->orderByDesc('is_active')
            ->orderBy('prize_name')
            ->get(['id', 'prize_name', 'monetary_value', 'image_path', 'description', 'is_active']);

        $ticketTypes = app(TicketPricingService::class)->getTicketTypes();

        return view('admin.rooms.edit', [
            'room' => $room,
            'prizeTemplates' => $prizeTemplates,
            'ticketTypes' => $ticketTypes,
            'isCreate' => false,
        ]);
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'ticket_type_code' => ['required', 'string', 'max:50'],
            'room_name' => ['nullable', 'string', 'max:255'],
            'open_at' => ['required', 'date'],
            'close_at' => ['required', 'date', 'after:open_at'],
            'draw_started_at' => ['nullable', 'date_format:H:i'],
            'required_votes' => ['required', 'integer', 'min:1', 'max:1000000'],
            'is_template' => ['nullable', 'boolean'],
            'prize_template_id' => ['nullable', 'integer', 'min:1', 'exists:prize_templates,id'],
            'prize_type' => ['required', 'string', 'max:255'],
            'prize_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'prize_description' => ['required', 'string', 'max:5000'],
            'prize_image_file' => ['nullable', 'image', 'max:5120'],
            'banner_image_files' => ['nullable', 'array'],
            'banner_image_files.*' => ['nullable', 'image', 'max:5120'],
        ], [
            'required' => ':attribute là bắt buộc.',
            'date' => ':attribute không đúng định dạng ngày tháng.',
            'date_format' => ':attribute không đúng định dạng :format.',
            'after' => ':attribute phải sau :date.',
            'integer' => ':attribute phải là số nguyên.',
            'min' => ':attribute tối thiểu là :min.',
            'max' => ':attribute tối đa là :max.',
            'numeric' => ':attribute phải là chữ số.',
            'image' => ':attribute phải là tệp ảnh.',
            'exists' => ':attribute không tồn tại trên hệ thống.',
        ], [
            'ticket_type_code' => 'Loại vé',
            'open_at' => 'Thời điểm mở phòng',
            'close_at' => 'Thời điểm đóng phòng',
            'draw_started_at' => 'Giờ quay dự kiến',
            'required_votes' => 'Số vé yêu cầu',
            'is_template' => 'Phòng mẫu',
            'prize_template_id' => 'Mẫu giải thưởng',
            'prize_type' => 'Tên giải thưởng',
            'prize_value' => 'Giá trị giải thưởng',
            'prize_description' => 'Mô tả giải thưởng',
            'prize_image_file' => 'Ảnh giải thưởng',
            'banner_image_files' => 'Danh sách banner',
            'room_name' => 'Tên phòng',
        ]);

        $ticketCodes = collect(app(TicketPricingService::class)->getTicketTypes())
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values();

        $ticketCodes->push('ALL_TYPES');

        if ($ticketCodes->isEmpty()) {
            return back()->withInput()->withErrors([
                'ticket_type_code' => 'Khong co loai ve hop le de gan cho phong.',
            ]);
        }

        $ticketTypeCode = strtoupper(trim((string) $validated['ticket_type_code']));
        if (!$ticketCodes->contains($ticketTypeCode)) {
            return back()->withInput()->withErrors([
                'ticket_type_code' => 'Loai ve khong hop le trong cau hinh he thong.',
            ]);
        }

        $selectedTemplate = null;
        if (!empty($validated['prize_template_id'])) {
            $selectedTemplate = PrizeTemplate::query()->find((int) $validated['prize_template_id']);
        }

        $imageUrl = trim((string) ($room->prize_image_url ?? ''));

        if ($request->hasFile('prize_image_file')) {
            $imagePath = $request->file('prize_image_file')->store('uploads/room-prizes', 'public');
            $imageUrl = Storage::url($imagePath);
        } elseif ($selectedTemplate && !empty($selectedTemplate->image_path)) {
            $imageUrl = trim((string) $selectedTemplate->image_path);
        }

        $room->prize_template_id = !empty($validated['prize_template_id'])
            ? (int) $validated['prize_template_id']
            : null;
        $room->ticket_type_code = $ticketTypeCode;
        $room->open_at = Carbon::parse((string) $validated['open_at'], 'Asia/Ho_Chi_Minh')->setTimezone('UTC');
        $room->close_at = Carbon::parse((string) $validated['close_at'], 'Asia/Ho_Chi_Minh')->setTimezone('UTC');
        $roomDateString = Carbon::parse((string) $room->room_date)->toDateString();
        $room->draw_started_at = !empty($validated['draw_started_at'])
            ? Carbon::parse($roomDateString . ' ' . (string) $validated['draw_started_at'], 'Asia/Ho_Chi_Minh')->setTimezone('UTC')
            : null;
        $room->required_votes = (int) $validated['required_votes'];
        $room->is_template = (bool) ($validated['is_template'] ?? false);
        $room->prize_type = trim((string) $validated['prize_type']);
        $room->prize_value = $validated['prize_value'] ?? null;
        $room->prize_description = trim((string) $validated['prize_description']);
        $room->prize_image_url = $imageUrl !== '' ? $imageUrl : null;
        $room->room_name = trim((string) ($validated['room_name'] ?? ''));
        $room->save();

        $bannerRecords = $this->prepareBannerRecords($request);
        if (!empty($bannerRecords)) {
            $room->banners()->createMany($bannerRecords);
        }

        return back()->with('success', 'Đã cập nhật giải thưởng và mô tả cho phòng ' . $room->room_code . '.');
    }

    public function reorderBanners(Request $request, Room $room)
    {
        $validated = $request->validate([
            'ordered_items' => ['required', 'array'],
            'ordered_items.*' => ['required', 'string'],
            'new_banner_files' => ['nullable', 'array'],
            'new_banner_files.*' => ['nullable', 'image', 'max:5120'],
            'deleted_banner_ids' => ['nullable', 'array'],
            'deleted_banner_ids.*' => ['nullable', 'integer'],
        ]);

        $existingBanners = $room->banners()->get()->keyBy('id');
        $submittedNewFiles = $request->file('new_banner_files', []);
        $deletedBannerIds = collect($validated['deleted_banner_ids'] ?? [])->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->values()->all();

        if ($submittedNewFiles === null) {
            $submittedNewFiles = [];
        }

        if (!is_array($submittedNewFiles)) {
            $submittedNewFiles = [$submittedNewFiles];
        }

        $newFiles = [];
        foreach ($submittedNewFiles as $index => $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $newFiles[$index] = $file;
            }
        }

        DB::transaction(function () use ($room, $validated, $existingBanners, $newFiles, $deletedBannerIds) {
            $nextOrder = 1;
            $processedExisting = [];
            $processedNewIndexes = [];

            if (!empty($deletedBannerIds)) {
                $room->banners()->whereIn('id', $deletedBannerIds)->delete();
                foreach ($deletedBannerIds as $bannerId) {
                    $existingBanners->forget($bannerId);
                }
            }

            foreach ($validated['ordered_items'] as $item) {
                if (str_starts_with($item, 'existing:')) {
                    $bannerId = (int) substr($item, strlen('existing:'));
                    if (!$existingBanners->has($bannerId)) {
                        continue;
                    }

                    $banner = $existingBanners->get($bannerId);
                    $banner->order = $nextOrder++;
                    $banner->save();
                    $processedExisting[] = $bannerId;
                    continue;
                }

                if (str_starts_with($item, 'new:')) {
                    $newIndex = (int) substr($item, strlen('new:'));
                    if (!array_key_exists($newIndex, $newFiles)) {
                        continue;
                    }

                    $file = $newFiles[$newIndex];
                    $imagePath = $file->store('uploads/room-banners', 'public');
                    $imageUrl = Storage::url($imagePath);

                    $room->banners()->create([
                        'image_url' => $imageUrl,
                        'order' => $nextOrder++,
                    ]);
                    $processedNewIndexes[] = $newIndex;
                }
            }

            foreach ($existingBanners as $banner) {
                if (in_array($banner->id, $processedExisting, true)) {
                    continue;
                }
                $banner->order = $nextOrder++;
                $banner->save();
            }

            foreach ($newFiles as $newIndex => $file) {
                if (in_array($newIndex, $processedNewIndexes, true)) {
                    continue;
                }

                $imagePath = $file->store('uploads/room-banners', 'public');
                $imageUrl = Storage::url($imagePath);

                $room->banners()->create([
                    'image_url' => $imageUrl,
                    'order' => $nextOrder++,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Lưu banner thành công.',
        ]);
    }

    public function destroy(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Xóa mềm phòng quay bởi quản trị viên.';
        }

        try {
            $summary = DB::transaction(function () use ($room, $reason, $request) {
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->first();
                if (!$lockedRoom) {
                    throw new \RuntimeException('Không tìm thấy phòng cần xóa.');
                }

                $this->assertRoomCanBeCanceledBeforeDraw($lockedRoom);

                $refundSummary = $this->refundRoomTicketsForRoomCancellation($lockedRoom, $reason, $request->user());

                $lockedRoom->forceFill([
                    'status' => 'CANCELED',
                    'current_votes' => 0,
                    'draw_started_at' => null,
                    'draw_completed_at' => null,
                ])->save();

                $lockedRoom->delete();

                return array_merge($refundSummary, [
                    'room_code' => $lockedRoom->room_code,
                ]);
            });

            return redirect()->route('admin.rooms.index')->with(
                'success',
                'Đã xóa mềm phòng ' . $summary['room_code']
                    . '. Hoàn ' . $summary['refunded_tickets'] . ' vé cho '
                    . $summary['affected_users'] . ' người dùng.'
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Xóa phòng thất bại: ' . $e->getMessage());
        }
    }

    public function restore(string $roomId): RedirectResponse
    {
        try {
            $roomCode = DB::transaction(function () use ($roomId) {
                $room = Room::withTrashed()->whereKey($roomId)->lockForUpdate()->first();
                if (!$room) {
                    throw new \RuntimeException('Không tìm thấy phòng cần khôi phục.');
                }

                if (!$room->trashed()) {
                    throw new \RuntimeException('Phòng này chưa bị xóa mềm.');
                }

                $room->restore();
                $room->forceFill([
                    'status' => 'WAITING',
                    'current_votes' => 0,
                    'draw_started_at' => null,
                    'draw_completed_at' => null,
                    'winner_id' => null,
                    'winner_ticket_id' => null,
                    'winner_name' => null,
                    'draw_seed' => null,
                    'block_hash' => null,
                    'tx_hash' => null,
                ])->save();

                return (string) ($room->room_code ?? '');
            });

            return back()->with('success', 'Đã khôi phục phòng ' . $roomCode . '. Phòng đang trống và sẵn sàng nhận vé mới.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Khôi phục phòng thất bại: ' . $e->getMessage());
        }
    }

    private function assertRoomCanBeCanceledBeforeDraw(Room $room): void
    {
        $cancelableStatuses = ['waiting', 'filling', 'full'];
        if (!in_array(strtolower((string) $room->status), $cancelableStatuses, true)) {
            throw new \RuntimeException('Chỉ cho phép xóa phòng khi chưa quay (WAITING/FILLING/FULL).');
        }

        if (!empty($room->draw_started_at)) {
            throw new \RuntimeException('Phòng đã bắt đầu quay, không thể xóa mềm.');
        }
    }

    private function refundRoomTicketsForRoomCancellation(Room $lockedRoom, string $reason, mixed $actor): array
    {
        $notificationService = app(NotificationService::class);
        $now = now();
        $actorId = $actor ? (string) $actor->id : null;
        $refundedPerUser = [];
        $totalRefunded = 0;

        RoomTicket::query()
            ->where('room_id', $lockedRoom->id)
            ->where('is_refunded', false)
            ->orderBy('id')
            ->chunkById(200, function ($roomTickets) use (&$refundedPerUser, &$totalRefunded, $lockedRoom, $reason, $actorId, $now) {
                $chunkIds = $roomTickets->pluck('id')->values()->all();
                $ticketIds = $roomTickets->pluck('ticket_id')->filter()->values()->all();

                $ticketOwnerMap = [];
                if (!empty($ticketIds)) {
                    $ticketOwnerMap = DB::table('tickets')
                        ->whereIn('id', $ticketIds)
                        ->pluck('user_id', 'id')
                        ->toArray();

                    DB::table('tickets')
                        ->whereIn('id', $ticketIds)
                        ->update([
                            'status' => 'AVAILABLE',
                            'used_in_room_id' => null,
                            'used_at' => null,
                            'updated_at' => $now,
                        ]);
                }

                if (!empty($chunkIds)) {
                    DB::table('room_tickets')
                        ->whereIn('id', $chunkIds)
                        ->update(['is_refunded' => true]);
                }

                $groupedByUser = [];
                foreach ($roomTickets as $roomTicket) {
                    $ticketId = $roomTicket->ticket_id ? (string) $roomTicket->ticket_id : null;
                    $userId = $roomTicket->user_id ?: ($ticketId ? ($ticketOwnerMap[$ticketId] ?? null) : null);

                    if (empty($userId)) {
                        continue;
                    }

                    if (!isset($groupedByUser[$userId])) {
                        $groupedByUser[$userId] = [
                            'count' => 0,
                            'ticket_ids' => [],
                        ];
                    }

                    $groupedByUser[$userId]['count']++;
                    if ($ticketId !== null && $ticketId !== '') {
                        $groupedByUser[$userId]['ticket_ids'][] = $ticketId;
                    }
                }

                foreach ($groupedByUser as $userId => $userData) {
                    $ticketCount = (int) ($userData['count'] ?? 0);
                    if ($ticketCount < 1) {
                        continue;
                    }

                    $totalRefunded += $ticketCount;
                    $refundedPerUser[$userId] = ($refundedPerUser[$userId] ?? 0) + $ticketCount;

                    $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
                    if (!$balance) {
                        DB::table('user_balances')->insert([
                            'user_id' => $userId,
                            'available_balance' => 0,
                            'locked_balance' => 0,
                            'used_balance' => 0,
                            'expired_balance' => 0,
                            'total_lifetime_purchased' => 0,
                            'total_lifetime_used' => 0,
                            'last_updated' => $now,
                            'version' => 0,
                        ]);
                        $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
                    }

                    $balanceBefore = (int) ($balance->available_balance ?? 0);
                    $balanceAfter = $balanceBefore + $ticketCount;

                    DB::table('user_balances')
                        ->where('user_id', $userId)
                        ->update([
                            'available_balance' => $balanceAfter,
                            'used_balance' => max(0, (int) ($balance->used_balance ?? 0) - $ticketCount),
                            'last_updated' => $now,
                            'version' => (int) ($balance->version ?? 0) + 1,
                        ]);

                    $refundId = (string) Str::uuid();
                    $refundCode = 'RF-RM-' . now()->format('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    $ticketIdList = array_values(array_unique((array) ($userData['ticket_ids'] ?? [])));
                    $firstTicketId = (string) ($ticketIdList[0] ?? '');

                    DB::table('refunds')->insert([
                        'id' => $refundId,
                        'refund_code' => $refundCode,
                        'user_id' => $userId,
                        'ticket_id' => $firstTicketId !== '' ? $firstTicketId : null,
                        'refund_type' => 'ticket',
                        'reason' => $reason,
                        'reason_code' => 'ROOM_CANCELED',
                        'old_status' => 'USED_IN_ROOM',
                        'new_status' => 'REFUNDED',
                        'source_type' => 'room_cancellation',
                        'source_id' => $lockedRoom->id,
                        'processed_by' => $actorId,
                        'processed_at' => $now,
                        'metadata' => json_encode([
                            'room_id' => $lockedRoom->id,
                            'room_code' => $lockedRoom->room_code,
                            'ticket_ids' => $ticketIdList,
                            'ticket_count' => $ticketCount,
                            'reason' => $reason,
                            'event' => 'room_soft_delete',
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                    ]);

                    $runningBefore = $balanceBefore;
                    foreach ($ticketIdList as $ticketId) {
                        DB::table('ticket_ledger')->insert([
                            'id' => (string) Str::uuid(),
                            'ticket_id' => $ticketId,
                            'user_id' => $userId,
                            'action_type' => 'REFUND',
                            'amount_change' => 1,
                            'balance_before' => $runningBefore,
                            'balance_after' => $runningBefore + 1,
                            'reference_id' => $refundId,
                            'reference_type' => 'ROOM_CANCELLATION',
                            'metadata' => json_encode([
                                'room_id' => $lockedRoom->id,
                                'room_code' => $lockedRoom->room_code,
                                'reason' => $reason,
                                'event' => 'room_soft_delete',
                            ], JSON_UNESCAPED_UNICODE),
                            'created_at' => $now,
                        ]);
                        $runningBefore++;
                    }
                }
            });

        foreach ($refundedPerUser as $userId => $count) {
            $notificationService->create([
                'message' => 'Phòng ' . $lockedRoom->room_code . ' đã bị hủy, ' . $count . ' vé của bạn đã được hoàn lại ví.',
                'body' => 'Phòng ' . $lockedRoom->room_code . ' đã bị hủy, vé của bạn đã được hoàn lại ví. Lý do: ' . $reason,
                'order_id' => null,
                'title' => 'Phòng quay đã bị hủy',
                'type' => 'ticket',
                'user_id' => (string) $userId,
                'audience_scope' => 'user_private',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'refunded_tickets' => $totalRefunded,
            'affected_users' => count($refundedPerUser),
            'refunded_per_user' => $refundedPerUser,
        ];
    }

    public function forceDraw(Request $request, Room $room): RedirectResponse
    {
        $notificationService = app(NotificationService::class);

        if ($room->status === 'COMPLETED') {
            return back()->with('error', 'Phòng đã quay xong, không thể force quay lại.');
        }

        $actualVotes = RoomTicket::query()->where('room_id', $room->id)->count();

        if ($actualVotes < (int) $room->required_votes) {
            return back()->with(
                'error',
                'Force quay thất bại: phòng chưa đủ điều kiện ' . number_format((int) $room->required_votes) . ' vé.'
            );
        }

        $result = DB::transaction(function () use ($room, $actualVotes, $notificationService) {
            $startedAt = now();

            $room->update([
                'status' => 'DRAWING',
                'current_votes' => $actualVotes,
                'draw_started_at' => $startedAt,
            ]);

            $winnerTicketRow = RoomTicket::query()
                ->where('room_id', $room->id)
                ->inRandomOrder()
                ->first();

            if (!$winnerTicketRow) {
                throw new \RuntimeException('Không tìm thấy vé tham gia để quay thưởng.');
            }

            $winnerUser = DB::table('users')->where('id', $winnerTicketRow->user_id)->first();
            $winnerName = $winnerUser->full_name ?? $winnerUser->name ?? ('User #' . $winnerTicketRow->user_id);

            $seed = bin2hex(random_bytes(16));
            $blockHash = hash('sha256', $seed . '|' . $room->id . '|' . microtime(true));
            $txHash = '0x' . hash('sha256', $blockHash . '|force_draw|' . $winnerTicketRow->ticket_id);

            $completedAt = now();

            $room->update([
                'status' => 'COMPLETED',
                'winner_id' => $winnerTicketRow->user_id,
                'winner_ticket_id' => $winnerTicketRow->ticket_id,
                'winner_name' => $winnerName,
                'draw_seed' => $seed,
                'block_hash' => $blockHash,
                'tx_hash' => $txHash,
                'draw_completed_at' => $completedAt,
            ]);

            RoomTicket::query()
                ->where('room_id', $room->id)
                ->update(['is_winner' => false]);

            RoomTicket::query()
                ->whereKey($winnerTicketRow->id)
                ->update(['is_winner' => true]);

            $drawCode = 'DRAW-' . now()->format('YmdHis') . '-' . $room->id;

            Draw::create([
                'room_id' => $room->id,
                'draw_code' => $drawCode,
                'status' => 'completed',
                'random_seed' => $seed,
                'algorithm' => 'seed_hash',
                'block_hash' => $blockHash,
                'block_number' => null,
                'block_timestamp' => $completedAt,
                'winner_ticket_id' => $winnerTicketRow->ticket_id,
                'winner_user_id' => $winnerTicketRow->user_id,
                'winner_index' => null,
                'tx_hash' => $txHash,
                'tx_status' => 'confirmed',
                'tx_gas_fee' => 0,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
            ]);

            $notificationService->create([
                'message' => 'Chúc mừng ' . $winnerName . ' đã trúng thưởng ở phòng ' . $room->room_code . '.',
                'order_id' => null,
                'title' => 'Bạn vừa trúng thưởng',
                'type' => 'draw',
                'user_id' => (string) $winnerTicketRow->user_id,
                'audience_scope' => 'user_private',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            event(new RoomDrawResult([
                'room_id' => $room->id,
                'room_code' => $room->room_code,
                'status' => 'COMPLETED',
                'winner_id' => $winnerTicketRow->user_id,
                'winner_ticket_id' => $winnerTicketRow->ticket_id,
                'winner_name' => $winnerName,
                'draw_completed_at' => $completedAt->toDateTimeString(),
                'tx_hash' => $txHash,
                'block_hash' => $blockHash,
                'random_seed' => $seed,
            ]));

            $roomData = [
                'id' => (string) $room->id,
                'name' => sprintf('Phòng %02d hôm nay', (int) $room->room_number),
                'description' => 'Phòng quay hôm nay, mỗi phòng cần 1000 vé để mở kết quả.',
                'maxTickets' => max(1, (int) ($room->required_votes ?: 1000)),
                'currentTickets' => (int) $room->current_votes,
                'prizeDescription' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                'prizeValue' => (float) ($room->prize_value ?: 18000000),
                'status' => strtolower((string) $room->status),
                'participants' => [],
                'createdAt' => optional($room->created_at)->toIso8601String(),
                'scheduledDrawAt' => optional($room->draw_started_at ?? $room->created_at)
                    ->setTimezone('Asia/Ho_Chi_Minh')
                    ->toIso8601String(),
                'drawResult' => [
                    'roomId' => (string) $room->id,
                    'winnerId' => (string) $winnerTicketRow->user_id,
                    'winnerName' => (string) $winnerName,
                    'winnerTicketCode' => (string) $winnerTicketRow->ticket_id,
                    'drawnAt' => optional($completedAt)->toIso8601String(),
                    'prizeDescription' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                ],
            ];

            event(new DrawRoomUpdated($roomData));

            return [
                'room_code' => $room->room_code,
                'winner_name' => $winnerName,
            ];
        });

        return back()->with(
            'success',
            'Kích hoạt quay thưởng thủ công thành công cho phòng ' . $result['room_code'] . '. Người trúng: ' . $result['winner_name']
        );
    }

    public function emergencyCancel(Request $request, string $roomId): RedirectResponse
    {
        if (!$request->isMethod('post')) {
            return redirect()->route('admin.rooms.index')
                ->with('error', 'Yêu cầu không hợp lệ. Vui lòng thao tác hủy từ trang danh sách phòng.');
        }

        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $actor = $request->user();
        if (!$actor || !in_array((string) $actor->role, ['admin', 'super_admin'], true)) {
            return back()->with('error', 'Chỉ tài khoản admin mới được phép hủy phòng khẩn cấp.');
        }

        $reason = trim((string) $request->input('reason'));
        $room = Room::query()->where('id', $roomId)->first();
        if (!$room) {
            return back()->with('error', 'Không tìm thấy phòng để hủy. Vui lòng tải lại trang và thử lại.');
        }

        try {
            $summary = DB::transaction(function () use ($room, $reason, $actor) {
                $notificationService = app(NotificationService::class);
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->first();
                if (!$lockedRoom) {
                    throw new \RuntimeException('Không tìm thấy phòng cần hủy.');
                }

                $cancelableStatuses = ['waiting', 'filling', 'full'];
                if (!in_array(strtolower((string) $lockedRoom->status), $cancelableStatuses, true)) {
                    throw new \RuntimeException('Chỉ cho phép hủy phòng khi chưa quay (WAITING/FILLING/FULL).');
                }

                if (!empty($lockedRoom->draw_started_at)) {
                    throw new \RuntimeException('Phòng đã bắt đầu quay, không thể hủy khẩn cấp.');
                }

                $now = now();
                $refundedPerUser = [];
                $totalRefunded = 0;

                RoomTicket::query()
                    ->where('room_id', $lockedRoom->id)
                    ->where('is_refunded', false)
                    ->orderBy('id')
                    ->chunkById(200, function ($roomTickets) use (&$refundedPerUser, &$totalRefunded, $lockedRoom, $reason, $actor, $now) {
                        $chunkIds = $roomTickets->pluck('id')->values()->all();
                        $ticketIds = $roomTickets->pluck('ticket_id')->filter()->values()->all();

                        $ticketOwnerMap = [];
                        if (!empty($ticketIds)) {
                            $ticketOwnerMap = DB::table('tickets')
                                ->whereIn('id', $ticketIds)
                                ->pluck('user_id', 'id')
                                ->toArray();

                            DB::table('tickets')
                                ->whereIn('id', $ticketIds)
                                ->update([
                                    'status' => 'AVAILABLE',
                                    'used_in_room_id' => null,
                                    'used_at' => null,
                                    'updated_at' => $now,
                                ]);
                        }

                        if (!empty($chunkIds)) {
                            DB::table('room_tickets')->whereIn('id', $chunkIds)->update(['is_refunded' => true]);
                        }

                        $groupedByUser = [];
                        foreach ($roomTickets as $roomTicket) {
                            $ticketId = $roomTicket->ticket_id ? (string) $roomTicket->ticket_id : null;
                            $userId = $roomTicket->user_id ?: ($ticketId ? ($ticketOwnerMap[$ticketId] ?? null) : null);

                            if (empty($userId)) {
                                continue;
                            }

                            if (!isset($groupedByUser[$userId])) {
                                $groupedByUser[$userId] = [
                                    'count' => 0,
                                    'ticket_ids' => [],
                                ];
                            }

                            $groupedByUser[$userId]['count']++;
                            if ($ticketId !== null && $ticketId !== '') {
                                $groupedByUser[$userId]['ticket_ids'][] = $ticketId;
                            }
                        }

                        foreach ($groupedByUser as $userId => $userData) {
                            $ticketCount = (int) ($userData['count'] ?? 0);
                            if ($ticketCount < 1) {
                                continue;
                            }

                            $totalRefunded += $ticketCount;
                            $refundedPerUser[$userId] = ($refundedPerUser[$userId] ?? 0) + $ticketCount;

                            $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
                            if (!$balance) {
                                DB::table('user_balances')->insert([
                                    'user_id' => $userId,
                                    'available_balance' => 0,
                                    'locked_balance' => 0,
                                    'used_balance' => 0,
                                    'expired_balance' => 0,
                                    'total_lifetime_purchased' => 0,
                                    'total_lifetime_used' => 0,
                                    'last_updated' => $now,
                                    'version' => 0,
                                ]);
                                $balance = DB::table('user_balances')->where('user_id', $userId)->lockForUpdate()->first();
                            }

                            $balanceBefore = (int) ($balance->available_balance ?? 0);
                            $balanceAfter = $balanceBefore + $ticketCount;

                            DB::table('user_balances')
                                ->where('user_id', $userId)
                                ->update([
                                    'available_balance' => $balanceAfter,
                                    'used_balance' => max(0, (int) ($balance->used_balance ?? 0) - $ticketCount),
                                    'last_updated' => $now,
                                    'version' => (int) ($balance->version ?? 0) + 1,
                                ]);

                            $refundId = (string) Str::uuid();
                            $refundCode = 'RF-RM-' . now()->format('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                            $ticketIdList = array_values(array_unique((array) ($userData['ticket_ids'] ?? [])));
                            $firstTicketId = (string) ($ticketIdList[0] ?? '');

                            DB::table('refunds')->insert([
                                'id' => $refundId,
                                'refund_code' => $refundCode,
                                'user_id' => $userId,
                                'ticket_id' => $firstTicketId !== '' ? $firstTicketId : null,
                                'refund_type' => 'ticket',
                                'reason' => $reason,
                                'reason_code' => 'ROOM_CANCELED',
                                'old_status' => 'USED_IN_ROOM',
                                'new_status' => 'REFUNDED',
                                'source_type' => 'room_cancellation',
                                'source_id' => $lockedRoom->id,
                                'processed_by' => $actor->id,
                                'processed_at' => $now,
                                'metadata' => json_encode([
                                    'room_id' => $lockedRoom->id,
                                    'room_code' => $lockedRoom->room_code,
                                    'ticket_ids' => $ticketIdList,
                                    'ticket_count' => $ticketCount,
                                ], JSON_UNESCAPED_UNICODE),
                                'created_at' => $now,
                            ]);

                            $runningBefore = $balanceBefore;
                            foreach ($ticketIdList as $ticketId) {
                                DB::table('ticket_ledger')->insert([
                                    'id' => (string) Str::uuid(),
                                    'ticket_id' => $ticketId,
                                    'user_id' => $userId,
                                    'action_type' => 'REFUND',
                                    'amount_change' => 1,
                                    'balance_before' => $runningBefore,
                                    'balance_after' => $runningBefore + 1,
                                    'reference_id' => $refundId,
                                    'reference_type' => 'ROOM_CANCELLATION',
                                    'metadata' => json_encode([
                                        'room_id' => $lockedRoom->id,
                                        'room_code' => $lockedRoom->room_code,
                                        'reason' => $reason,
                                    ], JSON_UNESCAPED_UNICODE),
                                    'created_at' => $now,
                                ]);
                                $runningBefore++;
                            }
                        }
                    });

                $lockedRoom->status = 'CANCELED';
                $lockedRoom->draw_started_at = null;
                $lockedRoom->draw_completed_at = null;
                $lockedRoom->save();

                foreach ($refundedPerUser as $userId => $count) {
                    $notificationService->create([
                        'message' => 'Phòng ' . $lockedRoom->room_code . ' đã hủy khẩn cấp. Hoàn ' . $count . ' vé cho user ' . $userId . '. Lý do: ' . $reason,
                        'order_id' => null,
                        'title' => 'Hoàn vé do hủy phòng',
                        'type' => 'ticket',
                        'user_id' => (string) $userId,
                        'store_id' => (string) $lockedRoom->store_id,
                        'audience_scope' => 'user_private',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return [
                    'room_code' => $lockedRoom->room_code,
                    'refunded_tickets' => $totalRefunded,
                    'affected_users' => count($refundedPerUser),
                ];
            });

            return back()->with(
                'success',
                'Đã hủy khẩn cấp phòng ' . $summary['room_code']
                    . '. Hoàn ' . $summary['refunded_tickets'] . ' vé cho '
                    . $summary['affected_users'] . ' người dùng.'
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Hủy phòng thất bại: ' . $e->getMessage());
        }
    }
}
