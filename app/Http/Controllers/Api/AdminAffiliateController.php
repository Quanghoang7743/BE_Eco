<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Services\AffiliateReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAffiliateController extends Controller
{
    public function __construct(private readonly AffiliateReportService $affiliateReportService)
    {
    }

    public function reports(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $reports = $this->affiliateReportService->paginateReports($validated, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $reports->items(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    public function changeUpline(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'complaint_id' => ['required', 'string', 'exists:complaints,id'],
            'action' => ['required', 'in:detach,transfer'],
            'new_referrer_id' => [
                Rule::requiredIf(fn () => $request->input('action') === 'transfer'),
                'nullable',
                'integer',
                'exists:users,id',
            ],
        ]);

        $complaint = Complaint::query()->findOrFail($validated['complaint_id']);

        $result = $this->affiliateReportService->changeUpline(
            $complaint,
            (string) $validated['action'],
            isset($validated['new_referrer_id']) ? (int) $validated['new_referrer_id'] : null,
            (int) $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã xử lý báo cáo affiliate thành công.',
            'data' => $result,
        ]);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
    }
}