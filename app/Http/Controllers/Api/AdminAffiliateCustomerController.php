<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AffiliateCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAffiliateCustomerController extends Controller
{
    public function __construct(
        private readonly AffiliateCustomerService $affiliateCustomerService,
    ) {
    }

    public function index(Request $request, int $affiliateId): JsonResponse
    {
        $this->ensureAdmin($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'risk_flag' => ['nullable', 'boolean'],
            'assigned_from' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'date'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters['affiliate_id'] = $affiliateId;
        $paginator = $this->affiliateCustomerService->paginateAssignments($filters, (int) ($filters['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function summary(Request $request, int $affiliateId): JsonResponse
    {
        $this->ensureAdmin($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'risk_flag' => ['nullable', 'boolean'],
            'assigned_from' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'date'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);

        $filters['affiliate_id'] = $affiliateId;

        return response()->json([
            'success' => true,
            'data' => $this->affiliateCustomerService->buildSummary($filters),
        ]);
    }

    public function show(Request $request, int $affiliateId, int $customerId): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'success' => true,
            'data' => $this->affiliateCustomerService->getAssignmentForAffiliateCustomer($affiliateId, $customerId),
        ]);
    }

    public function transfer(Request $request, int $affiliateId, int $customerId): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'new_affiliate_id' => ['required', 'integer', 'exists:users,id'],
            'transfer_reason' => ['required', 'string', 'max:2000'],
        ]);

        $result = $this->affiliateCustomerService->transferCustomer(
            $affiliateId,
            $customerId,
            (int) $validated['new_affiliate_id'],
            (string) $validated['transfer_reason'],
            $request->user(),
            $request,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã chuyển khách hàng sang Affiliate mới.',
            'data' => $result,
        ]);
    }

    public function remove(Request $request, int $affiliateId, int $customerId): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'transfer_reason' => ['required', 'string', 'max:2000'],
        ]);

        $result = $this->affiliateCustomerService->removeCustomer(
            $affiliateId,
            $customerId,
            (string) $validated['transfer_reason'],
            $request->user(),
            $request,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã loại khách hàng khỏi Affiliate.',
            'data' => $result,
        ]);
    }

    public function lock(Request $request, int $affiliateId, int $customerId): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'transfer_reason' => ['required', 'string', 'max:2000'],
        ]);

        $result = $this->affiliateCustomerService->lockCustomer(
            $affiliateId,
            $customerId,
            (string) $validated['transfer_reason'],
            $request->user(),
            $request,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã khóa khách hàng.',
            'data' => $result,
        ]);
    }

    public function unlock(Request $request, int $affiliateId, int $customerId): JsonResponse
    {
        $this->ensureAdmin($request);

        $result = $this->affiliateCustomerService->unlockCustomer(
            $affiliateId,
            $customerId,
            $request->user(),
            $request,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã mở khóa khách hàng.',
            'data' => $result,
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
    }
}
