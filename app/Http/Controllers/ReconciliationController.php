<?php

namespace App\Http\Controllers;

use App\Events\StoreFinanceReconciliationUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\SendReconciliationApprovedEmailJob;
use App\Models\FinancialReconciliation;
use App\Models\Reconciliation;
use App\Models\Store;
use App\Services\StoreFinanceReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(private readonly StoreFinanceReconciliationService $financeReconciliationService)
    {
    }

    /**
     * Display a listing of pending reconciliations
     */
    public function index()
    {
        $storeId = request()->query('store_id');
        $status = request()->query('status');
        $dateRange = request()->query('date_range');
        $keyword = request()->query('keyword');
        $perPage = (int) request()->query('per_page', 10);

        $query = Reconciliation::with(['store', 'approvedBy'])
            ->orderByDesc('reconciliation_date')
            ->orderByDesc('created_at');

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($keyword) {
            $query->whereHas('store', function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%');
            });
        }

        if ($dateRange) {
            $dates = explode(' - ', $dateRange);
            if (count($dates) === 2) {
                try {
                    $start = \Carbon\Carbon::createFromFormat('d/m/Y', trim($dates[0]))->startOfDay();
                    $end = \Carbon\Carbon::createFromFormat('d/m/Y', trim($dates[1]))->endOfDay();
                    $query->whereBetween('reconciliation_date', [$start, $end]);
                } catch (\Exception $e) {
                    // Ignore date parsing issues
                }
            }
        }

        $reconciliations = $query->paginate($perPage);

        // Gán thuộc tính is_latest_unapproved cho từng đơn (được tối ưu hóa tránh N+1 query)
        $storeIds = $reconciliations->pluck('store_id')->unique()->toArray();
        $latestUnapprovedByStore = $this->financeReconciliationService->getLatestUnapprovedForStores($storeIds);

        foreach ($reconciliations as $reconciliation) {
            $latest = $latestUnapprovedByStore[$reconciliation->store_id] ?? null;
            $reconciliation->is_latest_unapproved = !$latest || ($latest['type'] === 'reconciliation' && $latest['id'] === (string) $reconciliation->id);
        }

        // Provide list of stores for filter dropdown
        $stores = Store::query()->select(['id', 'name'])->orderBy('name')->get();

        return view('admin.reconciliation.index', compact('reconciliations', 'stores'));
    }

    /**
     * Show reconciliation details
     */
    public function show(Reconciliation $reconciliation)
    {
        $reconciliation->load(['store', 'approvedBy']);

        // Kiểm tra đơn này có phải là đơn mới nhất chưa duyệt không
        $reconciliation->is_latest_unapproved = $this->financeReconciliationService->isLatestUnapproved(
            (string) $reconciliation->store_id,
            'reconciliation',
            (string) $reconciliation->id
        );

        $financialRecon = FinancialReconciliation::query()
            ->where('store_id', $reconciliation->store_id)
            ->whereDate('period_date', $reconciliation->reconciliation_date)
            ->orderByDesc('created_at')
            ->first();

        $topupAmount = 0.0;
        $latestWithdrawalAmount = 0.0;

        if ($financialRecon) {
            $reconService = app(\App\Services\StoreFinanceReconciliationService::class);
            [$from, $to] = $reconService->reconciliationPeriodRange(
                (string) $financialRecon->store_id,
                (string) $financialRecon->period,
                \Carbon\Carbon::parse($financialRecon->period_date)
            );
            $topupAmount = $reconService->sumTopupAmount((string) $financialRecon->store_id, $from, $to);
            $latestWithdrawalAmount = $reconService->latestPaidWithdrawalAmount((string) $financialRecon->store_id);
        }

        return view('admin.reconciliation.show', compact('reconciliation', 'financialRecon', 'topupAmount', 'latestWithdrawalAmount'));
    }

    /**
     * Approve reconciliation and send email
     */
    public function approve(Request $request, Reconciliation $reconciliation)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        // Chặn duyệt nếu không phải đơn mới nhất
        if (! $this->financeReconciliationService->isLatestUnapproved(
            (string) $reconciliation->store_id,
            'reconciliation',
            (string) $reconciliation->id
        )) {
            return redirect()->route('admin.reconciliation.index')
                ->with('error', 'Chỉ được phép xử lý đơn đối soát hoặc yêu cầu rút mới nhất của cửa hàng.');
        }

        $financialRecon = FinancialReconciliation::query()
            ->where('store_id', $reconciliation->store_id)
            ->whereDate('period_date', $reconciliation->reconciliation_date)
            ->orderByDesc('created_at')
            ->first();

        if ($financialRecon) {
            $this->financeReconciliationService->approveFinancialReconciliation(
                $financialRecon,
                $request->user(),
                $validated['notes'] ?? null
            );
        } else {
            $reconciliation->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'notes' => $validated['notes'] ?? $reconciliation->notes,
            ]);
        }

        // Dispatch job to generate PDF bill and send email to store admin
        dispatch(new SendReconciliationApprovedEmailJob($reconciliation));

        return redirect()->route('admin.reconciliation.index')
            ->with('success', 'Đã duyệt đối soát thành công. Email sẽ được gửi tới admin cửa hàng');
    }

    /**
     * Reject reconciliation
     */
    public function reject(Request $request, Reconciliation $reconciliation)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:500',
        ]);

        // Chặn từ chối nếu không phải đơn mới nhất
        if (! $this->financeReconciliationService->isLatestUnapproved(
            (string) $reconciliation->store_id,
            'reconciliation',
            (string) $reconciliation->id
        )) {
            return redirect()->route('admin.reconciliation.index')
                ->with('error', 'Chỉ được phép xử lý đơn đối soát hoặc yêu cầu rút mới nhất của cửa hàng.');
        }

        $reconciliation->update([
            'status' => 'rejected',
            'notes' => $validated['notes'],
        ]);

        $financialRecon = FinancialReconciliation::query()
            ->where('store_id', $reconciliation->store_id)
            ->whereDate('period_date', $reconciliation->reconciliation_date)
            ->orderByDesc('created_at')
            ->first();

        if ($financialRecon) {
            $financialRecon->status = 'rejected';
            $financialRecon->save();

            event(new StoreFinanceReconciliationUpdated(
                (string) $reconciliation->store_id,
                (string) ($financialRecon->period ?? 'day'),
                $financialRecon->period_date?->toDateString() ?? (string) $reconciliation->reconciliation_date,
                'rejected',
                0,
                [
                    'id' => (string) $financialRecon->id,
                    'step' => 0,
                    'status' => (string) $financialRecon->status,
                    'period' => (string) $financialRecon->period,
                    'period_date' => $financialRecon->period_date?->toDateString(),
                    'revenue_total' => (float) $financialRecon->revenue_total,
                    'revenue_cash' => (float) $financialRecon->revenue_cash,
                    'revenue_transfer' => (float) $financialRecon->revenue_transfer,
                    'revenue_cod' => (float) $financialRecon->revenue_cod,
                    'affiliate_commission' => (float) $financialRecon->affiliate_commission,
                    'ticket_reward' => (float) $financialRecon->ticket_reward,
                    'net_amount' => (float) $financialRecon->net_amount,
                    'confirmed_at' => optional($financialRecon->confirmed_at)?->toIso8601String(),
                    'paid_at' => optional($financialRecon->paid_at)?->toIso8601String(),
                    'updated_at' => optional($financialRecon->updated_at)?->toIso8601String(),
                ]
            ));
        }

        // TODO: Send rejection email to store admin

        return redirect()->route('admin.reconciliation.index')
            ->with('success', 'Đã từ chối đối soát');
    }
}

