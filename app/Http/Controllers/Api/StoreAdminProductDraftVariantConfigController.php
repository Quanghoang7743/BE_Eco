<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreAdminProductDraftVariantConfigController extends Controller
{
    public function upsert(Request $request, string $draft): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('product_drafts') || !Schema::hasTable('product_draft_variant_configs') || !Schema::hasTable('product_draft_variant_options')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang du lieu variant-config chua duoc khoi tao.', 500, true, true);
        }

        $draftRow = DB::table('product_drafts')
            ->where('id', $draft)
            ->where('store_id', $store->id)
            ->first();

        if (!$draftRow) {
            return $this->errorResponse('DRAFT_NOT_FOUND', 'Khong tim thay ban nhap hoac ban nhap khong thuoc cua hang hien tai.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'group_1' => ['nullable', 'array'],
            'group_1.name' => [Rule::requiredIf(fn () => $request->boolean('enabled')), 'string', 'max:120'],
            'group_1.use_image' => ['nullable', 'boolean'],
            'group_1.options' => [Rule::requiredIf(fn () => $request->boolean('enabled')), 'array', 'min:1'],
            'group_1.options.*.value' => [Rule::requiredIf(fn () => $request->boolean('enabled')), 'string', 'max:120'],
            'group_1.options.*.image_url' => ['nullable', 'url', 'max:2048'],
            'group_2' => ['nullable', 'array'],
            'group_2.name' => ['nullable', 'string', 'max:120'],
            'group_2.options' => ['nullable', 'array'],
            'group_2.options.*.value' => ['required_with:group_2.options', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $normalized = $this->normalizeConfigPayload($validator->validated());

        if ($normalized['enabled'] && empty($normalized['group_1']['options'])) {
            return $this->errorResponse('VALIDATION_ERROR', 'Khi bat phan loai, group_1 can co it nhat 1 option.', 422, true, true);
        }

        $group2 = $normalized['group_2'] ?? null;
        if (is_array($group2)) {
            $hasGroup2Name = is_string($group2['name'] ?? null) && trim((string) $group2['name']) !== '';
            $hasGroup2Options = !empty($group2['options'] ?? []);

            if ($hasGroup2Name xor $hasGroup2Options) {
                return $this->errorResponse('VALIDATION_ERROR', 'group_2 phai co day du name va options, hoac de rong/null.', 422, true, true);
            }
        }

        DB::transaction(function () use ($draft, $normalized): void {
            $now = now();

            $existingConfigIds = DB::table('product_draft_variant_configs')
                ->where('draft_id', $draft)
                ->pluck('id')
                ->all();

            if (!empty($existingConfigIds)) {
                DB::table('product_draft_variant_options')->whereIn('config_id', $existingConfigIds)->delete();
            }

            DB::table('product_draft_variant_configs')->where('draft_id', $draft)->delete();

            $configId = (string) Str::uuid();
            DB::table('product_draft_variant_configs')->insert([
                'id' => $configId,
                'draft_id' => $draft,
                'enabled' => $normalized['enabled'] ? 1 : 0,
                'group_1_name' => $normalized['group_1']['name'],
                'group_1_use_image' => $normalized['group_1']['use_image'] ? 1 : 0,
                'group_2_name' => $normalized['group_2']['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = [];
            foreach ($normalized['group_1']['options'] as $index => $option) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'config_id' => $configId,
                    'group_no' => 1,
                    'option_value' => $option['value'],
                    'image_url' => $option['image_url'],
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($normalized['group_2']['options'] as $index => $option) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'config_id' => $configId,
                    'group_no' => 2,
                    'option_value' => $option['value'],
                    'image_url' => null,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('product_draft_variant_options')->insert($rows);
            }

            DB::table('product_drafts')
                ->where('id', $draft)
                ->update([
                    'current_step' => 2,
                    'updated_at' => $now,
                ]);
        });

        $data = $this->buildResponseData($draft, $normalized);

        return response()->json([
            'data' => $data,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function show(Request $request, string $draft): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('product_drafts') || !Schema::hasTable('product_draft_variant_configs') || !Schema::hasTable('product_draft_variant_options')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang du lieu variant-config chua duoc khoi tao.', 500, true, true);
        }

        $draftRow = DB::table('product_drafts')
            ->where('id', $draft)
            ->where('store_id', $store->id)
            ->first();

        if (!$draftRow) {
            return $this->errorResponse('DRAFT_NOT_FOUND', 'Khong tim thay ban nhap hoac ban nhap khong thuoc cua hang hien tai.', 404, true, true);
        }

        $configRow = DB::table('product_draft_variant_configs')
            ->where('draft_id', $draft)
            ->orderByDesc('updated_at')
            ->first();

        if (!$configRow) {
            $data = $this->buildResponseData($draft, [
                'enabled' => false,
                'group_1' => ['name' => null, 'use_image' => false, 'options' => []],
                'group_2' => null,
            ]);

            return response()->json([
                'data' => $data,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $optionRows = DB::table('product_draft_variant_options')
            ->where('config_id', $configRow->id)
            ->orderBy('group_no')
            ->orderBy('sort_order')
            ->get();

        $group1Options = [];
        $group2Options = [];

        foreach ($optionRows as $optionRow) {
            $item = [
                'value' => (string) $optionRow->option_value,
            ];

            if ((int) $optionRow->group_no === 1) {
                $item['image_url'] = $optionRow->image_url ? (string) $optionRow->image_url : null;
                $group1Options[] = $item;
            }

            if ((int) $optionRow->group_no === 2) {
                $group2Options[] = $item;
            }
        }

        $normalized = [
            'enabled' => (bool) $configRow->enabled,
            'group_1' => [
                'name' => $configRow->group_1_name ? (string) $configRow->group_1_name : null,
                'use_image' => (bool) $configRow->group_1_use_image,
                'options' => $group1Options,
            ],
            'group_2' => [
                'name' => $configRow->group_2_name ? (string) $configRow->group_2_name : null,
                'options' => $group2Options,
            ],
        ];

        $data = $this->buildResponseData($draft, $normalized);

        return response()->json([
            'data' => $data,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function buildResponseData(string $draftId, array $normalized): array
    {
        $group2 = $normalized['group_2'] ?? null;
        if (is_array($group2)) {
            $group2Name = isset($group2['name']) ? trim((string) $group2['name']) : '';
            $group2Options = $group2['options'] ?? [];
            if ($group2Name === '' && empty($group2Options)) {
                $group2 = null;
            }
        }

        return [
            'draft_id' => $draftId,
            'enabled' => (bool) $normalized['enabled'],
            'group_1' => $normalized['group_1'],
            'group_2' => $group2,
            'variant_matrix' => $this->buildVariantMatrix($normalized),
        ];
    }

    private function buildVariantMatrix(array $normalized): array
    {
        if (!$normalized['enabled']) {
            return [];
        }

        $group1Options = $normalized['group_1']['options'] ?? [];
        $group2 = $normalized['group_2'] ?? null;
        $group2Options = is_array($group2) ? ($group2['options'] ?? []) : [];

        if (empty($group1Options)) {
            return [];
        }

        $matrix = [];
        $usedKeys = [];

        if (empty($group2Options)) {
            foreach ($group1Options as $g1Index => $g1) {
                $key = $this->makeStableVariantKey((string) $g1['value'], null, $g1Index, null, $usedKeys);
                $matrix[] = [
                    'key' => $key,
                    'label' => (string) $g1['value'],
                    'group_1_value' => (string) $g1['value'],
                    'group_2_value' => null,
                ];
            }

            return $matrix;
        }

        foreach ($group1Options as $g1Index => $g1) {
            foreach ($group2Options as $g2Index => $g2) {
                $key = $this->makeStableVariantKey((string) $g1['value'], (string) $g2['value'], $g1Index, $g2Index, $usedKeys);
                $matrix[] = [
                    'key' => $key,
                    'label' => (string) $g1['value'] . ' / ' . (string) $g2['value'],
                    'group_1_value' => (string) $g1['value'],
                    'group_2_value' => (string) $g2['value'],
                ];
            }
        }

        return $matrix;
    }

    private function makeStableVariantKey(string $group1Value, ?string $group2Value, int $group1Index, ?int $group2Index, array &$usedKeys): string
    {
        $part1 = Str::slug(trim($group1Value), '-');
        if ($part1 === '') {
            $part1 = 'group1-' . $group1Index;
        }

        $baseKey = $part1;
        if ($group2Value !== null) {
            $part2 = Str::slug(trim($group2Value), '-');
            if ($part2 === '') {
                $part2 = 'group2-' . (int) $group2Index;
            }
            $baseKey .= '__' . $part2;
        }

        if (!isset($usedKeys[$baseKey])) {
            $usedKeys[$baseKey] = 0;
            return $baseKey;
        }

        $usedKeys[$baseKey]++;
        return $baseKey . '__' . $usedKeys[$baseKey];
    }

    private function normalizeConfigPayload(array $validated): array
    {
        $enabled = (bool) ($validated['enabled'] ?? false);

        $group1 = $validated['group_1'] ?? [];
        $group2 = $validated['group_2'] ?? null;

        $normalizedGroup1Options = [];
        foreach (($group1['options'] ?? []) as $option) {
            $value = trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $imageUrl = $option['image_url'] ?? null;
            $imageUrl = is_string($imageUrl) ? trim($imageUrl) : null;
            if ($imageUrl === '') {
                $imageUrl = null;
            }

            $normalizedGroup1Options[] = [
                'value' => $value,
                'image_url' => $imageUrl,
            ];
        }

        $normalizedGroup2Options = [];
        foreach ((is_array($group2) ? ($group2['options'] ?? []) : []) as $option) {
            $value = trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalizedGroup2Options[] = [
                'value' => $value,
            ];
        }

        return [
            'enabled' => $enabled,
            'group_1' => [
                'name' => isset($group1['name']) ? trim((string) $group1['name']) : null,
                'use_image' => (bool) ($group1['use_image'] ?? false),
                'options' => $normalizedGroup1Options,
            ],
            'group_2' => is_array($group2)
                ? [
                    'name' => isset($group2['name']) ? trim((string) $group2['name']) : null,
                    'options' => $normalizedGroup2Options,
                ]
                : null,
        ];
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
        }

        if (!$context['store']) {
            return [
                null,
                $context['user'],
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(string $code, string $message, int $status, bool $authenticated, bool $hasStore, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
