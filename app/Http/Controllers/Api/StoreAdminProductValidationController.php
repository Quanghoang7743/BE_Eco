<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreAdminProductValidationController extends Controller
{
    public function validateDraft(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $errors = [];

        $basicInfo = (array) ($request->input('basic_info', []));
        $variants = (array) ($request->input('variants', []));
        $pricing = (array) ($request->input('pricing', []));
        $rows = is_array($pricing['rows'] ?? null) ? $pricing['rows'] : [];

        $this->validateCategory($basicInfo, $errors);
        $this->validateTicketRewardQuantity($basicInfo, $errors);
        $this->validateTicketType($basicInfo, $errors);
        $this->validateMedia($store, $basicInfo, $errors);
        $this->validateSku($store, $rows, $errors);
        $this->validateVariantConsistency($variants, $rows, $errors);

        return response()->json([
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ]);
    }

    private function validateCategory(array $basicInfo, array &$errors): void
    {
        if (!Schema::hasTable('product_categories')) {
            return;
        }

        $categoryId = $basicInfo['category_id'] ?? null;
        if ($categoryId === null || $categoryId === '') {
            $errors[] = [
                'path' => 'basic_info.category_id',
                'message' => 'Vui long chon danh muc.',
            ];
            return;
        }

        $exists = DB::table('product_categories')->where('id', $categoryId)->exists();
        if (!$exists) {
            $errors[] = [
                'path' => 'basic_info.category_id',
                'message' => 'Danh muc khong hop le hoac khong ton tai.',
            ];
            return;
        }

    }

    private function validateTicketRewardQuantity(array $basicInfo, array &$errors): void
    {
        if (!array_key_exists('ticket_reward_quantity', $basicInfo)) {
            return;
        }

        $value = $basicInfo['ticket_reward_quantity'];

        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 1000) {
            $errors[] = [
                'path' => 'basic_info.ticket_reward_quantity',
                'message' => 'So luong ve tang phai la so nguyen trong khoang 0-1000.',
            ];
        }
    }

    private function validateTicketType(array $basicInfo, array &$errors): void
    {
        if (!array_key_exists('ticket_type_code', $basicInfo)) {
            return;
        }

        $code = strtoupper(trim((string) ($basicInfo['ticket_type_code'] ?? '')));
        if ($code === '') {
            return;
        }

        $resolved = app(TicketPricingService::class)->resolveTicketType($code);
        if (!$resolved || strtoupper((string) ($resolved['code'] ?? '')) !== $code) {
            $errors[] = [
                'path' => 'basic_info.ticket_type_code',
                'message' => 'Loai ve khong ton tai trong cau hinh he thong.',
            ];
        }
    }

    private function validateMedia(Store $store, array $basicInfo, array &$errors): void
    {
        $allowedPrefixes = $this->allowedProductImagePrefixes($store);

        $thumbnail = $basicInfo['thumbnail'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            $this->validateImageUrl($thumbnail, 'basic_info.thumbnail', $allowedPrefixes, $errors);
        }

        $images = $basicInfo['images'] ?? [];
        if (is_array($images)) {
            foreach ($images as $index => $url) {
                if (!is_string($url) || trim($url) === '') {
                    $errors[] = [
                        'path' => 'basic_info.images.' . $index,
                        'message' => 'URL anh khong hop le.',
                    ];
                    continue;
                }

                $this->validateImageUrl($url, 'basic_info.images.' . $index, $allowedPrefixes, $errors);
            }
        }
    }

    private function validateImageUrl(string $url, string $path, array $allowedPrefixes, array &$errors): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = [
                'path' => $path,
                'message' => 'URL anh khong hop le.',
            ];
            return;
        }

        $pathPart = parse_url($url, PHP_URL_PATH);
        $isAllowed = is_string($pathPart) && collect($allowedPrefixes)
            ->contains(static fn (string $prefix): bool => str_starts_with($pathPart, $prefix));

        if (!$isAllowed) {
            $errors[] = [
                'path' => $path,
                'message' => 'URL anh khong thuoc he thong upload hop le cua cua hang hien tai.',
            ];
        }
    }

    private function allowedProductImagePrefixes(Store $store): array
    {
        $basePrefix = '/storage/uploads/stores/' . $store->id;

        return [
            $basePrefix . '/products/',
            $basePrefix . '/licenses/product_cover/',
            $basePrefix . '/licenses/product_gallery/',
            $basePrefix . '/licenses/variant_option/',
        ];
    }

    private function validateSku(Store $store, array $rows, array &$errors): void
    {
        $seenInPayload = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!$this->isPricingRowEnabled($row)) {
                continue;
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $path = 'pricing.rows.' . $index . '.sku';
            $normalized = mb_strtolower($sku);

            if (isset($seenInPayload[$normalized])) {
                $errors[] = [
                    'path' => $path,
                    'message' => 'SKU khong duoc trung trong payload.',
                ];
                continue;
            }
            $seenInPayload[$normalized] = true;

            if (Schema::hasTable('products') && Schema::hasColumn('products', 'sku')) {
                $productSkuQuery = DB::table('products')->whereRaw('LOWER(sku) = ?', [$normalized]);
                if (Schema::hasColumn('products', 'store_id')) {
                    $productSkuQuery->where('store_id', $store->id);
                }
                if ($productSkuQuery->exists()) {
                    $errors[] = [
                        'path' => $path,
                        'message' => 'SKU khong duoc trung.',
                    ];
                    continue;
                }
            }

            if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'sku')) {
                $variantSkuQuery = DB::table('product_variants as pv')->whereRaw('LOWER(pv.sku) = ?', [$normalized]);

                if (Schema::hasColumn('product_variants', 'store_id')) {
                    $variantSkuQuery->where('pv.store_id', $store->id);
                } elseif (Schema::hasColumn('product_variants', 'product_id') && Schema::hasTable('products') && Schema::hasColumn('products', 'store_id')) {
                    $variantSkuQuery
                        ->join('products as p', 'p.id', '=', 'pv.product_id')
                        ->where('p.store_id', $store->id);
                }

                if ($variantSkuQuery->exists()) {
                    $errors[] = [
                        'path' => $path,
                        'message' => 'SKU khong duoc trung.',
                    ];
                }
            }
        }
    }

    private function validateVariantConsistency(array $variants, array $rows, array &$errors): void
    {
        $group1 = $this->extractOptionValues($variants['group1'] ?? ($variants['group_1'] ?? null));
        $group2 = $this->extractOptionValues($variants['group2'] ?? ($variants['group_2'] ?? null));
        $group3 = $this->extractOptionValues($variants['group3'] ?? ($variants['group_3'] ?? null));

        $hasVariantDimension = count($group1) > 0 || count($group2) > 0 || count($group3) > 0;
        if (!$hasVariantDimension) {
            return;
        }

        $expectedRows = max(1, count($group1)) * max(1, count($group2)) * max(1, count($group3));
        $actualRows = count($rows);

        if ($actualRows !== $expectedRows) {
            $errors[] = [
                'path' => 'pricing.rows',
                'message' => 'So luong pricing rows khong khop voi to hop variants (expected: ' . $expectedRows . ', actual: ' . $actualRows . ').',
            ];
        }

        $hasEnabledRow = false;
        foreach ($rows as $row) {
            if (is_array($row) && $this->isPricingRowEnabled($row)) {
                $hasEnabledRow = true;
                break;
            }
        }

        if (!$hasEnabledRow && $actualRows > 0) {
            $errors[] = [
                'path' => 'pricing.rows',
                'message' => 'Can giu lai it nhat 1 bien the dang bat.',
            ];
        }
    }

    private function isPricingRowEnabled(array $row): bool
    {
        if (array_key_exists('disabled', $row)) {
            $disabled = $this->normalizeBooleanFlag($row['disabled']);
            if ($disabled !== null) {
                return !$disabled;
            }
        }

        foreach (['enabled', 'is_enabled', 'isEnabled', 'active', 'is_active', 'isActive'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $enabled = $this->normalizeBooleanFlag($row[$key]);
            if ($enabled !== null) {
                return $enabled;
            }
        }

        return true;
    }

    private function normalizeBooleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled', 'active'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', 'inactive'], true)) {
                return false;
            }
        }

        return null;
    }

    private function extractOptionValues(mixed $group): array
    {
        if (!is_array($group)) {
            return [];
        }

        $options = $group['options'] ?? $group;
        if (!is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $opt) {
            if (is_array($opt)) {
                $value = $opt['value'] ?? $opt['label'] ?? $opt['name'] ?? null;
            } else {
                $value = $opt;
            }

            if ($value === null) {
                continue;
            }

            $s = trim((string) $value);
            if ($s !== '') {
                $values[] = $s;
            }
        }

        return array_values(array_unique($values));
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
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(string $code, string $message, int $status, bool $hasStore, bool $authenticated, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'valid' => false,
            'errors' => [$error],
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
        ], $status);
    }
}
