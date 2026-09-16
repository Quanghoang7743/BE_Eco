<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreAdminUploadController extends Controller
{
    public function __construct(private readonly StoreAdminStaffAccess $access)
    {
    }

    public function presign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_name' => ['nullable', 'string', 'max:255'],
            'content_type' => ['nullable', 'string', 'max:100'],
            'usage_type' => ['nullable', 'string', 'in:product_cover,product_gallery,variant_option,license_image,license_gallery,store_license'],
            'files' => ['nullable', 'array', 'min:1'],
            'files.*.file_name' => ['required_with:files', 'string', 'max:255'],
            'files.*.content_type' => ['required_with:files', 'string', 'max:100'],
            'files.*.usage_type' => ['nullable', 'string', 'in:product_cover,product_gallery,variant_option,license_image,license_gallery,store_license'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $items = $this->normalizeUploadRequests($validated);

        if (empty($items)) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, [
                'files' => ['Phai truyen it nhat 1 file hoac cap file_name/content_type hop le.'],
            ]);
        }

        $requestedStoreId = $this->resolveRequestedStoreId($request);
        $licenseOnlyRequest = $this->isLicenseUploadRequest($items);

        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            if (!$licenseOnlyRequest) {
                return $error;
            }

            $fallback = $this->resolveRegistrationDraftStore($request, $request->user());
            if (!$fallback) {
                return $error;
            }

            [$store, $user] = [$fallback, $request->user()];
        }

        $permissionError = $this->authorizePresign($user, $store, $items);
        if ($permissionError) {
            return $permissionError;
        }

        $uploadItems = [];
        foreach ($items as $item) {
            try {
                $uploadItems[] = $this->buildPresignItem($store->id, $item['file_name'], $item['content_type'], $item['usage_type']);
            } catch (\InvalidArgumentException $exception) {
                return $this->errorResponse(
                    'UNSUPPORTED_CONTENT_TYPE',
                    'Chi chap nhan cac dinh dang anh jpeg, png, webp, gif.',
                    422,
                    true,
                    true,
                    ['content_type' => $item['content_type']]
                );
            }
        }

        $responseData = [
            'upload_items' => $uploadItems,
            'total' => count($uploadItems),
        ];

        if (count($uploadItems) === 1) {
            $responseData = array_merge($responseData, $uploadItems[0]);
        }

        return response()->json([
            'data' => $responseData,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function presignedPut(Request $request, string $token): JsonResponse
    {
        $payload = Cache::pull($this->cacheKey($token));
        if (!$payload) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'UPLOAD_TOKEN_EXPIRED',
                    'message' => 'Upload URL da het han hoac khong hop le.',
                ]],
            ], 410);
        }

        $binary = $request->getContent();
        if ($binary === '' || $binary === null) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'EMPTY_FILE',
                    'message' => 'Noi dung file rong.',
                ]],
            ], 422);
        }

        $path = (string) ($payload['path'] ?? '');
        $expectedType = (string) ($payload['content_type'] ?? '');
        $actualType = (string) $request->header('Content-Type', '');

        if ($expectedType !== '' && $actualType !== '' && mb_strtolower($expectedType) !== mb_strtolower($actualType)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'CONTENT_TYPE_MISMATCH',
                    'message' => 'Content-Type khong khop voi URL da cap.',
                    'details' => [
                        'expected' => $expectedType,
                        'actual' => $actualType,
                    ],
                ]],
            ], 422);
        }

        Storage::disk('public')->put($path, $binary);

        return response()->json([
            'data' => [
                'public_url' => url(Storage::url($path)),
                'path' => $path,
            ],
            'meta' => [
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function extensionFromMime(string $mime): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        return $map[mb_strtolower($mime)] ?? null;
    }

    private function cacheKey(string $token): string
    {
        return 'store_admin_upload_presign:' . $token;
    }

    private function buildPresignItem(string $storeId, string $fileName, string $contentType, ?string $usageType): array
    {
        $contentType = trim($contentType);
        $allowedContentTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/jpg',
        ];

        if (!in_array(mb_strtolower($contentType), $allowedContentTypes, true)) {
            throw new \InvalidArgumentException('UNSUPPORTED_CONTENT_TYPE');
        }

        $usageType = (string) ($usageType ?: 'license_gallery');
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = $this->extensionFromMime($contentType) ?? 'jpg';
        }

        $safeBaseName = Str::slug($baseName);
        if ($safeBaseName === '') {
            $safeBaseName = 'image';
        }

        $finalFileName = $safeBaseName . '-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(8)) . '.' . $extension;
        $path = $this->buildUploadPath($storeId, $usageType, $finalFileName);
        $disk = 'public';
        $expiresAt = now()->addMinutes(10);
        /** @var FilesystemAdapter $diskAdapter */
        $diskAdapter = Storage::disk($disk);

        if ($diskAdapter instanceof FilesystemAdapter && method_exists($diskAdapter, 'temporaryUploadUrl')) {
            try {
                $temporary = $diskAdapter->temporaryUploadUrl($path, $expiresAt, [
                    'ContentType' => $contentType,
                ]);

                return [
                    'upload_url' => $temporary['url'] ?? null,
                    'upload_headers' => $temporary['headers'] ?? [],
                    'public_url' => url(Storage::url($path)),
                    'path' => $path,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'usage_type' => $usageType,
                    'file_name' => $fileName,
                    'content_type' => $contentType,
                ];
            } catch (\Throwable $e) {
                // Fall back to signed internal upload URL below.
            }
        }

        $token = (string) Str::uuid();
        Cache::put(
            $this->cacheKey($token),
            [
                'path' => $path,
                'content_type' => $contentType,
                'store_id' => $storeId,
            ],
            $expiresAt
        );

        $uploadUrl = URL::temporarySignedRoute(
            'store-admin.uploads.presigned-put',
            $expiresAt,
            ['token' => $token]
        );

        return [
            'upload_url' => $uploadUrl,
            'upload_headers' => [
                'Content-Type' => $contentType,
            ],
            'public_url' => url(Storage::url($path)),
            'path' => $path,
            'expires_at' => $expiresAt->toIso8601String(),
            'usage_type' => $usageType,
            'file_name' => $fileName,
            'content_type' => $contentType,
        ];
    }

    private function normalizeUploadRequests(array $validated): array
    {
        if (!empty($validated['files']) && is_array($validated['files'])) {
            return array_values(array_map(static function (array $file) {
                return [
                    'file_name' => (string) ($file['file_name'] ?? ''),
                    'content_type' => (string) ($file['content_type'] ?? ''),
                    'usage_type' => isset($file['usage_type']) && $file['usage_type'] !== '' ? (string) $file['usage_type'] : null,
                ];
            }, $validated['files']));
        }

        if (!empty($validated['file_name']) && !empty($validated['content_type'])) {
            return [[
                'file_name' => (string) $validated['file_name'],
                'content_type' => (string) $validated['content_type'],
                'usage_type' => isset($validated['usage_type']) && $validated['usage_type'] !== '' ? (string) $validated['usage_type'] : null,
            ]];
        }

        return [];
    }

    private function buildUploadPath(string $storeId, string $usageType, string $fileName): string
    {
        $productUsageFolders = [
            'product_cover' => 'covers',
            'product_gallery' => 'gallery',
            'variant_option' => 'variants',
        ];

        if (isset($productUsageFolders[$usageType])) {
            return "uploads/stores/{$storeId}/products/{$productUsageFolders[$usageType]}/{$fileName}";
        }

        return "uploads/stores/{$storeId}/licenses/{$usageType}/{$fileName}";
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            $user = $request->user();
            $requestedStoreId = $this->resolveRequestedStoreId($request);

            if (
                $user &&
                $requestedStoreId === null &&
                in_array((string) $user->role, ['user', 'account_user'], true)
            ) {
                $store = Store::query()
                    ->where('owner_id', $user->id)
                    ->when(Schema::hasColumn('stores', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                    ->orderByDesc('created_at')
                    ->first();

                if (!$store) {
                    $store = Store::create([
                        'owner_id' => $user->id,
                        'name' => $user->name ?? 'Cua hang moi',
                        'address' => 'Dang cap nhat',
                        'store_code' => 'STORE-' . strtoupper(uniqid()),
                        'status' => 'draft',
                        'is_open' => false,
                    ]);
                }

                return [$store, $user, null];
            }

            return [null, $user, $context['error']];
        }

        $store = $context['store'];
        $user = $context['user'];

        // Auto-create draft store for customers trying to register as partners
        if (!$store && in_array((string) $user->role, ['user', 'account_user'], true)) {
            $store = Store::create([
                'owner_id' => $user->id,
                'name' => $user->name ?? 'Cửa hàng mới',
                'address' => 'Đang cập nhật',
                'store_code' => 'STORE-' . strtoupper(uniqid()),
                'status' => 'draft',
                'is_open' => false,
            ]);
        }

        if (!$store) {
            return [
                null,
                $user,
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false),
            ];
        }

        return [$store, $user, null];
    }

    private function resolveRequestedStoreId(Request $request): ?string
    {
        $storeContextId = trim((string) $request->attributes->get('store_context_id', ''));
        if ($storeContextId !== '') {
            return $storeContextId;
        }

        $storeId = trim((string) ($request->query('store_id') ?: $request->input('store_id') ?: ''));
        if ($storeId !== '') {
            return $storeId;
        }

        $headerStoreId = trim((string) $request->header('X-Store-Id', ''));
        return $headerStoreId !== '' ? $headerStoreId : null;
    }

    private function isLicenseUploadRequest(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            $usageType = trim((string) ($item['usage_type'] ?? 'license_gallery'));
            if (!in_array($usageType, ['license_image', 'license_gallery', 'store_license'], true)) {
                return false;
            }
        }

        return true;
    }

    private function resolveRegistrationDraftStore(Request $request, ?User $user): ?Store
    {
        if (!$user) {
            return null;
        }

        $storeQuery = Store::query()->where('owner_id', $user->id);
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        $store = $storeQuery->orderByDesc('created_at')->first();
        if ($store) {
            return $store;
        }

        return Store::create([
            'owner_id' => $user->id,
            'name' => $user->name ?? 'Cua hang moi',
            'address' => 'Dang cap nhat',
            'store_code' => 'STORE-' . strtoupper(uniqid()),
            'status' => 'draft',
            'is_open' => false,
        ]);
    }

    private function resolveContext(Request $request): array
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);

        return [
            'user' => $user,
            'store' => $store,
            'error' => $error,
        ];
    }

    private function authorizePresign(?User $user, ?Store $store, array $items): ?JsonResponse
    {
        if (!$user || !$store) {
            return null;
        }

        if ($this->hasStoreAdminOverride($user, $store)) {
            return null;
        }

        foreach ($items as $item) {
            $usageType = trim((string) ($item['usage_type'] ?? 'license_gallery'));
            $requiredCodes = $this->requiredPermissionCodesForUsage($usageType);

            if (empty($requiredCodes)) {
                continue;
            }

            if ($this->access->hasAnyPermission((int) $user->id, (string) $store->id, $requiredCodes)) {
                continue;
            }

            return $this->errorResponse(
                'FORBIDDEN_PERMISSION',
                'Ban khong co quyen tai tep cho khu vuc nay trong cua hang hien tai.',
                403,
                true,
                true,
                [
                    'usage_type' => $usageType,
                    'required_permission_codes' => $requiredCodes,
                    'store_id' => (string) $store->id,
                ]
            );
        }

        return null;
    }

    private function requiredPermissionCodesForUsage(string $usageType): array
    {
        return match ($usageType) {
            'product_cover', 'product_gallery', 'variant_option' => ['CREATE_PRODUCT', 'EDIT_MENU'],
            'license_image', 'license_gallery', 'store_license' => ['MANAGE_STORE_LICENSE', 'EDIT_PROFILE'],
            default => [],
        };
    }

    private function hasStoreAdminOverride(User $user, Store $store): bool
    {
        if ((string) $store->owner_id === (string) $user->id) {
            return true;
        }

        return in_array((string) $user->role, ['admin', 'super_admin'], true);
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
