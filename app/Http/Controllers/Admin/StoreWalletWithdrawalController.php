<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreWalletWithdrawalRequest;
use App\Services\StoreFinanceWithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class StoreWalletWithdrawalController extends Controller
{
    public function __construct(private readonly StoreFinanceWithdrawalService $withdrawalService)
    {
    }

    public function index(Request $request): View
    {
        $this->ensureSuperAdmin();

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $status = trim((string) ($filters['status'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        $withdrawals = StoreWalletWithdrawalRequest::query()
            ->with(['store', 'financialReconciliation'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($scope) use ($keyword): void {
                    $scope
                        ->where('id', 'like', '%' . $keyword . '%')
                        ->orWhere('bank_name', 'like', '%' . $keyword . '%')
                        ->orWhere('account_number', 'like', '%' . $keyword . '%')
                        ->orWhere('account_name', 'like', '%' . $keyword . '%')
                        ->orWhereHas('store', function ($storeQuery) use ($keyword): void {
                            $storeQuery
                                ->where('name', 'like', '%' . $keyword . '%')
                                ->orWhere('store_code', 'like', '%' . $keyword . '%');
                        });
                });
            })
            ->orderByRaw("CASE WHEN status IN ('pending', 'approved', 'processing') THEN 0 WHEN status = 'paid' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        // Gán thuộc tính is_latest_unapproved cho từng đơn rút ví (được tối ưu hóa tránh N+1 query)
        $storeIds = $withdrawals->pluck('store_id')->unique()->toArray();
        /** @var \App\Services\StoreFinanceReconciliationService $reconService */
        $reconService = app(\App\Services\StoreFinanceReconciliationService::class);
        $latestUnapprovedByStore = $reconService->getLatestUnapprovedForStores($storeIds);

        foreach ($withdrawals as $withdrawal) {
            $canProcess = in_array((string) $withdrawal->status, ['pending', 'approved', 'processing'], true);
            if ($canProcess) {
                $latest = $latestUnapprovedByStore[$withdrawal->store_id] ?? null;
                $withdrawal->is_latest_unapproved = !$latest || ($latest['type'] === 'withdrawal' && $latest['id'] === (string) $withdrawal->id);
            } else {
                $withdrawal->is_latest_unapproved = false;
            }
        }

        $summary = [
            'pending' => StoreWalletWithdrawalRequest::query()->whereIn('status', ['pending', 'approved', 'processing'])->count(),
            'paid' => StoreWalletWithdrawalRequest::query()->where('status', 'paid')->count(),
            'rejected' => StoreWalletWithdrawalRequest::query()->where('status', 'rejected')->count(),
        ];

        return view('admin.store_wallet_withdrawals.index', [
            'withdrawals' => $withdrawals,
            'filters' => $filters,
            'summary' => $summary,
        ]);
    }

    public function markPaid(Request $request, StoreWalletWithdrawalRequest $withdrawal): RedirectResponse
    {
        $this->ensureSuperAdmin();

        try {
            $this->withdrawalService->markPaid($withdrawal, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $this->messageFor($exception->getMessage()));
        }

        return back()->with('success', 'Đã xác nhận chuyển khoản cho yêu cầu rút ví.');
    }

    public function reject(Request $request, StoreWalletWithdrawalRequest $withdrawal): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->withdrawalService->reject($withdrawal, $request->user(), $validated['reason'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $this->messageFor($exception->getMessage()));
        }

        return back()->with('success', 'Đã từ chối yêu cầu rút ví và hoàn lại tiền vào ví cửa hàng.');
    }

    private function ensureSuperAdmin(): void
    {
        $user = auth()->user();

        abort_unless(
            $user && in_array((string) $user->role, ['admin', 'super_admin', 'accountant'], true),
            403,
            'Chỉ admin tổng hoặc kế toán mới được phép xử lý lệnh rút ví cửa hàng.'
        );
    }

    private function messageFor(string $code): string
    {
        return match ($code) {
            'RECONCILIATION_REAPPROVAL_REQUIRED' => 'Kỳ đối soát đã thay đổi, cần duyệt lại trước khi chuyển khoản.',
            'RECONCILIATION_NOT_APPROVED' => 'Kỳ đối soát chưa ở trạng thái chờ thanh toán.',
            'STORE_WALLET_BALANCE_CHANGED' => 'Ví cửa hàng hoặc công nợ đã thay đổi, chưa thể xác nhận chuyển khoản.',
            'WITHDRAWAL_AMOUNT_EXCEEDS_CURRENT_ALLOWED' => 'Số tiền rút vượt hạn mức hiện tại của kỳ đối soát.',
            'WITHDRAWAL_NOT_PAYABLE' => 'Yêu cầu rút ví không còn ở trạng thái có thể chuyển khoản.',
            'WITHDRAWAL_NOT_REJECTABLE' => 'Yêu cầu rút ví không còn ở trạng thái có thể từ chối.',
            'LATEST_WITHDRAWAL_REQUIRED' => 'Chỉ được phép xử lý đơn đối soát hoặc yêu cầu rút mới nhất của cửa hàng.',
            default => $code !== '' ? $code : 'Không thể xử lý yêu cầu rút ví.',
        };
    }
}
