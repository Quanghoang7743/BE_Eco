<?php

namespace App\Http\Controllers;

use App\Models\PrizeTemplate;
use App\Models\Reward;
use App\Models\RoomSchedule;
use App\Models\Voucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrizeSettingController extends Controller
{
    public function index()
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();

        $prizeTemplates = PrizeTemplate::query()
            ->orderByDesc('is_active')
            ->orderBy('prize_name')
            ->get(['id', 'prize_name', 'monetary_value', 'image_path', 'description', 'is_active']);

        $vouchers = Voucher::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get(['id', 'code', 'description']);

        $rewards = Reward::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'value', 'quantity']);

        return view('admin.prize_settings.index', compact('schedule', 'vouchers', 'rewards', 'prizeTemplates'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'default_prize_template_id' => ['nullable', 'integer', 'min:1'],
            'default_prize_type' => ['required', 'string', 'max:100'],
            'default_prize_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'default_prize_description' => ['nullable', 'string', 'max:2000'],
            'default_prize_image_file' => ['nullable', 'image', 'max:5120'],
            'prize_template_allocations' => ['nullable', 'array'],
            'prize_template_allocations.*.start_room' => ['required_with:prize_template_allocations', 'integer', 'min:1', 'max:10000'],
            'prize_template_allocations.*.end_room' => ['required_with:prize_template_allocations', 'integer', 'min:1', 'max:10000'],
            'prize_template_allocations.*.prize_template_id' => ['required_with:prize_template_allocations', 'integer', 'min:1'],
            'secondary_prizes' => ['nullable', 'array'],
            'secondary_prizes.*.type' => ['required_with:secondary_prizes', 'string', 'in:voucher,reward'],
            'secondary_prizes.*.id' => ['required_with:secondary_prizes', 'integer', 'min:1'],
            'secondary_prizes.*.qty' => ['required_with:secondary_prizes', 'integer', 'min:1', 'max:1000000'],
        ]);

        $defaultTemplateId = !empty($validated['default_prize_template_id'])
            ? (int) $validated['default_prize_template_id']
            : null;

        $allocationRows = collect((array) ($validated['prize_template_allocations'] ?? []))
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
            ->sortBy('start_room')
            ->values()
            ->all();

        foreach ($allocationRows as $idx => $row) {
            if ((int) $row['end_room'] < (int) $row['start_room']) {
                return redirect()->back()->withInput()->withErrors([
                    'prize_template_allocations.' . $idx . '.end_room' => 'Phòng kết thúc phải lớn hơn hoặc bằng phòng bắt đầu.',
                ]);
            }
        }

        for ($i = 0; $i < count($allocationRows); $i++) {
            for ($j = $i + 1; $j < count($allocationRows); $j++) {
                $a = $allocationRows[$i];
                $b = $allocationRows[$j];

                if ((int) $a['start_room'] <= (int) $b['end_room'] && (int) $b['start_room'] <= (int) $a['end_room']) {
                    return redirect()->back()->withInput()->withErrors([
                        'prize_template_allocations' => 'Các dải phòng phân bổ giải thưởng không được chồng lấn nhau.',
                    ]);
                }
            }
        }

        $templateIds = collect($allocationRows)
            ->pluck('prize_template_id')
            ->push($defaultTemplateId)
            ->filter(static fn ($value) => $value !== null)
            ->map(static fn ($value) => (int) $value)
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

        if ($defaultTemplateId !== null && !$templateMap->has($defaultTemplateId)) {
            return redirect()->back()->withInput()->withErrors([
                'default_prize_template_id' => 'Mẫu giải thưởng mặc định không tồn tại hoặc đang ngừng hoạt động.',
            ]);
        }

        foreach ($allocationRows as $index => $row) {
            $templateId = (int) ($row['prize_template_id'] ?? 0);
            if (!$templateMap->has($templateId)) {
                return redirect()->back()->withInput()->withErrors([
                    'prize_template_allocations.' . $index . '.prize_template_id' => 'Mẫu giải thưởng trong phân bổ không tồn tại hoặc đang ngừng hoạt động.',
                ]);
            }
        }

        $secondaryPrizesInput = array_values($validated['secondary_prizes'] ?? []);

        $voucherIds = [];
        $rewardIds = [];
        foreach ($secondaryPrizesInput as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (($row['type'] ?? null) === 'voucher') {
                $voucherIds[] = (int) ($row['id'] ?? 0);
            }
            if (($row['type'] ?? null) === 'reward') {
                $rewardIds[] = (int) ($row['id'] ?? 0);
            }
        }

        $vouchers = empty($voucherIds)
            ? collect()
            : Voucher::query()->whereIn('id', array_values(array_unique($voucherIds)))->get(['id', 'code', 'description']);
        $rewards = empty($rewardIds)
            ? collect()
            : Reward::query()->whereIn('id', array_values(array_unique($rewardIds)))->get(['id', 'name']);

        $voucherMap = $vouchers->keyBy('id');
        $rewardMap = $rewards->keyBy('id');

        $normalizedSecondaryPrizes = [];
        foreach ($secondaryPrizesInput as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $id = (int) ($row['id'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);

            if ($type === 'voucher') {
                $voucher = $voucherMap->get($id);
                if (!$voucher) {
                    return redirect()->back()->withInput()->withErrors([
                        'secondary_prizes.' . $index . '.id' => 'Voucher khong ton tai hoac da bi xoa.',
                    ]);
                }

                $normalizedSecondaryPrizes[] = [
                    'type' => 'voucher',
                    'id' => $id,
                    'qty' => $qty,
                    'name' => trim((string) ($voucher->code . ' - ' . ($voucher->description ?? 'Voucher'))),
                ];
                continue;
            }

            if ($type === 'reward') {
                $reward = $rewardMap->get($id);
                if (!$reward) {
                    return redirect()->back()->withInput()->withErrors([
                        'secondary_prizes.' . $index . '.id' => 'Qua tang khong ton tai hoac da bi xoa.',
                    ]);
                }

                $normalizedSecondaryPrizes[] = [
                    'type' => 'reward',
                    'id' => $id,
                    'qty' => $qty,
                    'name' => (string) ($reward->name ?? ('Reward #' . $id)),
                ];
            }
        }

        $schedule = RoomSchedule::query()->findOrFail($id);
        $schedule->default_prize_template_id = $defaultTemplateId;

        if ($defaultTemplateId !== null) {
            $defaultTemplate = $templateMap->get($defaultTemplateId);
            $schedule->default_prize_type = (string) ($defaultTemplate->prize_name ?? $validated['default_prize_type']);
            $schedule->default_prize_value = $defaultTemplate->monetary_value !== null ? (float) $defaultTemplate->monetary_value : null;
            $schedule->default_prize_description = $defaultTemplate->description !== null ? (string) $defaultTemplate->description : null;
            $schedule->default_prize_image_url = $defaultTemplate->image_path !== null ? (string) $defaultTemplate->image_path : null;
        } else {
            $defaultPrizeImageUrl = (string) ($schedule->default_prize_image_url ?? '');
            if ($request->hasFile('default_prize_image_file')) {
                $imagePath = $request->file('default_prize_image_file')->store('uploads/prize-settings/default', 'public');
                $defaultPrizeImageUrl = Storage::url($imagePath);
            }

            $schedule->default_prize_type = $validated['default_prize_type'];
            $schedule->default_prize_value = $validated['default_prize_value'] ?? null;
            $schedule->default_prize_description = $validated['default_prize_description'] ?? null;
            $schedule->default_prize_image_url = $defaultPrizeImageUrl !== '' ? $defaultPrizeImageUrl : null;
        }

        $schedule->prize_template_allocations = $allocationRows;
        $schedule->secondary_prizes = $normalizedSecondaryPrizes;
        $schedule->save();

        return redirect()
            ->route('admin.prize-settings.index')
            ->with('success', 'Cập nhật cấu hình giải thưởng (giải chính và giải phụ) thành công.');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'prize_name' => ['required', 'string', 'max:150'],
            'monetary_value' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'image_file' => ['nullable', 'image', 'max:5120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $imageUrl = null;
        if ($request->hasFile('image_file')) {
            $imagePath = $request->file('image_file')->store('uploads/prize-settings/templates', 'public');
            $imageUrl = Storage::url($imagePath);
        }

        PrizeTemplate::query()->create([
            'prize_name' => trim((string) $validated['prize_name']),
            'monetary_value' => (float) $validated['monetary_value'],
            'image_path' => $imageUrl,
            'description' => isset($validated['description']) && trim((string) $validated['description']) !== '' ? trim((string) $validated['description']) : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return redirect()->route('admin.prize-settings.index')->with('success', 'Đã thêm mẫu giải thưởng mới.');
    }

    public function updateTemplate(Request $request, int $templateId): RedirectResponse
    {
        $validated = $request->validate([
            'prize_name' => ['required', 'string', 'max:150'],
            'monetary_value' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'image_file' => ['nullable', 'image', 'max:5120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template = PrizeTemplate::query()->findOrFail($templateId);
        $imageUrl = (string) ($template->image_path ?? '');
        if ($request->hasFile('image_file')) {
            $imagePath = $request->file('image_file')->store('uploads/prize-settings/templates', 'public');
            $imageUrl = Storage::url($imagePath);
        }

        $template->prize_name = trim((string) $validated['prize_name']);
        $template->monetary_value = (float) $validated['monetary_value'];
        $template->image_path = $imageUrl !== '' ? $imageUrl : null;
        $template->description = isset($validated['description']) && trim((string) $validated['description']) !== '' ? trim((string) $validated['description']) : null;
        $template->is_active = (bool) ($validated['is_active'] ?? false);
        $template->save();

        return redirect()->route('admin.prize-settings.index')->with('success', 'Đã cập nhật mẫu giải thưởng.');
    }

    public function deleteTemplate(int $templateId): RedirectResponse
    {
        $template = PrizeTemplate::query()->findOrFail($templateId);

        $isDefault = RoomSchedule::query()->where('prize_template_id', $templateId)->exists();
        $isInAllocation = RoomSchedule::query()
            ->get(['id', 'last_run_message'])
            ->contains(function (RoomSchedule $schedule) use ($templateId): bool {
                foreach ($schedule->prize_template_allocations as $rule) {
                    if ((int) ($rule['prize_template_id'] ?? 0) === $templateId) {
                        return true;
                    }
                }

                return false;
            });

        if ($isDefault || $isInAllocation) {
            return redirect()->route('admin.prize-settings.index')->withErrors([
                'template' => 'Mẫu giải thưởng đang được dùng trong cấu hình phòng, không thể xóa.',
            ]);
        }

        $template->delete();

        return redirect()->route('admin.prize-settings.index')->with('success', 'Đã xóa mẫu giải thưởng.');
    }
}
