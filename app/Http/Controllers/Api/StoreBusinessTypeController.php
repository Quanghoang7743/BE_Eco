<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBusinessType;
use App\Services\StoreBusinessTypeService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreBusinessTypeController extends Controller
{
    public function __construct(private readonly StoreBusinessTypeService $businessTypeService)
    {
    }

    public function adminIndex(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin($request)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'in:0,1,true,false'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $validated = $validator->validated();

        $query = StoreBusinessType::query()
            ->withCount('stores')
            ->when(! empty($validated['q']), function ($query) use ($validated): void {
                $keyword = trim((string) $validated['q']);
                $query->where(function ($scope) use ($keyword): void {
                    $scope->where('name', 'like', '%' . $keyword . '%')
                        ->orWhere('slug', 'like', '%' . $keyword . '%');
                });
            })
            ->when(array_key_exists('is_active', $validated), function ($query) use ($validated): void {
                $query->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('sort_order')
            ->orderBy('name');

        $items = $query->get();

        return $this->success(
            $items->map(fn (StoreBusinessType $item) => $this->businessTypeService->format($item, true))->values(),
            ['total' => $items->count()]
        );
    }

    public function publicIndex(): JsonResponse
    {
        $items = StoreBusinessType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(
            $items->map(fn (StoreBusinessType $item) => $this->businessTypeService->format($item))->values(),
            ['total' => $items->count()]
        );
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin($request)) {
            return $response;
        }

        $payload = $this->validatePayload($request);
        $slugSource = trim((string) ($payload['slug'] ?? '')) ?: $payload['name'];

        $businessType = StoreBusinessType::query()->create([
            'name' => trim($payload['name']),
            'slug' => $this->businessTypeService->uniqueSlug($slugSource),
            'icon_url' => $this->nullableString($payload['icon_url'] ?? null),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return $this->success($this->businessTypeService->format($businessType), [], 201);
    }

    public function update(Request $request, StoreBusinessType $storeBusinessType): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin($request)) {
            return $response;
        }

        $payload = $this->validatePayload($request, $storeBusinessType->id);
        $slugSource = trim((string) ($payload['slug'] ?? '')) ?: $payload['name'];

        $storeBusinessType->update([
            'name' => trim($payload['name']),
            'slug' => $this->businessTypeService->uniqueSlug($slugSource, (int) $storeBusinessType->id),
            'icon_url' => $this->nullableString($payload['icon_url'] ?? null),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return $this->success($this->businessTypeService->format($storeBusinessType->refresh()));
    }

    public function destroy(Request $request, StoreBusinessType $storeBusinessType): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin($request)) {
            return $response;
        }

        $linkedStoresCount = $this->linkedStoresCount($storeBusinessType);
        if ($linkedStoresCount > 0) {
            return $this->error(
                'BUSINESS_TYPE_IN_USE',
                'Loai hinh kinh doanh dang duoc su dung boi cua hang, khong the xoa.',
                409,
                ['linked_stores_count' => $linkedStoresCount]
            );
        }

        $storeBusinessType->delete();

        return $this->success([
            'id' => (int) $storeBusinessType->id,
            'deleted' => true,
        ]);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('store_business_types', 'name')->ignore($ignoreId),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('store_business_types', 'slug')->ignore($ignoreId),
            ],
            'icon_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException($this->validationError($validator->errors()->toArray()));
        }

        return $validator->validated();
    }

    private function linkedStoresCount(StoreBusinessType $businessType): int
    {
        if (! Schema::hasTable('stores')) {
            return 0;
        }

        $hasBusinessTypeId = Schema::hasColumn('stores', 'business_type_id');
        $hasBusinessTypeName = Schema::hasColumn('stores', 'business_type');

        if (! $hasBusinessTypeId && ! $hasBusinessTypeName) {
            return 0;
        }

        $query = Store::query();
        $query->where(function ($scope) use ($businessType, $hasBusinessTypeId, $hasBusinessTypeName): void {
            if ($hasBusinessTypeId) {
                $scope->where('business_type_id', $businessType->id);
            }

            if ($hasBusinessTypeName) {
                $method = $hasBusinessTypeId ? 'orWhere' : 'where';
                $scope->{$method}('business_type', $businessType->name);
            }
        });

        return $query->count();
    }

    private function ensureSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Ban chua dang nhap.', 401);
        }

        if ((string) ($user->role ?? '') !== 'super_admin') {
            return $this->error('FORBIDDEN_ROLE', 'Chi super_admin moi co quyen quan ly loai hinh kinh doanh.', 403);
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => array_merge([
                'request_at' => now()->toIso8601String(),
            ], $meta),
            'errors' => [],
        ], $status);
    }

    private function error(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => array_merge([
                'request_at' => now()->toIso8601String(),
            ], $meta),
            'errors' => [[
                'code' => $code,
                'message' => $message,
            ]],
        ], $status);
    }

    private function validationError(array $messages): JsonResponse
    {
        $errors = [];
        foreach ($messages as $field => $fieldMessages) {
            foreach ((array) $fieldMessages as $message) {
                $errors[] = [
                    'code' => 'VALIDATION_ERROR',
                    'path' => $field,
                    'message' => (string) $message,
                ];
            }
        }

        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => [
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => $errors,
        ], 422);
    }
}
