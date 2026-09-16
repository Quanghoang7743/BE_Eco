<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreWalletWithdrawalRequest;
use App\Services\StoreFinanceWithdrawalService;
use App\Support\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AdminStoreWalletWithdrawalController extends Controller
{
    public function __construct(private readonly StoreFinanceWithdrawalService $service)
    {
    }

    public function withdrawals(Request $request): JsonResponse
    {
        if (! PlatformAdminAccess::allows($request->user())) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'max:30'],
            'store_id' => ['nullable', 'string', 'max:64'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        $filters = $validator->validated();
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $rows = StoreWalletWithdrawalRequest::query()
            ->with(['store', 'financialReconciliation'])
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(! empty($filters['store_id']), fn ($query) => $query->where('store_id', (string) $filters['store_id']))
            ->orderByDesc('created_at')
            ->paginate($pageSize);

        return response()->json([
            'data' => array_map(function (StoreWalletWithdrawalRequest $row): array {
                $payload = $this->service->serializeWithdrawal($row);
                $payload['store'] = $row->store ? [
                    'id' => (string) $row->store->id,
                    'name' => $row->store->name,
                    'store_code' => $row->store->store_code,
                    'wallet_balance' => (float) ($row->store->wallet_balance ?? 0),
                    'ticket_debt' => (float) ($row->store->ticket_debt ?? 0),
                ] : null;
                $payload['reconciliation'] = $row->financialReconciliation ? [
                    'id' => (string) $row->financialReconciliation->id,
                    'period' => (string) $row->financialReconciliation->period,
                    'period_date' => $row->financialReconciliation->period_date?->toDateString(),
                    'status' => (string) $row->financialReconciliation->status,
                    'net_amount' => (float) $row->financialReconciliation->net_amount,
                ] : null;

                return $payload;
            }, $rows->items()),
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'errors' => [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function markPaid(Request $request, string $withdrawalId): JsonResponse
    {
        if (! PlatformAdminAccess::allows($request->user())) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $withdrawal = StoreWalletWithdrawalRequest::query()->whereKey($withdrawalId)->first();
        if (! $withdrawal) {
            return $this->error('WITHDRAWAL_NOT_FOUND', 'Withdrawal request not found.', 404);
        }

        try {
            $updated = $this->service->markPaid($withdrawal, $request->user());

            return response()->json(['data' => $this->service->serializeWithdrawal($updated), 'errors' => []], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), $exception->getMessage(), 422);
        }
    }

    public function reject(Request $request, string $withdrawalId): JsonResponse
    {
        if (! PlatformAdminAccess::allows($request->user())) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        $withdrawal = StoreWalletWithdrawalRequest::query()->whereKey($withdrawalId)->first();
        if (! $withdrawal) {
            return $this->error('WITHDRAWAL_NOT_FOUND', 'Withdrawal request not found.', 404);
        }

        try {
            $updated = $this->service->reject($withdrawal, $request->user(), $validator->validated()['reason'] ?? null);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), $exception->getMessage(), 422);
        }

        return response()->json(['data' => $this->service->serializeWithdrawal($updated), 'errors' => []], 200, [], JSON_UNESCAPED_UNICODE);
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
