<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class StoreAdminLicenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_licenses')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $items = StoreLicense::query()
            ->where('store_id', $store->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $items->map(fn (StoreLicense $item) => $this->serializeLicense($item))->values()->all(),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_licenses')) {
            return $this->errorResponse('STORE_LICENSES_NOT_SUPPORTED', 'Bang store_licenses chua duoc tao.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'license_type' => ['required', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'url', 'max:2000'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();

        $license = new StoreLicense();
        $license->store_id = $store->id;
        $license->license_type = $validated['license_type'];
        $license->license_number = $validated['license_number'] ?? null;
        $license->image_url = $validated['image_url'] ?? null;
        $license->expiry_date = $validated['expiry_date'] ?? null;
        $license->status = 'pending';
        $license->save();

        return response()->json([
            'data' => $this->serializeLicense($license),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_licenses')) {
            return $this->errorResponse('STORE_LICENSES_NOT_SUPPORTED', 'Bang store_licenses chua duoc tao.', 422, true, true);
        }

        $license = StoreLicense::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();

        if (!$license) {
            return $this->errorResponse('LICENSE_NOT_FOUND', 'Khong tim thay giay phep can cap nhat.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'license_type' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'url', 'max:2000'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $dirty = false;

        foreach (['license_type', 'license_number', 'image_url', 'expiry_date'] as $field) {
            if (array_key_exists($field, $validated)) {
                $license->{$field} = $validated[$field];
                $dirty = true;
            }
        }

        if ($dirty) {
            $license->status = 'pending';
            $license->rejection_reason = null;
            $license->save();
        }

        return response()->json([
            'data' => $this->serializeLicense($license),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_licenses')) {
            return $this->errorResponse('STORE_LICENSES_NOT_SUPPORTED', 'Bang store_licenses chua duoc tao.', 422, true, true);
        }

        $license = StoreLicense::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();

        if (!$license) {
            return $this->errorResponse('LICENSE_NOT_FOUND', 'Khong tim thay giay phep can xoa.', 404, true, true);
        }

        $license->delete();

        return response()->json([
            'data' => [
                'id' => $id,
                'deleted' => true,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function serializeLicense(StoreLicense $license): array
    {
        return [
            'id' => (string) $license->id,
            'type' => (string) $license->license_type,
            'number' => (string) ($license->license_number ?? ''),
            'image' => (string) ($license->image_url ?? ''),
            'status' => (string) ($license->status ?? 'pending'),
            'rejection_reason' => (string) ($license->rejection_reason ?? ''),
            'expiry_date' => $license->expiry_date ? $license->expiry_date->toDateString() : null,
            'created_at' => optional($license->created_at)?->toIso8601String(),
            'updated_at' => optional($license->updated_at)?->toIso8601String(),
        ];
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
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
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$store, $user, null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        bool $hasStore,
        bool $authenticated,
        ?array $details = null
    ): JsonResponse {
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
