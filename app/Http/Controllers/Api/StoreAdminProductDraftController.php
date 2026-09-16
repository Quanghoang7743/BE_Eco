<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductDraft;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class StoreAdminProductDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('product_drafts')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang product_drafts chua ton tai.', 500, true, true);
        }

        $validator = Validator::make($request->all(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);
        $skip = ($page - 1) * $pageSize;

        $query = ProductDraft::query()
            ->where('store_id', $store->id)
            ->orderByDesc('updated_at');

        $total = $query->count();
        $drafts = $query
            ->skip($skip)
            ->take($pageSize)
            ->get();

        $items = $drafts->map(function (ProductDraft $draft) {
            $payload = is_array($draft->payload) ? $draft->payload : [];

            // Hỗ trợ cả 2 format: basic_info (snake_case) và basicInfo (camelCase)
            $basicInfo = $payload['basicInfo'] ?? $payload['basic_info'] ?? [];
            $name = $basicInfo['name'] ?? 'Chưa đặt tên';

            return [
                'id' => (string) $draft->id,
                'name' => $name,
                'current_step' => (int) $draft->current_step,
                'payload' => $payload,
                'updated_at' => optional($draft->updated_at)?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $items->toArray(),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $pageSize),
                ],
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

        if (!Schema::hasTable('product_drafts')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang product_drafts chua ton tai.', 500, true, true);
        }

        // Draft endpoint intentionally allows partial payload without strict full-product validation.
        $validator = Validator::make($request->all(), [
            'current_step' => ['nullable', 'integer', 'min:1', 'max:10'],
            'payload' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $draft = ProductDraft::query()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'current_step' => (int) ($validated['current_step'] ?? 1),
            'payload' => $validated['payload'] ?? $request->except(['current_step']),
        ]);

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'current_step' => (int) $draft->current_step,
                'updated_at' => optional($draft->updated_at)?->toIso8601String(),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('product_drafts')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang product_drafts chua ton tai.', 500, true, true);
        }

        $draft = ProductDraft::query()
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$draft) {
            return $this->errorResponse('DRAFT_NOT_FOUND', 'Khong tim thay ban nhap hoac ban nhap khong thuoc cua hang hien tai.', 404, true, true);
        }

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'current_step' => (int) $draft->current_step,
                'payload' => $draft->payload ?? [],
                'updated_at' => optional($draft->updated_at)?->toIso8601String(),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('product_drafts')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang product_drafts chua ton tai.', 500, true, true);
        }

        // Draft update accepts partial data; no strict publish-level validation.
        $validator = Validator::make($request->all(), [
            'current_step' => ['nullable', 'integer', 'min:1', 'max:10'],
            'payload' => ['nullable', 'array'],
            'basic_info' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'pricing' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $draft = ProductDraft::query()
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$draft) {
            return $this->errorResponse('DRAFT_NOT_FOUND', 'Khong tim thay ban nhap hoac ban nhap khong thuoc cua hang hien tai.', 404, true, true);
        }

        $validated = $validator->validated();
        $existingPayload = is_array($draft->payload) ? $draft->payload : [];

        $incomingPayload = $validated['payload'] ?? null;
        if (!is_array($incomingPayload)) {
            $incomingPayload = [];

            if (array_key_exists('basic_info', $validated)) {
                $incomingPayload['basic_info'] = $validated['basic_info'];
            }
            if (array_key_exists('variants', $validated)) {
                $incomingPayload['variants'] = $validated['variants'];
            }
            if (array_key_exists('pricing', $validated)) {
                $incomingPayload['pricing'] = $validated['pricing'];
            }

            // Keep backward compatibility for clients sending full state without wrapping in payload.
            if (empty($incomingPayload)) {
                $incomingPayload = $request->except(['current_step']);
            }
        }

        $draft->payload = array_replace_recursive($existingPayload, $incomingPayload);

        if (array_key_exists('current_step', $validated)) {
            $draft->current_step = (int) $validated['current_step'];
        }

        $draft->save();

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'current_step' => (int) $draft->current_step,
                'updated_at' => optional($draft->updated_at)?->toIso8601String(),
            ],
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

        if (!Schema::hasTable('product_drafts')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang product_drafts chua ton tai.', 500, true, true);
        }

        $draft = ProductDraft::query()
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$draft) {
            return $this->errorResponse('DRAFT_NOT_FOUND', 'Khong tim thay ban nhap hoac ban nhap khong thuoc cua hang hien tai.', 404, true, true);
        }

        $deletedId = (string) $draft->id;
        $draft->delete();

        return response()->json([
            'data' => [
                'id' => $deletedId,
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
