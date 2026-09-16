<?php

namespace App\Http\Controllers;

use App\Models\AffiliateCommission;
use App\Services\AffiliateCommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAffiliateCommissionController extends Controller
{
    public function __construct(
        private readonly AffiliateCommissionService $commissionService,
    ) {
    }

    public function index(Request $request): View
    {
        $this->ensureAdmin();

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
        $commissions = $this->commissionService->paginateCommissions($filters, $perPage)->appends($request->query());
        $summary = [
            'total' => AffiliateCommission::query()->count(),
            'eligible' => AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_ELIGIBLE)->count(),
            'approved' => AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_APPROVED)->count(),
            'paid' => AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_PAID)->count(),
            'held' => AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_HELD)->count(),
        ];

        return view('admin.affiliate.commissions.index', compact('filters', 'commissions', 'summary'));
    }

    public function show(int $commissionId): View
    {
        $this->ensureAdmin();

        return view('admin.affiliate.commissions.show', [
            'commission' => $this->commissionService->getCommission($commissionId),
        ]);
    }

    public function approve(Request $request, int $commissionId): RedirectResponse
    {
        $this->ensureAdmin();
        $this->commissionService->approveCommission($commissionId, $request->user());

        return back()->with('success', 'Đã duyệt hoa hồng.');
    }

    public function reject(Request $request, int $commissionId): RedirectResponse
    {
        $this->ensureAdmin();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->commissionService->rejectCommission($commissionId, (string) $validated['reason']);

        return back()->with('success', 'Đã từ chối hoa hồng.');
    }

    public function hold(Request $request, int $commissionId): RedirectResponse
    {
        $this->ensureAdmin();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->commissionService->holdCommission($commissionId, (string) $validated['reason']);

        return back()->with('success', 'Đã tạm giữ hoa hồng.');
    }

    public function release(Request $request, int $commissionId): RedirectResponse
    {
        $this->ensureAdmin();
        $this->commissionService->releaseCommission($commissionId);

        return back()->with('success', 'Đã mở giữ hoa hồng.');
    }

    public function cancel(Request $request, int $commissionId): RedirectResponse
    {
        $this->ensureAdmin();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->commissionService->cancelCommission($commissionId, (string) $validated['reason']);

        return back()->with('success', 'Đã hủy hoa hồng.');
    }

    public function clawback(Request $request, int $commissionId): RedirectResponse
    {
        $this->ensureAdmin();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->commissionService->clawbackCommission($commissionId, (string) $validated['reason']);

        return back()->with('success', 'Đã tạo clawback.');
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ admin mới được phép thực hiện thao tác này.');
    }
}
