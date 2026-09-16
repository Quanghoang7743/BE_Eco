<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AffiliateCommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAffiliateCommissionController extends Controller
{
    public function __construct(
        private readonly AffiliateCommissionService $commissionService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'affiliate_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:50'],
            'payout_batch_id' => ['nullable', 'integer'],
            'order_keyword' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        return response()->json($this->commissionService->paginateCommissions($filters, $perPage));
    }

    public function show(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->commissionService->getCommission($commissionId)]);
    }

    public function approve(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->commissionService->approveCommission($commissionId, $request->user())]);
    }

    public function reject(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => $this->commissionService->rejectCommission($commissionId, (string) $validated['reason'])]);
    }

    public function hold(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => $this->commissionService->holdCommission($commissionId, (string) $validated['reason'])]);
    }

    public function release(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->commissionService->releaseCommission($commissionId)]);
    }

    public function cancel(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => $this->commissionService->cancelCommission($commissionId, (string) $validated['reason'])]);
    }

    public function clawback(Request $request, int $commissionId): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => $this->commissionService->clawbackCommission($commissionId, (string) $validated['reason'])]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user() && in_array((string) $request->user()->role, ['admin', 'super_admin'], true), 403);
    }
}
