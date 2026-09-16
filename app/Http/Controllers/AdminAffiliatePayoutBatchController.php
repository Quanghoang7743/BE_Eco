<?php

namespace App\Http\Controllers;

use App\Services\AffiliatePayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAffiliatePayoutBatchController extends Controller
{
    public function __construct(
        private readonly AffiliatePayoutService $payoutService,
    ) {
    }

    public function index(Request $request): View
    {
        $this->ensureAdmin();

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return view('admin.affiliate.payout-batches.index', [
            'filters' => $filters,
            'batches' => $this->payoutService->paginateBatches($filters, $perPage)->appends($request->query()),
        ]);
    }

    public function show(int $batchId): View
    {
        $this->ensureAdmin();

        return view('admin.affiliate.payout-batches.show', [
            'batch' => $this->payoutService->getBatch($batchId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'payout_period_from' => ['required', 'date'],
            'payout_period_to' => ['required', 'date', 'after_or_equal:payout_period_from'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $batch = $this->payoutService->createBatch($validated, $request->user());

        return redirect()->route('admin.affiliate.payout-batches.show', $batch['id'])->with('success', 'Đã tạo kỳ thanh toán.');
    }

    public function submit(Request $request, int $batchId): RedirectResponse
    {
        $this->ensureAdmin();
        $this->payoutService->submitBatch($batchId);

        return back()->with('success', 'Đã gửi batch sang chờ duyệt.');
    }

    public function approve(Request $request, int $batchId): RedirectResponse
    {
        $this->ensureAdmin();
        $this->payoutService->approveBatch($batchId, $request->user());

        return back()->with('success', 'Đã duyệt batch thanh toán.');
    }

    public function markPaid(Request $request, int $batchId): RedirectResponse
    {
        $this->ensureAdmin();
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:191'],
            'payment_proof_url' => ['nullable', 'url', 'max:500'],
        ]);
        $this->payoutService->markBatchPaid($batchId, $validated, $request->user());

        return back()->with('success', 'Đã xác nhận thanh toán batch.');
    }

    public function cancel(Request $request, int $batchId): RedirectResponse
    {
        $this->ensureAdmin();
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->payoutService->cancelBatch($batchId, (string) ($validated['reason'] ?? ''));

        return back()->with('success', 'Đã hủy batch thanh toán.');
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ admin mới được phép thực hiện thao tác này.');
    }
}
