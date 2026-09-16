<?php

namespace App\Http\Controllers;

use App\Services\AffiliateCustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAffiliateCustomerController extends Controller
{
    public function __construct(
        private readonly AffiliateCustomerService $affiliateCustomerService,
    ) {
    }

    public function index(Request $request): View
    {
        $this->ensureAdmin();

        $filters = $request->validate([
            'affiliate_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'risk_flag' => ['nullable', 'boolean'],
            'assigned_from' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'date'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $assignments = $this->affiliateCustomerService->paginateAssignments($filters, $perPage)->appends($request->query());
        $summary = $this->affiliateCustomerService->buildSummary($filters);

        return view('admin.affiliate.customers.index', [
            'filters' => $filters,
            'assignments' => $assignments,
            'summary' => $summary,
            'affiliateOptions' => $this->affiliateCustomerService->approvedAffiliateOptions(),
            'isLegacyMode' => $this->affiliateCustomerService->isLegacyMode(),
        ]);
    }

    public function show(Request $request, int $assignmentId): View
    {
        $this->ensureAdmin();

        $assignment = $this->affiliateCustomerService->getAssignmentById($assignmentId);

        return view('admin.affiliate.customers.show', [
            'assignment' => $assignment,
            'affiliateOptions' => $this->affiliateCustomerService->approvedAffiliateOptions(),
        ]);
    }

    public function transfer(Request $request, int $assignmentId): RedirectResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'new_affiliate_id' => ['required', 'integer', 'exists:users,id'],
            'transfer_reason' => ['required', 'string', 'max:2000'],
        ]);

        $assignment = $this->affiliateCustomerService->getAssignmentById($assignmentId);

        try {
            $this->affiliateCustomerService->transferCustomer(
                (int) $assignment['affiliate_id'],
                (int) $assignment['customer_id'],
                (int) $validated['new_affiliate_id'],
                (string) $validated['transfer_reason'],
                $request->user(),
                $request,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return redirect()
            ->route('admin.affiliate.customers.index', ['affiliate_id' => $assignment['affiliate_id']])
            ->with('success', 'Đã chuyển khách hàng sang Affiliate mới.');
    }

    public function remove(Request $request, int $assignmentId): RedirectResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'transfer_reason' => ['required', 'string', 'max:2000'],
        ]);

        $assignment = $this->affiliateCustomerService->getAssignmentById($assignmentId);
        $this->affiliateCustomerService->removeCustomer(
            (int) $assignment['affiliate_id'],
            (int) $assignment['customer_id'],
            (string) $validated['transfer_reason'],
            $request->user(),
            $request,
        );

        return redirect()
            ->route('admin.affiliate.customers.index', ['affiliate_id' => $assignment['affiliate_id']])
            ->with('success', 'Đã loại khách hàng khỏi Affiliate.');
    }

    public function lock(Request $request, int $assignmentId): RedirectResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'transfer_reason' => ['required', 'string', 'max:2000'],
        ]);

        $assignment = $this->affiliateCustomerService->getAssignmentById($assignmentId);
        $this->affiliateCustomerService->lockCustomer(
            (int) $assignment['affiliate_id'],
            (int) $assignment['customer_id'],
            (string) $validated['transfer_reason'],
            $request->user(),
            $request,
        );

        return back()->with('success', 'Đã khóa khách hàng dưới Affiliate.');
    }

    public function unlock(Request $request, int $assignmentId): RedirectResponse
    {
        $this->ensureAdmin();

        $assignment = $this->affiliateCustomerService->getAssignmentById($assignmentId);
        $this->affiliateCustomerService->unlockCustomer(
            (int) $assignment['affiliate_id'],
            (int) $assignment['customer_id'],
            $request->user(),
            $request,
        );

        return back()->with('success', 'Đã mở khóa khách hàng.');
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
    }
}
