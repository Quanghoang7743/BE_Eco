<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Services\OrderReturnService;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use App\Support\ReturnEvidencePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class StoreAdminOrderReturnController extends Controller
{
    public function __construct(
        private readonly StoreAdminStaffAccess $staffAccess,
        private readonly OrderReturnService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->staffAccess->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));
        $query = OrderReturn::query()
            ->with([
                'order',
                'user',
                'items.orderItem.product',
                'items.orderItem.variant',
                'items.orderItem.menuItem.product',
                'items.orderItem.menuItem.variant',
            ])
            ->where('store_id', (string) $store->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            if ($status === 'processed') {
                $query->whereIn('status', [
                    OrderReturn::STATUS_REFUND_PROCESSING,
                    OrderReturn::STATUS_REFUNDED,
                    OrderReturn::STATUS_CLOSED,
                ]);
            } else {
                $query->where('status', $status);
            }
        }

        $rows = $query->paginate($pageSize);

        return response()->json([
            'data' => collect($rows->items())->map(fn (OrderReturn $return): array => $this->serializeReturn($return))->all(),
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'errors' => [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function approve(Request $request, string $returnId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'return_method' => ['nullable', 'string', 'in:at_store,shipping'],
            'refund_settlement_method' => ['nullable', 'string', 'in:wallet,manual_at_store'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
            'carrier_name' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }
        $validated = $validator->validated();
        $legacyReturnMethod = $validated['return_method'] ?? null;
        $refundSettlementMethod = $validated['refund_settlement_method']
            ?? ($legacyReturnMethod === 'at_store' ? 'manual_at_store' : 'wallet');

        return $this->handleReturnAction(
            $request,
            $returnId,
            fn ($user, OrderReturn $return, string $storeId) => $this->service->approveReturn(
                $user,
                $return,
                $validated['tracking_code'] ?? null,
                $validated['carrier_name'] ?? null,
                $storeId,
                $legacyReturnMethod,
                $refundSettlementMethod
            )
        );
    }

    public function reject(Request $request, string $returnId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        return $this->handleReturnAction(
            $request,
            $returnId,
            fn ($user, OrderReturn $return, string $storeId) => $this->service->rejectReturn($user, $return, $validator->validated()['rejection_reason'], $storeId)
        );
    }

    public function receive(Request $request, string $returnId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_satisfied' => ['required', 'boolean'],
            'shop_received_note' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['string', 'max:255'],
            'video' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }
        $payload = $validator->validated();

        return $this->handleReturnAction(
            $request,
            $returnId,
            function ($user, OrderReturn $return, string $storeId) use ($payload) {
                $safePayload = $payload;
                $safePayload['images'] = ReturnEvidencePaths::sanitizeImagesForOwner($payload['images'] ?? [], $storeId, ['shop_returns']);
                $safePayload['video'] = ReturnEvidencePaths::sanitizeVideoForOwner($payload['video'] ?? null, $storeId, ['shop_returns']);

                return $this->service->receiveReturn(
                    $user,
                    $return,
                    (bool) $safePayload['is_satisfied'],
                    $safePayload,
                    $storeId
                );
            }
        );
    }

    public function upload(Request $request): JsonResponse
    {
        [, $store, $error] = $this->staffAccess->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        $fileField = $request->hasFile('file') ? 'file' : 'image';
        $validator = Validator::make($request->all(), [
            $fileField => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        $file = $request->file($fileField);
        $directory = 'uploads/shop_returns/' . (string) $store->id;
        $path = $file->store($directory, 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => url(\Illuminate\Support\Facades\Storage::url($path)),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ],
            'errors' => [],
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    private function handleReturnAction(Request $request, string $returnId, callable $callback): JsonResponse
    {
        [$user, $store, $error] = $this->staffAccess->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        $return = OrderReturn::query()->whereKey($returnId)->first();
        if (! $return) {
            return $this->error('RETURN_NOT_FOUND', 'Return request not found.', 404);
        }

        if ((string) $return->store_id !== (string) $store->id) {
            return $this->error('RETURN_STORE_FORBIDDEN', 'Return request does not belong to this store.', 403);
        }

        try {
            return response()->json([
                'data' => $this->serializeReturn($callback($user, $return, (string) $store->id)),
                'errors' => [],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), 422);
        }
    }

    private function serializeReturn(OrderReturn $return): array
    {
        $return->loadMissing([
            'order',
            'user',
            'items.orderItem.product',
            'items.orderItem.variant',
            'items.orderItem.menuItem.product',
            'items.orderItem.menuItem.variant',
        ]);

        return [
            'id' => (string) $return->id,
            'return_code' => $return->return_code,
            'order_id' => (string) $return->order_id,
            'order_code' => $return->order?->order_code,
            'order' => $return->order ? [
                'id' => (string) $return->order->id,
                'order_code' => $return->order->order_code,
                'shipping_method' => $return->order->shipping_method,
                'delivery_method' => $return->order->shipping_method,
            ] : null,
            'customer' => [
                'id' => (string) $return->user_id,
                'name' => $return->user?->full_name ?? $return->user?->name,
                'phone' => $return->user?->phone,
            ],
            'status' => $return->status,
            'return_type' => $return->return_type,
            'refund_method' => $return->refund_method,
            'refund_bank_info' => $return->refund_bank_info,
            'return_method' => $return->return_method,
            'refund_settlement_method' => $return->refund_funding_source === 'manual_at_store' ? 'manual_at_store' : 'wallet',
            'refund_amount_estimated' => (float) $return->refund_amount_estimated,
            'refund_amount_final' => (float) $return->refund_amount_final,
            'refund_currency' => $return->refund_currency,
            'refund_funding_source' => $return->refund_funding_source,
            'platform_advanced_amount' => (float) $return->platform_advanced_amount,
            'created_at' => optional($return->created_at)?->toIso8601String(),
            'updated_at' => optional($return->updated_at)?->toIso8601String(),
            'auto_approve_at' => optional($return->auto_approve_at)?->toIso8601String(),
            'shipped_at' => in_array($return->status, ['returning', 'received', 'refund_processing', 'refunded'], true)
                ? optional($return->updated_at)?->toIso8601String()
                : null,
            'received_at' => in_array($return->status, ['received', 'refund_processing', 'refunded'], true)
                ? optional($return->updated_at)?->toIso8601String()
                : null,
            'refunded_at' => optional($return->refund_processed_at)?->toIso8601String(),
            'sla_seconds_remaining' => $return->auto_approve_at ? max(0, now()->diffInSeconds($return->auto_approve_at, false)) : null,
            'reason_code' => $return->reason_code,
            'reason' => $return->reason,
            'images' => $return->images ?? [],
            'video' => $return->video,
            'rejection_reason' => $return->rejection_reason,
            'tracking_code' => $return->tracking_code,
            'carrier_name' => $return->carrier_name,
            'shipping_receipt_images' => $return->shipping_receipt_images ?? [],
            'shop_received_images' => $return->shop_received_images ?? [],
            'shop_received_video' => $return->shop_received_video,
            'shop_received_note' => $return->shop_received_note,
            'dispute_reason' => $return->dispute_reason,
            'dispute_evidence_images' => $return->dispute_evidence_images ?? [],
            'admin_resolution' => $return->admin_resolution,
            'admin_resolution_note' => $return->admin_resolution_note,
            'items' => $return->items->map(fn ($item): array => [
                'order_item_id' => (string) $item->order_item_id,
                'quantity' => (int) $item->quantity,
                'refund_amount' => (float) $item->refund_amount,
                'metadata' => $item->metadata,
                'product_name' => $item->orderItem?->product_name ?? $item->orderItem?->item_name ?? 'Sản phẩm',
                'thumbnail' => $this->resolveOrderItemThumbnail($item->orderItem),
                'unit_price' => (float) ($item->orderItem?->price ?? $item->orderItem?->unit_price ?? 0),
                'sku' => $item->orderItem?->variant_id ? 'VAR-' . $item->orderItem->variant_id : 'PROD-' . ($item->orderItem?->product_id ?? 'BGMSMN01'),
            ])->values()->all(),
        ];
    }

    private function resolveOrderItemThumbnail(?OrderItem $orderItem): string
    {
        if (! $orderItem) {
            return '';
        }

        $candidates = [
            $orderItem->thumbnail,
            $orderItem->variant?->image,
            $orderItem->menuItem?->image_url,
            $orderItem->menuItem?->variant?->image,
            $orderItem->product?->thumbnail,
            $orderItem->menuItem?->product?->thumbnail,
            $orderItem->getAttribute('product_thumbnail'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['data' => null, 'errors' => [$error]], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
