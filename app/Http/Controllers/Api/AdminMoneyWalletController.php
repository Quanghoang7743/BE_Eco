<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoneyWalletWithdrawalRequest;
use App\Services\OrderReturnService;
use App\Support\PlatformAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AdminMoneyWalletController extends Controller
{
    public function __construct(private readonly OrderReturnService $service)
    {
    }

    public function withdrawals(Request $request): JsonResponse
    {
        if (! $this->isPlatformAdmin($request)) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));
        $rows = MoneyWalletWithdrawalRequest::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderByDesc('created_at')
            ->paginate($pageSize);

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'errors' => [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function markWithdrawalPaid(Request $request, string $withdrawalId): JsonResponse
    {
        if (! $this->isPlatformAdmin($request)) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $withdrawal = MoneyWalletWithdrawalRequest::query()->whereKey($withdrawalId)->first();
        if (! $withdrawal) {
            return $this->error('WITHDRAWAL_NOT_FOUND', 'Withdrawal request not found.', 404);
        }

        try {
            $updated = $this->service->markWithdrawalPaid($withdrawal, $request->user());

            return response()->json(['data' => $updated, 'errors' => []], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), 422);
        }
    }

    public function cancelWithdrawal(Request $request, string $withdrawalId): JsonResponse
    {
        if (! $this->isPlatformAdmin($request)) {
            return $this->error('FORBIDDEN', 'Forbidden', 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['nullable', 'string', 'in:cancelled,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid request data.', 422, $validator->errors()->toArray());
        }

        $withdrawal = MoneyWalletWithdrawalRequest::query()->whereKey($withdrawalId)->first();
        if (! $withdrawal) {
            return $this->error('WITHDRAWAL_NOT_FOUND', 'Withdrawal request not found.', 404);
        }

        try {
            $validated = $validator->validated();
            $updated = $this->service->cancelWithdrawal(
                $withdrawal,
                $request->user(),
                (string) ($validated['status'] ?? 'cancelled'),
                $validated['reason'] ?? null
            );

            return response()->json(['data' => $updated, 'errors' => []], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), 422);
        }
    }

    private function isPlatformAdmin(Request $request): bool
    {
        return PlatformAdminAccess::allows($request->user());
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
