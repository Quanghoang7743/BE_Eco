<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Services\OrderReturnService;
use App\Support\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AdminOrderReturnController extends Controller
{
    public function __construct(private readonly OrderReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->isPlatformAdmin($request)) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));
        $status = (string) $request->query('status', OrderReturn::STATUS_PLATFORM_REVIEWING);
        $rows = OrderReturn::query()
            ->with([
                'order',
                'store',
                'user',
                'items.orderItem.product',
                'items.orderItem.variant',
                'items.orderItem.menuItem.product',
                'items.orderItem.menuItem.variant',
            ])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($pageSize);

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

    public function resolveDispute(Request $request, string $returnId): JsonResponse
    {
        if (! $this->isPlatformAdmin($request)) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $validator = Validator::make($request->all(), [
            'resolution' => ['required', 'string', 'in:approve_refund,reject_refund'],
            'admin_resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        $return = OrderReturn::query()->whereKey($returnId)->first();
        if (! $return) {
            return $this->error('RETURN_NOT_FOUND', 'Return request not found.', 404);
        }

        try {
            $resolved = $this->service->resolveDispute($request->user(), $return, $validator->validated());

            return response()->json(['data' => $this->serializeReturn($resolved), 'errors' => []], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), 422);
        }
    }

    private function isPlatformAdmin(Request $request): bool
    {
        return PlatformAdminAccess::allows($request->user());
    }

    private function serializeReturn(OrderReturn $return): array
    {
        $return->loadMissing([
            'order',
            'store',
            'user',
            'items.orderItem.product',
            'items.orderItem.variant',
            'items.orderItem.menuItem.product',
            'items.orderItem.menuItem.variant',
        ]);

        return [
            'id' => (string) $return->id,
            'return_code' => $return->return_code,
            'order_code' => $return->order?->order_code,
            'order' => $return->order ? [
                'id' => (string) $return->order->id,
                'order_code' => $return->order->order_code,
                'shipping_method' => $return->order->shipping_method,
                'delivery_method' => $return->order->shipping_method,
            ] : null,
            'store' => [
                'id' => (string) $return->store_id,
                'name' => $return->store?->name,
            ],
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
            'refund_amount_estimated' => (float) $return->refund_amount_estimated,
            'refund_amount_final' => (float) $return->refund_amount_final,
            'refund_currency' => $return->refund_currency,
            'refund_funding_source' => $return->refund_funding_source,
            'platform_advanced_amount' => (float) $return->platform_advanced_amount,
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
