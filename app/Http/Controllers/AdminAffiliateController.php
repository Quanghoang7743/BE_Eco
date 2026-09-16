<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Services\AffiliateReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAffiliateController extends Controller
{
    public function __construct(private readonly AffiliateReportService $affiliateReportService)
    {
    }

    public function index(Request $request): View
    {
        $this->ensureAdmin();

        return view('admin.affiliate.reports');
    }

    public function dashboard(Request $request): View
    {
        $this->ensureAdmin();

        // 1. Total Customers (referred via affiliate)
        $totalCustomers = \App\Models\AffiliateCustomer::count();
        $newCustomersThisMonth = \App\Models\AffiliateCustomer::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $newCustomersLastMonth = \App\Models\AffiliateCustomer::whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $customerGrowth = $newCustomersLastMonth > 0 ? (($newCustomersThisMonth - $newCustomersLastMonth) / $newCustomersLastMonth) * 100 : ($newCustomersThisMonth > 0 ? 100 : 0);

        // 2. Total Revenue (from completed affiliate orders)
        $totalRevenue = \App\Models\Order::whereNotNull('referrer_id')
            ->whereIn('status', ['completed', 'delivered', 'call_completed'])
            ->sum('final_amount');
        $revenueThisMonth = \App\Models\Order::whereNotNull('referrer_id')
            ->whereIn('status', ['completed', 'delivered', 'call_completed'])
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('final_amount');
        $revenueLastMonth = \App\Models\Order::whereNotNull('referrer_id')
            ->whereIn('status', ['completed', 'delivered', 'call_completed'])
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->sum('final_amount');
        $revenueGrowth = $revenueLastMonth > 0 ? (($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100 : ($revenueThisMonth > 0 ? 100 : 0);

        // 3. Total Orders (referred via affiliate)
        $totalOrders = \App\Models\Order::whereNotNull('referrer_id')->count();
        $ordersThisMonth = \App\Models\Order::whereNotNull('referrer_id')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $ordersLastMonth = \App\Models\Order::whereNotNull('referrer_id')
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->count();
        $ordersGrowth = $ordersLastMonth > 0 ? (($ordersThisMonth - $ordersLastMonth) / $ordersLastMonth) * 100 : ($ordersThisMonth > 0 ? 100 : 0);

        // 4. Total Withdrawal Requests
        $totalWithdrawals = \App\Models\AffiliateWithdrawalRequest::count();
        $withdrawalsThisMonth = \App\Models\AffiliateWithdrawalRequest::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $withdrawalsLastMonth = \App\Models\AffiliateWithdrawalRequest::whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $withdrawalsGrowth = $withdrawalsLastMonth > 0 ? (($withdrawalsThisMonth - $withdrawalsLastMonth) / $withdrawalsLastMonth) * 100 : ($withdrawalsThisMonth > 0 ? 100 : 0);

        // Last 12 Months Revenue data for line chart
        $labels = [];
        $values = [];
        $total12MonthsRevenue = 0;
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $revenue = (float) \App\Models\Order::whereNotNull('referrer_id')
                ->whereIn('status', ['completed', 'delivered', 'call_completed'])
                ->whereBetween('created_at', [$start, $end])
                ->sum('final_amount');

            $labels[] = 'Tháng ' . $month->format('m/Y');
            $values[] = $revenue;
            $total12MonthsRevenue += $revenue;
        }

        // Compare last 12 months with the previous 12 months
        $prev12Start = now()->subMonths(23)->startOfMonth();
        $prev12End = now()->subMonths(12)->endOfMonth();
        $prev12Revenue = (float) \App\Models\Order::whereNotNull('referrer_id')
            ->whereIn('status', ['completed', 'delivered', 'call_completed'])
            ->whereBetween('created_at', [$prev12Start, $prev12End])
            ->sum('final_amount');
        $chartGrowth = $prev12Revenue > 0 ? (($total12MonthsRevenue - $prev12Revenue) / $prev12Revenue) * 100 : ($total12MonthsRevenue > 0 ? 100 : 0);

        return view('admin.affiliate.dashboard', compact(
            'totalCustomers', 'customerGrowth',
            'totalRevenue', 'revenueGrowth',
            'totalOrders', 'ordersGrowth',
            'totalWithdrawals', 'withdrawalsGrowth',
            'labels', 'values', 'total12MonthsRevenue', 'chartGrowth'
        ));
    }

    public function datatable(Request $request)
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);

        $columns = [
            0 => 'complaint_code',
            4 => 'created_at',
        ];

        $statusLabels = [
            'pending' => 'Mới tiếp nhận',
            'processing' => 'Đang xử lý',
            'resolved' => 'Đã duyệt',
            'closed' => 'Đã đóng',
            'new' => 'Mới báo cáo',
        ];
        $severityLabels = [
            'low' => 'Thấp',
            'normal' => 'Bình thường',
            'high' => 'Cao',
            'critical' => 'Khẩn cấp',
        ];

        $orderColumn = (int) $request->input('order.0.column', 4);
        $orderField = $columns[$orderColumn] ?? 'created_at';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $query = $this->affiliateReportService->buildReportsQuery($validated);
        $recordsTotal = (clone $query)->count();

        $searchValue = (string) $request->input('search.value', '');
        if ($searchValue !== '') {
            $query->where(function ($sub) use ($searchValue) {
                $sub->where('complaint_code', 'like', '%' . $searchValue . '%')
                    ->orWhere('title', 'like', '%' . $searchValue . '%')
                    ->orWhere('description', 'like', '%' . $searchValue . '%')
                    ->orWhere('user_id', 'like', '%' . $searchValue . '%');
            });
        }

        $recordsFiltered = $query->count();

        $complaints = $query
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $mapped = $this->affiliateReportService->transformReports(collect($complaints));

        $data = $mapped->map(function ($report) use ($statusLabels, $severityLabels) {
            return [
                'complaint_code' => view('admin.affiliate.partials.datatable-complaint-code', compact('report', 'severityLabels'))->render(),
                'users_info' => view('admin.affiliate.partials.datatable-users-info', compact('report'))->render(),
                'current_upline' => view('admin.affiliate.partials.datatable-current-upline', compact('report'))->render(),
                'status' => view('admin.affiliate.partials.datatable-status', compact('report', 'statusLabels'))->render(),
                'created_at' => view('admin.affiliate.partials.datatable-created-at', compact('report'))->render(),
                'actions' => view('admin.affiliate.partials.datatable-actions', compact('report'))->render(),
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function changeUpline(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'action' => ['required', 'in:detach,transfer'],
            'new_referrer_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->affiliateReportService->changeUpline(
            $complaint,
            (string) $validated['action'],
            isset($validated['new_referrer_id']) ? (int) $validated['new_referrer_id'] : null,
            (int) $request->user()->id
        );

        return back()->with('success', 'Đã phê duyệt xử lý affiliate report thành công.');
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
    }
}