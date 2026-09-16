<?php

namespace App\Http\Controllers;

use App\Models\banner as Banner;
use App\Models\Room;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BannerController extends Controller
{
    private const BANNER_TYPE_IMAGE_ONLY = 'image_only';
    private const BANNER_TYPE_ROOM_LINK = 'room_link';
    private const BANNER_TYPE_PROMO = 'promo';

    private const ROOM_SELECTION_SELECTED = 'selected_room';
    private const ROOM_SELECTION_TOP_VOTED = 'top_voted';

    private const BANNER_TYPE_OPTIONS = [
        self::BANNER_TYPE_ROOM_LINK => '1. Phòng cố định (không chuyển động)',
        self::BANNER_TYPE_IMAGE_ONLY => '2. Slider ảnh',
        self::BANNER_TYPE_PROMO => '3. Slider nội dung',
    ];

    private const ROOM_SELECTION_MODE_OPTIONS = [
        self::ROOM_SELECTION_SELECTED => 'Chọn phòng cụ thể',
        self::ROOM_SELECTION_TOP_VOTED => 'Tự lấy phòng nhiều vote nhất (hôm nay, chưa quay)',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $banners = Banner::query()
            ->orderBy('order')
            ->orderByDesc('id')
            ->get();

        $homeBanners = $banners->filter(fn ($banner) => (string) $banner->position === '1')->values();
        $activeHomeBanner = $homeBanners->first(fn ($banner) => (bool) $banner->status === true);
        $activeBannerType = $activeHomeBanner?->banner_type;

        $typeStats = collect(array_keys(self::BANNER_TYPE_OPTIONS))
            ->mapWithKeys(function (string $type) use ($homeBanners) {
                $typeBanners = $homeBanners->filter(fn ($banner) => (string) $banner->banner_type === $type);

                return [
                    $type => [
                        'total' => $typeBanners->count(),
                        'active' => $typeBanners->filter(fn ($banner) => (bool) $banner->status === true)->count(),
                    ],
                ];
            })
            ->all();

        return view('admin.banners.index', [
            'banners' => $banners,
            'bannerTypeOptions' => self::BANNER_TYPE_OPTIONS,
            'activeBannerType' => $activeBannerType,
            'typeStats' => $typeStats,
        ]);
    }

    public function switchDisplayType(Request $request)
    {
        if (!$request->isMethod('patch')) {
            return redirect()
                ->route('admin.banners.index')
                ->with('error', 'Đường dẫn này chỉ dùng thao tác qua nút chọn loại hiển thị. Vui lòng chọn 1 trong 3 nút ở trang Banner.');
        }

        $validated = $request->validate([
            'banner_type' => ['required', Rule::in(array_keys(self::BANNER_TYPE_OPTIONS))],
        ]);

        $selectedType = (string) $validated['banner_type'];
        $selectedLabel = self::BANNER_TYPE_OPTIONS[$selectedType] ?? $selectedType;

        $selectedTypeCount = Banner::query()
            ->whereIn('position', ['1', 1])
            ->where('banner_type', $selectedType)
            ->count();

        if ($selectedTypeCount <= 0) {
            return redirect()
                ->route('admin.banners.index')
                ->with('error', 'Chưa có banner thuộc loại "' . $selectedLabel . '" để kích hoạt.');
        }

        Banner::query()
            ->whereIn('position', ['1', 1])
            ->where('banner_type', $selectedType)
            ->update(['status' => true]);

        Banner::query()
            ->whereIn('position', ['1', 1])
            ->where(function ($query) use ($selectedType) {
                $query->whereNull('banner_type')
                    ->orWhere('banner_type', '!=', $selectedType);
            })
            ->update(['status' => false]);

        return redirect()
            ->route('admin.banners.index')
            ->with('success', 'Đã chuyển sang loại hiển thị: ' . $selectedLabel . '. Các loại khác đã tự tắt.');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.banners.create', $this->buildFormViewData());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validateBannerRequest($request, false);

        $banner = new Banner();
        $this->fillBannerFromRequest($banner, $request, $validated, false);

        $banner->save();

        return redirect()->route('admin.banners.index')->with('success', 'Banner đã được thêm thành công.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Banner $banner)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Banner $banner)
    {
        return view('admin.banners.edit', array_merge(
            ['banner' => $banner],
            $this->buildFormViewData()
        ));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Banner $banner)
    {
        $validated = $this->validateBannerRequest($request, true);
        $this->fillBannerFromRequest($banner, $request, $validated, true);

        $banner->save();

        return redirect()->route('admin.banners.index')->with('success', 'Cập nhật banner thành công!');
   
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Banner $banner)
    {
        if ($banner->image && Storage::exists('public/' . $banner->image)) {
            Storage::delete('public/' . $banner->image);
        }

        $banner->delete();

        return redirect()->route('admin.banners.index')->with('success', 'Đã xoá banner thành công.');
    }

    public function toggleStatus($id)
{
    $banner = Banner::findOrFail($id);
    $banner->status = !$banner->status; // Đảo ngược trạng thái
    $banner->save();

    return redirect()->back()->with('success', 'Cập nhật trạng thái thành công!');
}

    private function buildFormViewData(): array
    {
        return [
            'bannerTypeOptions' => self::BANNER_TYPE_OPTIONS,
            'roomSelectionModeOptions' => self::ROOM_SELECTION_MODE_OPTIONS,
            'roomOptions' => $this->getRoomOptions(),
        ];
    }

    private function getRoomOptions(): Collection
    {
        return Room::query()
            ->orderByDesc('room_date')
            ->orderByDesc('room_number')
            ->limit(120)
            ->get([
                'id',
                'room_date',
                'room_number',
                'status',
                'current_votes',
                'required_votes',
            ]);
    }

    private function validateBannerRequest(Request $request, bool $isUpdate): array
    {
        $imageRule = $isUpdate
            ? ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:4096']
            : ['required', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:4096'];

        $validated = $request->validate([
            'banner_type' => ['required', Rule::in(array_keys(self::BANNER_TYPE_OPTIONS))],
            'status' => ['required', 'boolean'],
            'order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'title' => ['nullable', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1200'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'link' => ['nullable', 'string', 'max:500'],
            'room_selection_mode' => ['nullable', Rule::in(array_keys(self::ROOM_SELECTION_MODE_OPTIONS))],
            'room_id' => ['nullable', 'string', 'max:100'],
            'image' => $imageRule,
        ]);

        $bannerType = (string) ($validated['banner_type'] ?? self::BANNER_TYPE_PROMO);
        $roomSelectionMode = (string) ($validated['room_selection_mode'] ?? self::ROOM_SELECTION_SELECTED);

        if ($bannerType === self::BANNER_TYPE_ROOM_LINK) {
            if (!in_array($roomSelectionMode, array_keys(self::ROOM_SELECTION_MODE_OPTIONS), true)) {
                $roomSelectionMode = self::ROOM_SELECTION_SELECTED;
            }

            $validated['room_selection_mode'] = $roomSelectionMode;

            if ($roomSelectionMode === self::ROOM_SELECTION_TOP_VOTED) {
                return $validated;
            }

            $roomId = trim((string) ($validated['room_id'] ?? ''));

            if ($roomId === '') {
                throw ValidationException::withMessages([
                    'room_id' => 'Vui lòng chọn phòng quay cho loại banner này.',
                ]);
            }

            $roomExists = Room::query()->whereKey($roomId)->exists();
            if (! $roomExists) {
                throw ValidationException::withMessages([
                    'room_id' => 'Phòng quay đã chọn không tồn tại hoặc đã bị xoá.',
                ]);
            }
        }

        if ($bannerType === self::BANNER_TYPE_PROMO) {
            $hasAnyContent = trim((string) ($validated['title'] ?? '')) !== ''
                || trim((string) ($validated['headline'] ?? '')) !== ''
                || trim((string) ($validated['description'] ?? '')) !== '';

            if (! $hasAnyContent) {
                throw ValidationException::withMessages([
                    'title' => 'Banner nội dung cần có ít nhất 1 trường chữ (tiêu đề, headline hoặc mô tả).',
                ]);
            }
        }

        return $validated;
    }

    private function fillBannerFromRequest(Banner $banner, Request $request, array $validated, bool $isUpdate): void
    {
        $bannerType = (string) ($validated['banner_type'] ?? self::BANNER_TYPE_PROMO);
        $title = trim((string) ($validated['title'] ?? ''));
        $headline = trim((string) ($validated['headline'] ?? ''));
        $description = trim((string) ($validated['description'] ?? ''));
        $ctaLabel = trim((string) ($validated['cta_label'] ?? ''));
        $link = trim((string) ($validated['link'] ?? ''));
        $roomSelectionMode = trim((string) ($validated['room_selection_mode'] ?? self::ROOM_SELECTION_SELECTED));
        $roomId = trim((string) ($validated['room_id'] ?? ''));

        $banner->banner_type = $bannerType;
        // Hide display-position from CMS and always keep home position.
        $banner->position = '1';
        $banner->status = (bool) ($validated['status'] ?? true);
        $banner->order = (int) ($validated['order'] ?? ($banner->order ?: 1));

        if ($bannerType === self::BANNER_TYPE_IMAGE_ONLY) {
            $banner->title = $title !== '' ? $title : 'Slider ảnh';
            $banner->headline = null;
            $banner->description = null;
            $banner->cta_label = null;
            $banner->room_selection_mode = null;
            $banner->room_id = null;
            $banner->link = $link !== '' ? $link : null;
        } elseif ($bannerType === self::BANNER_TYPE_ROOM_LINK) {
            $resolvedRoomSelectionMode = $roomSelectionMode === self::ROOM_SELECTION_TOP_VOTED
                ? self::ROOM_SELECTION_TOP_VOTED
                : self::ROOM_SELECTION_SELECTED;

            $banner->title = $title !== '' ? $title : 'Phòng quay cố định';
            $banner->headline = null;
            $banner->description = null;
            $banner->cta_label = $ctaLabel !== '' ? $ctaLabel : 'Xem phòng quay';
            $banner->room_selection_mode = $resolvedRoomSelectionMode;
            $banner->room_id = $resolvedRoomSelectionMode === self::ROOM_SELECTION_SELECTED && $roomId !== ''
                ? $roomId
                : null;
            if ($link !== '') {
                $banner->link = $link;
            } elseif ($resolvedRoomSelectionMode === self::ROOM_SELECTION_SELECTED && $banner->room_id) {
                $banner->link = '/draw-room?roomId=' . $banner->room_id;
            } else {
                // Let API resolve the current top-voted room dynamically.
                $banner->link = null;
            }
        } else {
            $banner->title = $title !== '' ? $title : 'Slider nội dung';
            $banner->headline = $headline !== '' ? $headline : null;
            $banner->description = $description !== '' ? $description : null;
            $banner->cta_label = $ctaLabel !== '' ? $ctaLabel : null;
            $banner->room_selection_mode = null;
            $banner->room_id = null;
            $banner->link = $link !== '' ? $link : null;
        }

        if ($request->hasFile('image')) {
            if ($banner->image && Storage::exists('public/' . $banner->image)) {
                Storage::delete('public/' . $banner->image);
            }

            $banner->image = $request->file('image')->store('banners', 'public');
            return;
        }

        if (! $isUpdate && empty($banner->image)) {
            throw ValidationException::withMessages([
                'image' => 'Vui lòng chọn ảnh banner.',
            ]);
        }
    }

}
