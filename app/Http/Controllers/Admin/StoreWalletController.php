<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Admin\StoreWalletService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreWalletController extends Controller
{
    public function __construct(
        private readonly StoreWalletService $storeWalletService,
    ) {}

    // =========================================================================
    // GET /admin/store-wallets
    // Danh sách ví cửa hàng
    // =========================================================================
    public function index(Request $request): View
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'keyword'            => ['nullable', 'string', 'max:255'],
            'is_debt_locked'     => ['nullable', 'in:0,1'],
            'credit_level'       => ['nullable', 'integer', 'min:1'],
            'status'             => ['nullable', 'string', 'max:50'],
            'min_wallet_balance' => ['nullable', 'numeric'],
            'max_wallet_balance' => ['nullable', 'numeric'],
            'min_ticket_debt'    => ['nullable', 'numeric'],
            'max_ticket_debt'    => ['nullable', 'numeric'],
            'sort_by'            => ['nullable', 'string', 'max:50'],
            'sort_direction'     => ['nullable', 'in:asc,desc'],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->storeWalletService->getWalletList($validated);

        $stores = collect($paginator->items())
            ->map(fn ($store) => $this->storeWalletService->formatStoreWallet($store));

        // Thống kê tổng quan nhanh (top summary cards)
        $summary = $this->storeWalletService->getSummary();

        return view('admin.store_wallets.index', [
            'stores'      => $stores,
            'paginator'   => $paginator,
            'summary'     => $summary,
            'filters'     => $validated,
        ]);
    }

    // =========================================================================
    // GET /admin/store-wallets/summary
    // Trang thống kê tổng quan ví (trả JSON cho AJAX)
    // =========================================================================
    public function summary(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();

        $data = $this->storeWalletService->getSummary();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // =========================================================================
    // GET /admin/store-wallets/{store}
    // Chi tiết ví một cửa hàng
    // =========================================================================
    public function show(Request $request, string $store): View
    {
        $this->ensureSuperAdmin();

        $storeModel = $this->storeWalletService->getWalletDetail($store);

        $walletData     = $this->storeWalletService->formatStoreWallet($storeModel);
        $walletData['created_at'] = $storeModel->created_at?->toIso8601String();

        $recentTransactions = collect($storeModel->getRelation('recentTransactions') ?? [])
            ->map(fn ($tx) => $this->storeWalletService->formatTransaction($tx));

        $stats = $this->storeWalletService->getWalletStats($store);

        return view('admin.store_wallets.show', [
            'wallet'             => $walletData,
            'recentTransactions' => $recentTransactions,
            'storeId'            => $store,
            'totalDeposit'       => $stats['total_deposit'],
            'totalSpent'         => $stats['total_spent'],
        ]);
    }

    // =========================================================================
    // GET /admin/store-wallets/{store}/transactions
    // Lịch sử nạp/rút ví (trả JSON để DataTable AJAX load)
    // =========================================================================
    public function transactions(Request $request, string $store): JsonResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'type'      => ['nullable', 'string', 'max:50'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'keyword'   => ['nullable', 'string', 'max:255'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->storeWalletService->getTransactions($store, $validated);

        $items = collect($paginator->items())
            ->map(fn ($tx) => $this->storeWalletService->formatTransaction($tx))
            ->values()
            ->all();

        $storeModel = Store::findOrFail($store);
        $stats = $this->storeWalletService->getWalletStats($store);

        return response()->json([
            'success' => true,
            'data'    => [
                'store_id'        => $store,
                'current_balance' => number_format((float)($storeModel->wallet_balance ?? 0), 2, '.', ''),
                'total_deposit'   => number_format($stats['total_deposit'], 2, '.', ''),
                'total_spent'     => number_format($stats['total_spent'], 2, '.', ''),
                'transactions'    => $items,
                'items'           => $items,
                'pagination'      => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function ensureSuperAdmin(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && in_array((string) $user->role, ['admin', 'super_admin', 'accountant'], true),
            403,
            'Chỉ admin tổng hoặc kế toán mới được phép truy cập chức năng này.'
        );
    }
}
