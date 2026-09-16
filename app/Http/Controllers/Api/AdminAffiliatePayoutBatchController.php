<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AffiliatePayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAffiliatePayoutBatchController extends Controller
{
    public function __construct(
        private readonly AffiliatePayoutService $payoutService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 20);

        return response()->json($this->payoutService->paginateBatches($filters, $perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'payout_period_from' => ['required', 'date'],
            'payout_period_to' => ['required', 'date', 'after_or_equal:payout_period_from'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['success' => true, 'data' => $this->payoutService->createBatch($validated, $request->user())]);
    }

    public function show(Request $request, int $batchId): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->payoutService->getBatch($batchId)]);
    }

    public function submit(Request $request, int $batchId): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->payoutService->submitBatch($batchId)]);
    }

    public function approve(Request $request, int $batchId): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->payoutService->approveBatch($batchId, $request->user())]);
    }

    public function markPaid(Request $request, int $batchId): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:191'],
            'payment_proof_url' => ['nullable', 'url', 'max:500'],
        ]);

        return response()->json(['success' => true, 'data' => $this->payoutService->markBatchPaid($batchId, $validated, $request->user())]);
    }

    public function cancel(Request $request, int $batchId): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['success' => true, 'data' => $this->payoutService->cancelBatch($batchId, (string) ($validated['reason'] ?? ''))]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user() && in_array((string) $request->user()->role, ['admin', 'super_admin'], true), 403);
    }
}
