<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\OrderReturnService;
use App\Support\ReturnEvidencePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CustomerOrderReturnController extends ClientController
{
    public function __construct(private readonly OrderReturnService $service) {}

    public function create(Request $request, string $orderId): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $validator = Validator::make($request->all(), [
            'return_type' => ['required', 'string', 'in:refund'],
            'refund_method' => ['nullable', 'string', 'in:money_wallet'],
            'return_method' => ['nullable', 'string', 'in:at_store,shipping'],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['string', 'max:255'],
            'video' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'refund_bank_info' => ['nullable', 'array'],
            'refund_bank_info.bank_name' => ['required_with:refund_bank_info', 'string', 'max:255'],
            'refund_bank_info.account_name' => ['required_with:refund_bank_info', 'string', 'max:255'],
            'refund_bank_info.account_number' => ['required_with:refund_bank_info', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $order = Order::query()
            ->where('id', $orderId)
            ->orWhere('order_code', $orderId)
            ->first();
        if (! $order) {
            return $this->error('ORDER_NOT_FOUND', 'Order not found.', 404);
        }

        try {
            $payload = $validator->validated();
            $payload['images'] = ReturnEvidencePaths::sanitizeImagesForOwner($payload['images'] ?? [], (string) $user->id, ['returns']);
            $payload['video'] = ReturnEvidencePaths::sanitizeVideoForOwner($payload['video'] ?? null, (string) $user->id, ['returns']);

            $return = $this->service->createReturnRequest($user, $order, $payload);

            return $this->data($this->serializeReturn($return), 201);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->messageFor($e->getMessage()), $this->statusFor($e->getMessage()));
        }
    }

    public function ship(Request $request, string $returnId): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $validator = Validator::make($request->all(), [
            'tracking_code' => ['required', 'string', 'max:100'],
            'carrier_name' => ['nullable', 'string', 'max:100'],
            'shipping_receipt_images' => ['required', 'array', 'min:1', 'max:3'],
            'shipping_receipt_images.*' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $return = OrderReturn::query()->whereKey($returnId)->first();
        if (! $return) {
            return $this->error('RETURN_NOT_FOUND', 'Return request not found.', 404);
        }

        try {
            $payload = $validator->validated();
            $payload['shipping_receipt_images'] = ReturnEvidencePaths::sanitizeImagesForOwner(
                $payload['shipping_receipt_images'] ?? [],
                (string) $user->id,
                ['returns']
            );

            return $this->data($this->serializeReturn($this->service->shipReturn($user, $return, $payload)));
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->messageFor($e->getMessage()), $this->statusFor($e->getMessage()));
        }
    }

    public function arriveAtStore(Request $request, string $returnId): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $return = OrderReturn::query()->whereKey($returnId)->first();
        if (! $return) {
            return $this->error('RETURN_NOT_FOUND', 'Return request not found.', 404);
        }

        try {
            return $this->data($this->serializeReturn($this->service->markAtStoreReturnArrived($user, $return)));
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->messageFor($e->getMessage()), $this->statusFor($e->getMessage()));
        }
    }

    public function dispute(Request $request, string $returnId): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $validator = Validator::make($request->all(), [
            'dispute_reason' => ['required', 'string', 'max:2000'],
            'evidence_images' => ['nullable', 'array', 'max:10'],
            'evidence_images.*' => ['string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $return = OrderReturn::query()->whereKey($returnId)->first();
        if (! $return) {
            return $this->error('RETURN_NOT_FOUND', 'Return request not found.', 404);
        }

        try {
            $payload = $validator->validated();
            $payload['evidence_images'] = ReturnEvidencePaths::sanitizeImagesForOwner($payload['evidence_images'] ?? [], (string) $user->id, ['returns']);

            return $this->data($this->serializeReturn($this->service->disputeReturn($user, $return, $payload)));
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->messageFor($e->getMessage()), $this->statusFor($e->getMessage()));
        }
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Unauthorized', 401);
        }

        $fileField = $request->hasFile('file') ? 'file' : 'image';
        $validator = Validator::make($request->all(), [
            $fileField => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $file = $request->file($fileField);
        $userId = (string) $user->id;
        $directory = 'uploads/returns/' . $userId;
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
        ], 201);
    }

    private function serializeReturn(OrderReturn $return): array
    {
        $return->loadMissing(['items.orderItem', 'order']);

        return [
            'id' => (string) $return->id,
            'return_code' => $return->return_code,
            'order_id' => (string) $return->order_id,
            'order_code' => $return->order?->order_code,
            'status' => $return->status,
            'return_type' => $return->return_type,
            'refund_method' => $return->refund_method,
            'refund_bank_info' => $return->refund_bank_info,
            'return_method' => $return->return_method,
            'reason_code' => $return->reason_code,
            'reason' => $return->reason,
            'images' => $return->images ?? [],
            'video' => $return->video,
            'refund_amount_estimated' => (float) $return->refund_amount_estimated,
            'refund_amount_final' => (float) $return->refund_amount_final,
            'refund_currency' => $return->refund_currency,
            'auto_approve_at' => optional($return->auto_approve_at)?->toIso8601String(),
            'rejection_reason' => $return->rejection_reason,
            'tracking_code' => $return->tracking_code,
            'carrier_name' => $return->carrier_name,
            'shipping_receipt_images' => $return->shipping_receipt_images ?? [],
            'shipped_at' => ($return->status === 'returning' || $return->status === 'received' || $return->status === 'refunded') ? optional($return->updated_at)?->toIso8601String() : null,
            'dispute_reason' => $return->dispute_reason,
            'dispute_evidence_images' => $return->dispute_evidence_images ?? [],
            'items' => $return->items->map(fn ($item): array => [
                'id' => (string) $item->id,
                'order_item_id' => (string) $item->order_item_id,
                'quantity' => (int) $item->quantity,
                'unit_price_snapshot' => (float) $item->unit_price_snapshot,
                'gross_amount' => (float) $item->gross_amount,
                'discount_allocated' => (float) $item->discount_allocated,
                'refund_amount' => (float) $item->refund_amount,
                'metadata' => $item->metadata,
            ])->values()->all(),
        ];
    }

    private function data(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'errors' => []], $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function validationError(array $details): JsonResponse
    {
        return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $details);
    }

    private function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['data' => null, 'errors' => [$error]], $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function statusFor(string $code): int
    {
        return in_array($code, ['ORDER_NOT_OWNED_BY_USER', 'RETURN_NOT_OWNED_BY_USER'], true) ? 403 : 422;
    }

    private function messageFor(string $code): string
    {
        return match ($code) {
            'RETURN_WINDOW_EXPIRED' => 'Return window has expired.',
            'PRODUCT_RETURN_FORFEITED_BY_TICKET_USE' => 'This item can no longer be returned because its reward ticket was used.',
            'REFUND_BANK_INFO_REQUIRED' => 'Refund bank information is required for shipped returns.',
            'RETURN_QUANTITY_EXCEEDED' => 'Requested return quantity exceeds the remaining returnable quantity.',
            'ORDER_STATUS_NOT_RETURNABLE' => 'Order status is not returnable.',
            'INVALID_RETURN_STATE' => 'Return request is not in a valid state for this action.',
            'SHIPPING_RECEIPT_IMAGES_REQUIRED' => 'Shipping receipt images are required.',
            'RETURN_METHOD_SHIPPING_NOT_ALLOWED' => 'This return request must be handled at the store.',
            'RETURN_METHOD_AT_STORE_NOT_ALLOWED' => 'This return request must be shipped back to the store.',
            default => $code,
        };
    }
}
