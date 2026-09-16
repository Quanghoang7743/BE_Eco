<?php

namespace App\Http\Controllers\Api;

use App\Events\StoreFinanceReconciliationUpdated;
use App\Http\Controllers\Controller;
use App\Models\FinancialReconciliation;
use App\Models\Reconciliation;
use App\Models\Store;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use App\Services\StoreCreditService;
use App\Services\StoreFinanceReconciliationService;
use App\Services\StoreFinanceWithdrawalService;
use App\Services\TicketPricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class StoreAdminFinanceController extends Controller
{
    public function __construct(
        private readonly StoreAdminStaffAccess $access,
        private readonly StoreFinanceReconciliationService $reconciliationService,
        private readonly StoreFinanceWithdrawalService $withdrawalService
    )
    {
    }

    public function summary(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->query(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();
        $usesExplicitRange = !empty($validated['from']) || !empty($validated['to']);
        if ($usesExplicitRange) {
            $from = Carbon::createFromFormat('Y-m-d', $validated['from'] ?? $baseDate->toDateString())->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $validated['to'] ?? $from->toDateString())->endOfDay();
        } else {
            [$from, $to] = $this->resolvePeriodRange($period, $baseDate);
        }

        // Find the last confirmed reconciliation (if any) to only calculate NEW orders since then
        $lastConfirmedForPeriod = null;
        if (!$usesExplicitRange && Schema::hasTable('financial_reconciliations')) {
            $lastConfirmedForPeriod = FinancialReconciliation::query()
                ->where('store_id', $store->id)
                ->where('period', $period)
                ->whereDate('period_date', $from->toDateString())
                ->where('status', '<>', 'pending_shop')
                ->orderByDesc('confirmed_at')
                ->orderByDesc('created_at')
                ->first();
        }

        $amountColumn = $this->resolveAmountColumn();
        $orderDateExpression = $this->resolveOrderDateExpression();
        // Alias-specific expression for queries that alias orders as `o`
        $orderDateExpressionO = $this->resolveOrderDateExpression('o');

        // Calculate CUMULATIVE revenue (all orders in period) for grid cards
        $ordersQueryAll = DB::table('orders')
            ->where('store_id', $store->id)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $ordersQueryAll->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $paymentRowsAll = $ordersQueryAll
            ->selectRaw('LOWER(COALESCE(payment_method, "")) as method')
            ->selectRaw('COALESCE(SUM(`' . $amountColumn . '`), 0) as total_amount')
            ->groupBy('method')
            ->get();

        $revenueBucketsAll = [
            'cash' => 0.0,
            'transfer' => 0.0,
            'cod' => 0.0,
            'other' => 0.0,
        ];

        foreach ($paymentRowsAll as $row) {
            $method = (string) ($row->method ?? '');
            $amount = (float) ($row->total_amount ?? 0);
            $bucket = $this->mapPaymentMethodBucket($method);
            $revenueBucketsAll[$bucket] += $amount;
        }

        $revenueBucketsAll['transfer'] += $this->sumPendingTransferRevenue($store->id, $from, $to, null);
        $revenueTotalAll = array_sum($revenueBucketsAll);

        // Calculate NEW revenue (only after last confirmed) for draft record update
        $ordersQueryNew = DB::table('orders')
            ->where('store_id', $store->id)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $ordersQueryNew->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        // Only include orders created AFTER the last confirmed reconciliation
        if ($lastConfirmedForPeriod && $lastConfirmedForPeriod->confirmed_at) {
            $ordersQueryNew->where('created_at', '>', $lastConfirmedForPeriod->confirmed_at);
        }

        $paymentRowsNew = $ordersQueryNew
            ->selectRaw('LOWER(COALESCE(payment_method, "")) as method')
            ->selectRaw('COALESCE(SUM(`' . $amountColumn . '`), 0) as total_amount')
            ->groupBy('method')
            ->get();

        $revenueBucketsNew = [
            'cash' => 0.0,
            'transfer' => 0.0,
            'cod' => 0.0,
            'other' => 0.0,
        ];

        foreach ($paymentRowsNew as $row) {
            $method = (string) ($row->method ?? '');
            $amount = (float) ($row->total_amount ?? 0);
            $bucket = $this->mapPaymentMethodBucket($method);
            $revenueBucketsNew[$bucket] += $amount;
        }

        $revenueBucketsNew['transfer'] += $this->sumPendingTransferRevenue($store->id, $from, $to, $lastConfirmedForPeriod?->confirmed_at);
        $revenueTotalNew = array_sum($revenueBucketsNew);

        // Commission and ticket reward: get both cumulative and new
        $commissionTotalAll = $this->sumAffiliateCommission($store->id, $from, $to, null);
        $commissionTotalAll += $this->sumPendingTransferAffiliateCommission($store->id, $from, $to, null);

        $commissionTotalNew = $this->sumAffiliateCommission($store->id, $from, $to, $lastConfirmedForPeriod?->confirmed_at);
        $commissionTotalNew += $this->sumPendingTransferAffiliateCommission($store->id, $from, $to, $lastConfirmedForPeriod?->confirmed_at);

        $ticketRewardTotalAll = $this->sumTicketReward($store->id, $from, $to, null);
        $ticketRewardTotalNew = $this->sumTicketReward($store->id, $from, $to, $lastConfirmedForPeriod?->confirmed_at);

        $store->refresh();
        $creditSnapshot = $this->buildStoreFinanceCreditSnapshot($store);
        $cashCollectedAll = $this->signedMoney($revenueBucketsAll['cash'] + $revenueBucketsAll['cod']);
        $cashCollectedNew = $this->signedMoney($revenueBucketsNew['cash'] + $revenueBucketsNew['cod']);
        $refundAmountAll = $this->sumRefundAmount($store->id, $from, $to);
        $walletForWithdrawal = $this->signedMoney($creditSnapshot['wallet_balance_for_withdrawal']);
        $netAmountAll = $walletForWithdrawal;
        $netAmountNew = $walletForWithdrawal;
        $orderStats = $this->buildOrderStats($store->id, $from, $to);

        $reconciliation = null;
        $lastConfirmed = null;
        if (!$usesExplicitRange && Schema::hasTable('financial_reconciliations')) {
            $record = FinancialReconciliation::query()
                ->where('store_id', $store->id)
                ->where('period', $period)
                ->whereDate('period_date', $from->toDateString())
                ->orderByRaw("CASE WHEN status = 'pending_shop' THEN 0 WHEN status = 'waiting_payment' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at')
                ->orderByDesc('updated_at')
                ->first();

            if ($record) {
                // If this is a draft record, update it with the latest calculation from NEW orders only
                // This ensures the formula panel always shows only new orders since last confirmed submission
                if ($record->status === 'pending_shop') {
                    $record->update($this->filterFinancialReconciliationColumns([
                        'revenue_total' => $revenueTotalNew,
                        'revenue_cash' => $revenueBucketsNew['cash'],
                        'revenue_transfer' => $revenueBucketsNew['transfer'],
                        'revenue_cod' => $revenueBucketsNew['cod'],
                        'affiliate_commission' => $commissionTotalNew,
                        'ticket_reward' => $ticketRewardTotalNew,
                        'cash_collected_amount' => $cashCollectedNew,
                        'withdrawable_amount' => $netAmountNew,
                        'net_amount' => $netAmountNew,
                    ]));
                }

                $reconciliation = $this->serializeReconciliation($record);
            }

            // Also fetch the last non-draft reconciliation (useful for UI when a pending_shop draft
            // contains zeroed numbers but we still want to show the last confirmed amounts).
            $confirmedRecord = FinancialReconciliation::query()
                ->where('store_id', $store->id)
                ->where('period', $period)
                ->whereDate('period_date', $from->toDateString())
                ->where('status', '<>', 'pending_shop')
                ->orderByDesc('created_at')
                ->orderByDesc('updated_at')
                ->first();

            if ($confirmedRecord) {
                $lastConfirmed = $this->serializeReconciliation($confirmedRecord);
            }
        }

        return response()->json([
            'data' => [
                'period' => $period,
                'date' => $baseDate->toDateString(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => 'VND',
                'revenue' => [
                    'total' => round($revenueTotalAll, 2),
                    'cash' => round($revenueBucketsAll['cash'], 2),
                    'transfer' => round($revenueBucketsAll['transfer'], 2),
                    'cod' => round($revenueBucketsAll['cod'], 2),
                    'other' => round($revenueBucketsAll['other'], 2),
                ],
                'deductions' => [
                    'affiliate_commission' => round($commissionTotalAll, 2),
                    'ticket_reward' => round($ticketRewardTotalAll, 2),
                ],
                'stats' => $orderStats,
                'credit' => [
                    'wallet_balance' => round($creditSnapshot['wallet_balance'], 2),
                    'ticket_debt' => round($creditSnapshot['ticket_debt'], 2),
                    'account_balance' => round($creditSnapshot['account_balance'], 2),
                    'wallet_balance_for_withdrawal' => round($walletForWithdrawal, 2),
                ],
                'formula' => [
                    'version' => 'wallet_includes_affiliate_commission_v1',
                    'wallet_balance_for_withdrawal' => round($walletForWithdrawal, 2),
                    'affiliate_commission' => round($commissionTotalAll, 2),
                    'cash_collected_amount' => round($cashCollectedAll, 2),
                    'refund_amount' => round($refundAmountAll, 2),
                    'net_amount' => round($netAmountAll, 2),
                ],
                'cash_collected_amount' => round($cashCollectedAll, 2),
                'refund_amount' => round($refundAmountAll, 2),
                'withdrawable_amount' => round($netAmountAll, 2),
                'net_amount' => round($netAmountAll, 2),
                'reconciliation' => $reconciliation,
                'last_reconciliation' => $lastConfirmed,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function recordDeposit(Request $request, StoreCreditService $creditService): JsonResponse
    {
        return $this->errorResponse(
            'MANUAL_STORE_DEPOSIT_DISABLED',
            'Manual store wallet deposits are disabled. Use the verified wallet deposit payment flow.',
            403,
            false,
            (bool) $request->user()
        );

        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['required', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $status = strtolower(trim((string) ($validated['status'] ?? 'success')));
        if (! in_array($status, ['success', 'paid', 'completed', 'settled', 'confirmed'], true)) {
            return response()->json([
                'data' => [
                    'store_id' => (string) $store->id,
                    'applied' => false,
                    'ignored' => true,
                    'reason' => 'deposit_status_not_successful',
                    'status' => $status,
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        try {
            $result = $creditService->recordWalletDeposit(
                (string) $store->id,
                (float) $validated['amount'],
                (string) $validated['reference'],
                array_merge($validated['metadata'] ?? [], [
                    'source' => 'store_admin_finance_deposit',
                    'recorded_by' => $user?->id ?? null,
                    'reference' => (string) $validated['reference'],
                    'status' => $status,
                ])
            );
        } catch (Throwable $exception) {
            return $this->errorResponse('STORE_DEPOSIT_FAILED', $exception->getMessage(), 422, true, true);
        }

        $store->refresh();

        return response()->json([
            'data' => [
                'store_id' => (string) $store->id,
                'deposit' => $result,
                'credit' => [
                    'credit_limit' => (float) ($store->credit_limit ?? 0),
                    'ticket_debt' => (float) ($store->ticket_debt ?? 0),
                    'wallet_balance' => (float) ($store->wallet_balance ?? 0),
                    'credit_version' => (int) ($store->credit_version ?? 0),
                    'is_debt_locked' => (bool) ($store->is_debt_locked ?? false),
                    'debt_locked_at' => optional($store->debt_locked_at)?->toIso8601String(),
                    'debt_lock_reason' => $store->debt_lock_reason ?? null,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function createWalletDepositPreOrder(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1000'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Số tiền nạp không hợp lệ.', 422, true, true, $validator->errors()->toArray());
        }

        $amount = (float) $request->input('amount');
        $preOrderCode = 'P' . strtoupper(substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 10));

        $preOrder = \App\Models\PreOrder::create([
            'pre_order_code' => $preOrderCode,
            'user_id' => $user->id,
            'amount' => $amount,
            'payload' => [
                'pre_order_type' => 'wallet_deposit',
                'store_id' => (string) $store->id,
                'user_id' => $user->id,
                'amount' => $amount,
            ],
            'status' => 'pending',
        ]);

        // Fetch bank info
        $settings = \App\Models\appSetting::first();
        $bankId = trim((string) ($settings?->vietqr_bank_id ?: env('VIETQR_BANK_ID', 'MB')));
        $accountNo = trim((string) ($settings?->vietqr_account_no ?: env('VIETQR_ACCOUNT_NO')));
        $accountName = trim((string) ($settings?->vietqr_account_name ?: env('VIETQR_ACCOUNT_NAME')));

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $preOrder->id,
                'pre_order_code' => $preOrder->pre_order_code,
                'amount' => $amount,
                'status' => $preOrder->status,
                'bank_id' => $bankId,
                'account_no' => $accountNo,
                'account_name' => $accountName,
            ],
        ]);
    }

    public function affiliateOverview(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $now = now();
        [$dayFrom, $dayTo] = $this->resolvePeriodRange('day', $now);
        [$monthFrom, $monthTo] = $this->resolvePeriodRange('month', $now);
        $amountColumn = $this->resolveAmountColumn();
        $orderDateExpression = $this->resolveOrderDateExpression();
        $orderDateExpressionO = $this->resolveOrderDateExpression('o');

        $revenueToday = 0.0;
        if (Schema::hasTable('orders')) {
            $revenueTodayQuery = DB::table('orders')
                ->where('store_id', $store->id)
                ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$dayFrom->toDateString()])
                ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$dayTo->toDateString()]);

            if (Schema::hasColumn('orders', 'status')) {
                $revenueTodayQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
            }

            $revenueToday = (float) $revenueTodayQuery->sum($amountColumn);
        }

        $commissionToday = $this->sumAffiliateCommission($store->id, $dayFrom, $dayTo, null)
            + $this->sumPendingTransferAffiliateCommission($store->id, $dayFrom, $dayTo, null);
        $merchantNetToday = max(0, $revenueToday - $commissionToday);

        $pendingCommissionMonth = 0.0;
        $paidCommissionMonth = 0.0;
        if (Schema::hasTable('commission_users') && Schema::hasTable('order_items')) {
            $baseCommissionQuery = DB::table('commission_users as cu')
                ->join('order_items as oi', 'oi.id', '=', 'cu.order_item_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.store_id', $store->id)
                ->whereRaw('DATE(' . $orderDateExpressionO . ') >= ?', [$monthFrom->toDateString()])
                ->whereRaw('DATE(' . $orderDateExpressionO . ') <= ?', [$monthTo->toDateString()]);

            if (Schema::hasColumn('orders', 'status')) {
                $baseCommissionQuery->whereIn(DB::raw('LOWER(COALESCE(o.status, ""))'), $this->completedOrderStatuses());
            }

            if (Schema::hasColumn('commission_users', 'status')) {
                $pendingCommissionMonth = (float) (clone $baseCommissionQuery)
                    ->where('cu.status', 'pending')
                    ->sum('cu.amount');
                $paidCommissionMonth = (float) (clone $baseCommissionQuery)
                    ->where('cu.status', 'paid')
                    ->sum('cu.amount');
            }
        }

        $activeProducts = 0;
        $previewOrders = 0;
        $products = [];
        $productRows = collect();

        if (Schema::hasTable('products')) {
            $productSelects = [
                'p.id',
                'p.name',
            ];

            if (Schema::hasColumn('products', 'slug')) {
                $productSelects[] = 'p.slug';
            }
            if (Schema::hasColumn('products', 'thumbnail')) {
                $productSelects[] = DB::raw('p.thumbnail as image_url');
            } else {
                $productSelects[] = DB::raw('NULL as image_url');
            }
            if (Schema::hasColumn('products', 'price')) {
                $productSelects[] = DB::raw('p.price as price');
            } else {
                $productSelects[] = DB::raw('NULL as price');
            }
            if (Schema::hasColumn('products', 'sale_price')) {
                $productSelects[] = DB::raw('p.sale_price as sale_price');
            } else {
                $productSelects[] = DB::raw('NULL as sale_price');
            }
            if (Schema::hasColumn('products', 'commission_rate')) {
                $productSelects[] = DB::raw('p.commission_rate as commission_rate');
            } else {
                $productSelects[] = DB::raw('NULL as commission_rate');
            }
            if (Schema::hasColumn('products', 'commission_rate_mode')) {
                $productSelects[] = DB::raw('p.commission_rate_mode as commission_rate_mode');
            } else {
                $productSelects[] = DB::raw('NULL as commission_rate_mode');
            }
            if (Schema::hasColumn('products', 'affiliate_commission_enabled')) {
                $productSelects[] = DB::raw('p.affiliate_commission_enabled as affiliate_commission_enabled');
            } else {
                $productSelects[] = DB::raw('NULL as affiliate_commission_enabled');
            }
            if (Schema::hasColumn('products', 'affiliate_commission_type')) {
                $productSelects[] = DB::raw('p.affiliate_commission_type as affiliate_commission_type');
            } else {
                $productSelects[] = DB::raw('NULL as affiliate_commission_type');
            }
            if (Schema::hasColumn('products', 'affiliate_commission_value')) {
                $productSelects[] = DB::raw('p.affiliate_commission_value as affiliate_commission_value');
            } else {
                $productSelects[] = DB::raw('NULL as affiliate_commission_value');
            }

            $productRows = DB::table('products as p')
                ->where('p.store_id', $store->id)
                ->orderByDesc('p.updated_at')
                ->orderByDesc('p.created_at')
                ->get($productSelects);

            $productIds = $productRows->pluck('id')->filter()->values()->all();
            $statsByProductId = [];

            if (!empty($productIds)
                && Schema::hasTable('order_items')
                && Schema::hasTable('orders')
                && Schema::hasColumn('order_items', 'product_id')) {
                $statsQuery = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.store_id', $store->id)
                    ->whereIn('oi.product_id', $productIds);

                if (Schema::hasColumn('orders', 'status')) {
                    $statsQuery->whereIn(DB::raw('LOWER(COALESCE(o.status, ""))'), $this->completedOrderStatuses());
                }

                $statsRows = $statsQuery
                    ->selectRaw('oi.product_id as product_id')
                    ->selectRaw('COALESCE(SUM(COALESCE(oi.quantity, 1)), 0) as sold_count')
                    ->selectRaw('COUNT(DISTINCT o.id) as conversion_count')
                    ->groupBy('oi.product_id')
                    ->get();

                foreach ($statsRows as $row) {
                    $statsByProductId[(string) ($row->product_id ?? '')] = [
                        'sold_count' => (int) ($row->sold_count ?? 0),
                        'conversion_count' => (int) ($row->conversion_count ?? 0),
                    ];
                }
            }

            foreach ($productRows as $row) {
                $commissionValue = (float) (
                    $row->commission_rate
                    ?? $row->affiliate_commission_value
                    ?? 0
                );
                $commissionMode = (string) (
                    $row->commission_rate_mode
                    ?? $row->affiliate_commission_type
                    ?? 'percent'
                );
                if ($commissionMode !== 'fixed') {
                    $commissionMode = 'percent';
                }
                $commissionEnabled = isset($row->affiliate_commission_enabled)
                    ? (bool) $row->affiliate_commission_enabled
                    : $commissionValue > 0;

                if ($commissionEnabled && $commissionValue > 0) {
                    $activeProducts++;
                }

                $stats = $statsByProductId[(string) $row->id] ?? [
                    'sold_count' => 0,
                    'conversion_count' => 0,
                ];

                $previewOrders += $stats['sold_count'];

                $products[] = [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'slug' => isset($row->slug) ? (string) $row->slug : null,
                    'image_url' => $row->image_url ?? null,
                    'price' => (float) ($row->price ?? $row->sale_price ?? 0),
                    'sold_count' => $stats['sold_count'],
                    'conversion_count' => $stats['conversion_count'],
                    'commission' => [
                        'enabled' => $commissionEnabled,
                        'mode' => $commissionMode,
                        'value' => $commissionValue,
                    ],
                ];
            }
        }

        $repeatOrders = 0;
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'user_id')) {
            $repeatOrdersQuery = DB::table('orders')
                ->where('store_id', $store->id)
                ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$monthFrom->toDateString()])
                ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$monthTo->toDateString()]);

            if (Schema::hasColumn('orders', 'status')) {
                $repeatOrdersQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
            }

            $repeatRows = $repeatOrdersQuery
                ->select('user_id')
                ->selectRaw('COUNT(*) as order_count')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($repeatRows as $row) {
                $repeatOrders += max(0, (int) ($row->order_count ?? 0) - 1);
            }
        }

        $reconciliation = [];
        if (Schema::hasTable('commission_users') && Schema::hasTable('order_items') && Schema::hasTable('orders')) {
            $reconciliationQuery = DB::table('commission_users as cu')
                ->join('order_items as oi', 'oi.id', '=', 'cu.order_item_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->leftJoin('users as u', 'u.id', '=', 'cu.user_id')
                ->where('o.store_id', $store->id)
                ->whereRaw('DATE(' . $orderDateExpressionO . ') >= ?', [$monthFrom->toDateString()])
                ->whereRaw('DATE(' . $orderDateExpressionO . ') <= ?', [$monthTo->toDateString()]);

            if (Schema::hasColumn('orders', 'status')) {
                $reconciliationQuery->whereIn(DB::raw('LOWER(COALESCE(o.status, ""))'), $this->completedOrderStatuses());
            }

            $reconciliationRows = $reconciliationQuery
                ->selectRaw('cu.user_id as user_id')
                ->selectRaw('COALESCE(MAX(u.full_name), MAX(u.name), CONCAT("User #", cu.user_id)) as affiliate_name')
                ->selectRaw('COUNT(DISTINCT o.id) as completed_orders')
                ->selectRaw('COALESCE(SUM(cu.amount), 0) as total_commission')
                ->selectRaw('MAX(cu.created_at) as processed_at')
                ->selectRaw(
                    Schema::hasColumn('commission_users', 'status')
                        ? 'SUM(CASE WHEN cu.status = "pending" THEN 1 ELSE 0 END) as pending_count'
                        : '0 as pending_count',
                )
                ->groupBy('cu.user_id')
                ->orderByDesc('total_commission')
                ->limit(3)
                ->get();

            foreach ($reconciliationRows as $row) {
                $processedAt = $row->processed_at ? Carbon::parse($row->processed_at) : $now;
                $pendingCount = (int) ($row->pending_count ?? 0);

                $reconciliation[] = [
                    'id' => (string) ($row->user_id ?? $row->affiliate_name ?? Str::uuid()),
                    'affiliate_name' => (string) ($row->affiliate_name ?? ''),
                    'completed_orders' => (int) ($row->completed_orders ?? 0),
                    'total_commission' => (float) ($row->total_commission ?? 0),
                    'status' => $pendingCount > 0 ? 'scheduled' : 'ready',
                    'processed_at' => $processedAt->format('H:i'),
                ];
            }
        }

        return response()->json([
            'data' => [
                'period' => 'month',
                'date' => $now->toDateString(),
                'from' => $monthFrom->toDateString(),
                'to' => $monthTo->toDateString(),
                'currency' => 'VND',
                'wallet' => [
                    'pending' => round($pendingCommissionMonth, 2),
                    'available' => round($paidCommissionMonth, 2),
                    'paid_this_month' => round($paidCommissionMonth, 2),
                    'repeat_orders' => $repeatOrders,
                ],
                'stats' => [
                    'commission_today' => round($commissionToday, 2),
                    'merchant_net_today' => round($merchantNetToday, 2),
                    'active_products' => $activeProducts,
                    'preview_orders' => $previewOrders,
                ],
                'products' => $products,
                'reconciliation' => $reconciliation,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => $now->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
    

    public function confirm(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('financial_reconciliations')) {
            return $this->errorResponse('RECONCILIATION_NOT_SUPPORTED', 'Bang doi soat chua duoc tao.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();
        try {
            $record = $this->reconciliationService->submitForAdminReview(
                $store,
                $period,
                $baseDate,
                'store_admin',
                $user
            );
        } catch (\Throwable $ex) {
            logger()->error('Failed to submit store finance reconciliation: ' . $ex->getMessage());

            return $this->errorResponse('RECONCILIATION_SUBMIT_FAILED', $ex->getMessage(), 422, true, true);
        }

        return response()->json([
            'data' => $this->reconciliationService->serialize($record),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function getStatus(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->query(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        if (!Schema::hasTable('financial_reconciliations')) {
            return response()->json([
                'data' => [
                    'step' => 1,
                    'has_record' => false,
                    'reconciliation' => null,
                    'reconciliation_prompt' => [
                        'show' => false,
                        'reason' => 'RECONCILIATION_NOT_SUPPORTED',
                        'message' => 'Bang doi soat chua duoc tao.',
                        'action_label' => null,
                        'period' => null,
                        'date' => null,
                        'last_reconciliation' => null,
                    ],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();
        [$periodStart, $periodEnd] = $this->resolvePeriodRange($period, $baseDate);

        $record = FinancialReconciliation::query()
            ->where('store_id', $store->id)
            ->where('period', $period)
            ->whereDate('period_date', $periodStart->toDateString())
            ->where('status', '<>', 'pending_shop')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('created_at')
            ->first();

        if (!$record) {
            $record = FinancialReconciliation::query()
                ->where('store_id', $store->id)
                ->where('period', $period)
                ->whereDate('period_date', $periodStart->toDateString())
                ->where('status', 'pending_shop')
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->first();
        }

        $withdrawalStatus = $this->withdrawalService->status($store, $period, $baseDate);
        $reconciliationPrompt = $this->buildReconciliationPrompt((string) $store->id, $period, $baseDate);

        return response()->json([
            'data' => [
                'step' => (int) ($withdrawalStatus['reconciliation']['step'] ?? ($record ? $this->calculateStep($record) : 1)),
                'has_record' => (bool) ($withdrawalStatus['reconciliation'] ?? $record),
                'reconciliation' => $withdrawalStatus['reconciliation'] ?? ($record ? $this->serializeReconciliation($record) : null),
                'reconciliation_prompt' => $reconciliationPrompt,
                'withdrawal' => $withdrawalStatus,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function createWithdrawal(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('financial_reconciliations') || !Schema::hasTable('store_wallet_withdrawal_requests')) {
            return $this->errorResponse('WITHDRAWAL_NOT_SUPPORTED', 'Chuc nang rut tien chua san sang.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:50000'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();

        try {
            $withdrawal = $this->withdrawalService->createWithdrawal($store, $period, $baseDate, [
                'amount' => (float) $validated['amount'],
                'idempotency_key' => (string) ($validated['idempotency_key'] ?? Str::uuid()),
            ], $user);

            $freshStore = Store::query()->whereKey((string) $store->id)->first() ?? $store;
            $withdrawalStatus = $this->withdrawalService->status($freshStore, $period, $baseDate);
        } catch (Throwable $ex) {
            $code = trim($ex->getMessage()) ?: 'WITHDRAWAL_CREATE_FAILED';

            return $this->errorResponse($code, $this->withdrawalErrorMessage($code), 422, true, true);
        }

        return response()->json([
            'data' => [
                'withdrawal' => $this->withdrawalService->serializeWithdrawal($withdrawal),
                'status' => $withdrawalStatus,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
        }

        if (!$context['store']) {
            return [
                null,
                $context['user'],
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function resolveAmountColumn(): string
    {
        if (Schema::hasColumn('orders', 'final_amount')) {
            return 'final_amount';
        }

        if (Schema::hasColumn('orders', 'total_amount')) {
            return 'total_amount';
        }

        if (Schema::hasColumn('orders', 'total')) {
            return 'total';
        }

        return 'final_amount';
    }

    private function resolvePeriodRange(string $period, Carbon $baseDate): array
    {
        if ($period === 'month') {
            return [$baseDate->copy()->startOfMonth(), $baseDate->copy()->endOfMonth()];
        }

        if ($period === 'year') {
            return [$baseDate->copy()->startOfYear(), $baseDate->copy()->endOfYear()];
        }

        return [$baseDate->copy()->startOfDay(), $baseDate->copy()->endOfDay()];
    }

    private function mapPaymentMethodBucket(string $method): string
    {
        $value = strtolower(trim($method));

        if ($value === '') {
            return 'other';
        }

        if (str_contains($value, 'cod')) {
            return 'cod';
        }

        if (str_contains($value, 'cash') || str_contains($value, 'tien mat') || str_contains($value, 'tienmat')) {
            return 'cash';
        }

        if (str_contains($value, 'bank') || str_contains($value, 'transfer') || str_contains($value, 'vnpay') || str_contains($value, 'momo')) {
            return 'transfer';
        }

        return 'other';
    }

    private function completedOrderStatuses(): array
    {
        return ['delivered', 'completed', 'call_completed'];
    }

    private function buildOrderStats(string $storeId, Carbon $from, Carbon $to): array
    {
        $emptyStats = [
            'total_orders' => 0,
            'completed_orders' => 0,
            'canceled_orders' => 0,
            'refunded_orders' => 0,
            'tickets_used' => 0,
        ];

        if (!Schema::hasTable('orders')) {
            return $emptyStats;
        }

        $orderDateExpression = $this->resolveOrderDateExpression();
        $baseQuery = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        $hasStatus = Schema::hasColumn('orders', 'status');
        $hasStatusPayment = Schema::hasColumn('orders', 'status_payment');

        $completedQuery = clone $baseQuery;
        if ($hasStatus) {
            $completedQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $canceledOrders = 0;
        if ($hasStatus) {
            $canceledOrders = (int) (clone $baseQuery)
                ->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), ['canceled', 'cancelled'])
                ->count();
        }

        $refundedOrders = 0;
        if ($hasStatus || $hasStatusPayment) {
            $refundedOrders = (int) (clone $baseQuery)
                ->where(function ($query) use ($hasStatus, $hasStatusPayment) {
                    if ($hasStatus) {
                        $query->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), ['refunded', 'returned']);
                    }

                    if ($hasStatusPayment) {
                        if ($hasStatus) {
                            $query->orWhereRaw('LOWER(COALESCE(status_payment, "")) = ?', ['refunded']);
                        } else {
                            $query->whereRaw('LOWER(COALESCE(status_payment, "")) = ?', ['refunded']);
                        }
                    }
                })
                ->count();
        }

        $ticketsUsed = 0;
        if (Schema::hasColumn('orders', 'total_tickets_used')) {
            $ticketsUsed = (int) (clone $completedQuery)->sum('total_tickets_used');
        }

        return [
            'total_orders' => (int) (clone $baseQuery)->count(),
            'completed_orders' => (int) (clone $completedQuery)->count(),
            'canceled_orders' => $canceledOrders,
            'refunded_orders' => $refundedOrders,
            'tickets_used' => $ticketsUsed,
        ];
    }

    private function sumAffiliateCommission(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (!Schema::hasTable('commission_users') || !Schema::hasTable('order_items')) {
            return 0.0;
        }

        $orderDateExpression = $this->resolveOrderDateExpression();

        $query = DB::table('commission_users')
            ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(orders.status, ""))'), $this->completedOrderStatuses());
        }

        // Only include commissions from orders created AFTER the last confirmed reconciliation
        if ($confirmedAfter) {
            $query->where('orders.created_at', '>', $confirmedAfter);
        }

        if (Schema::hasColumn('commission_users', 'status')) {
            $query->whereIn('commission_users.status', ['paid', 'pending']);
        }

        return (float) $query->sum('commission_users.amount');
    }

    private function sumPendingTransferAffiliateCommission(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (!Schema::hasTable('commission_users') || !Schema::hasTable('order_items')) {
            return 0.0;
        }

        $orderDateExpression = $this->resolveOrderDateExpression();

        $query = DB::table('commission_users')
            ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if ($confirmedAfter) {
            $query->where('orders.created_at', '>', $confirmedAfter);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereNotIn(DB::raw('LOWER(COALESCE(orders.status, ""))'), $this->completedOrderStatuses());
        }

        if (Schema::hasColumn('orders', 'payment_method')) {
            $query->where(function ($builder) {
                $builder
                    ->whereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%bank%'])
                    ->orWhereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%transfer%'])
                    ->orWhereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%vnpay%'])
                    ->orWhereRaw('LOWER(COALESCE(orders.payment_method, "")) LIKE ?', ['%momo%']);
            });
        }

        if (Schema::hasColumn('commission_users', 'status')) {
            $query->where('commission_users.status', 'pending');
        }

        return (float) $query->sum('commission_users.amount');
    }

    private function sumPendingTransferRevenue(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        if (!Schema::hasTable('orders')) {
            return 0.0;
        }

        $amountColumn = $this->resolveAmountColumn();
        $query = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString());

        if ($confirmedAfter) {
            $query->where('created_at', '>', $confirmedAfter);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereNotIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
        }

        $query->where(function ($builder) {
            $builder
                ->whereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%bank%'])
                ->orWhereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%transfer%'])
                ->orWhereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%vnpay%'])
                ->orWhereRaw('LOWER(COALESCE(payment_method, "")) LIKE ?', ['%momo%']);
        });

        return (float) $query->sum($amountColumn);
    }

    private function sumTicketReward(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): float
    {
        $orderDateExpression = $this->resolveOrderDateExpression('o');

        if (!Schema::hasTable('order_items')) {
            return 0.0;
        }

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.store_id', $storeId)
            ->whereRaw('DATE(' . $orderDateExpression . ') >= ?', [$from->toDateString()])
            ->whereRaw('DATE(' . $orderDateExpression . ') <= ?', [$to->toDateString()]);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw('LOWER(COALESCE(o.status, ""))'), $this->completedOrderStatuses());
        }

        // Only include ticket rewards from orders created AFTER the last confirmed reconciliation
        if ($confirmedAfter) {
            $query->where('o.created_at', '>', $confirmedAfter);
        }

        $hasVariantReward = Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'ticket_reward_quantity');
        $hasProductReward = Schema::hasTable('products') && Schema::hasColumn('products', 'ticket_reward_quantity');

        if ($hasVariantReward) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id');
        }

        if ($hasProductReward) {
            $query->leftJoin('products as p', 'p.id', '=', 'oi.product_id');
        }

        $rows = $query
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw(
                $hasVariantReward && $hasProductReward
                    ? 'COALESCE(pv.ticket_reward_quantity, p.ticket_reward_quantity, 0) as ticket_reward_quantity'
                    : ($hasVariantReward
                        ? 'COALESCE(pv.ticket_reward_quantity, 0) as ticket_reward_quantity'
                        : ($hasProductReward
                            ? 'COALESCE(p.ticket_reward_quantity, 0) as ticket_reward_quantity'
                            : '0 as ticket_reward_quantity'))
            )
            ->get();

        $totalTickets = 0;
        foreach ($rows as $row) {
            $quantity = max(0, (int) ($row->quantity ?? 0));
            $rewardPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));
            $totalTickets += $quantity * $rewardPerUnit;
        }

        if ($totalTickets <= 0 && Schema::hasTable('store_ticket_transactions')) {
            $query = DB::table('store_ticket_transactions')
                ->where('store_id', $storeId)
                ->whereBetween('timestamp', [$from->toDateTimeString(), $to->toDateTimeString()]);

            // Only include transactions AFTER the last confirmed reconciliation
            if ($confirmedAfter) {
                $query->where('timestamp', '>', $confirmedAfter);
            }

            if (Schema::hasColumn('store_ticket_transactions', 'event_type')) {
                $query->where('event_type', 'ORDER_DELIVERED');
            }

            $totalTickets = (int) $query->sum('tickets_consumed');
        }

        return (float) $totalTickets * 5000;
    }

    private function resolveTicketUnitValue(): float
    {
        $config = app(TicketPricingService::class)->getConfig();

        return max(0.0, (float) ($config['ticket_value'] ?? 0));
    }

    private function resolveOrderDateExpression(string $alias = 'orders'): string
    {
        if (Schema::hasColumn('orders', 'delivered_at')) {
            return 'COALESCE(' . $alias . '.delivered_at, ' . $alias . '.created_at)';
        }

        return $alias . '.created_at';
    }

    private function buildSummarySnapshot(string $storeId, Carbon $from, Carbon $to, ?Carbon $confirmedAfter = null): array
    {
        $amountColumn = $this->resolveAmountColumn();
        $dateColumn = Schema::hasColumn('orders', 'delivered_at') ? 'delivered_at' : 'created_at';

        $ordersQuery = DB::table('orders')
            ->where('store_id', $storeId)
            ->whereDate($dateColumn, '>=', $from->toDateString())
            ->whereDate($dateColumn, '<=', $to->toDateString());

        if (Schema::hasColumn('orders', 'status')) {
            $ordersQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
            if ($confirmedAfter) {
                $ordersQuery->where('created_at', '>', $confirmedAfter);
            }
        }

        $paymentRows = $ordersQuery
            ->selectRaw('LOWER(COALESCE(payment_method, "")) as method')
            ->selectRaw('COALESCE(SUM(`' . $amountColumn . '`), 0) as total_amount')
            ->groupBy('method')
            ->get();

        $revenueBuckets = [
            'cash' => 0.0,
            'transfer' => 0.0,
            'cod' => 0.0,
            'other' => 0.0,
        ];

        foreach ($paymentRows as $row) {
            $method = (string) ($row->method ?? '');
            $amount = (float) ($row->total_amount ?? 0);
            $bucket = $this->mapPaymentMethodBucket($method);
            $revenueBuckets[$bucket] += $amount;
        }

        $revenueBuckets['transfer'] += $this->sumPendingTransferRevenue($storeId, $from, $to, $confirmedAfter);

        $revenueTotal = array_sum($revenueBuckets);
        $commissionTotal = $this->sumAffiliateCommission($storeId, $from, $to, $confirmedAfter)
            + $this->sumPendingTransferAffiliateCommission($storeId, $from, $to, $confirmedAfter);
        $ticketRewardTotal = $this->sumTicketReward($storeId, $from, $to, $confirmedAfter);
        $store = Store::query()->whereKey($storeId)->first();
        $creditSnapshot = $store ? $this->buildStoreFinanceCreditSnapshot($store) : [
            'wallet_balance_for_withdrawal' => 0.0,
        ];
        $netAmount = $this->signedMoney((float) $creditSnapshot['wallet_balance_for_withdrawal']);

        return [
            'revenue_total' => $revenueTotal,
            'revenue_cash' => $revenueBuckets['cash'],
            'revenue_transfer' => $revenueBuckets['transfer'],
            'revenue_cod' => $revenueBuckets['cod'],
            'affiliate_commission' => $commissionTotal,
            'ticket_reward' => $ticketRewardTotal,
            'cash_collected_amount' => $this->signedMoney($revenueBuckets['cash'] + $revenueBuckets['cod']),
            'withdrawable_amount' => $netAmount,
            'net_amount' => $netAmount,
        ];
    }

    public function markPaid(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('financial_reconciliations')) {
            return $this->errorResponse('RECONCILIATION_NOT_SUPPORTED', 'Bang doi soat chua duoc tao.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();
        [$periodStart, $periodEnd] = $this->resolvePeriodRange($period, $baseDate);

        $record = FinancialReconciliation::query()
            ->where('store_id', $store->id)
            ->where('period', $period)
            ->whereDate('period_date', $periodStart->toDateString())
            ->where('status', 'waiting_payment')
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();

        if (!$record) {
            return $this->errorResponse('RECORD_NOT_FOUND', 'Khong tim thay ho so doi soat.', 404, true, true);
        }

        $record->paid_at = now();
        $record->save();

        $this->broadcastFinanceUpdate($store->id, $period, $periodStart->toDateString(), 'paid', $record);

        return response()->json([
            'data' => [
                'step' => $this->calculateStep($record),
                'reconciliation' => $this->serializeReconciliation($record),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function createNew(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('financial_reconciliations')) {
            return $this->errorResponse('RECONCILIATION_NOT_SUPPORTED', 'Bang doi soat chua duoc tao.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();
        
        [$from, $to] = $this->reconciliationService->reconciliationPeriodRange((string) $store->id, $period, $baseDate);
        [$periodStart, $periodEnd] = $this->resolvePeriodRange($period, $baseDate);

        $summary = $this->buildSummarySnapshot($store->id, $from, $to);

        // Auto-delete old unapproved reconciliations
        FinancialReconciliation::query()
            ->where('store_id', $store->id)
            ->where('period', $period)
            ->whereIn('status', ['pending_shop', 'pending_admin'])
            ->whereDate('period_date', '<', $periodStart->toDateString())
            ->delete();

        if (Schema::hasTable('reconciliations')) {
            Reconciliation::query()
                ->where('store_id', $store->id)
                ->where('status', 'pending')
                ->whereDate('reconciliation_date', '<', $periodStart->toDateString())
                ->delete();
        }

        $record = FinancialReconciliation::query()
            ->where('store_id', $store->id)
            ->where('period', $period)
            ->whereDate('period_date', $periodStart->toDateString())
            ->where('status', 'pending_shop')
            ->orderByDesc('updated_at')
            ->first();

        if (!$record) {
            $record = new FinancialReconciliation();
            $record->id = (string) Str::uuid();
            $record->store_id = $store->id;
            $record->period = $period;
            $record->period_date = $periodStart->toDateString();
        }

        $record->revenue_total = $summary['revenue_total'];
        $record->revenue_cash = $summary['revenue_cash'];
        $record->revenue_transfer = $summary['revenue_transfer'];
        $record->revenue_cod = $summary['revenue_cod'];
        $record->affiliate_commission = $summary['affiliate_commission'];
        $record->ticket_reward = $summary['ticket_reward'];
        if (Schema::hasColumn('financial_reconciliations', 'cash_collected_amount')) {
            $record->cash_collected_amount = $summary['cash_collected_amount'] ?? 0;
        }
        if (Schema::hasColumn('financial_reconciliations', 'withdrawable_amount')) {
            $record->withdrawable_amount = $summary['withdrawable_amount'] ?? $summary['net_amount'];
        }
        $record->net_amount = $summary['net_amount'];
        $record->status = 'pending_shop';
        $record->confirmed_at = null;
        $record->paid_at = null;
        $record->save();

        $this->broadcastFinanceUpdate($store->id, $period, $periodStart->toDateString(), 'created', $record);

        return response()->json([
            'data' => [
                'step' => $this->calculateStep($record),
                'reconciliation' => $this->serializeReconciliation($record),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Return list of Reconciliation records for the current store and period date.
     */
    public function reconciliations(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->query(), [
            'period' => ['required', 'string', 'in:day,month,year'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $period = (string) $validated['period'];
        $baseDate = isset($validated['date']) ? Carbon::createFromFormat('Y-m-d', $validated['date']) : now();
        [$periodStart, $periodEnd] = $this->resolvePeriodRange($period, $baseDate);

        $query = Reconciliation::with('store')
            ->where('store_id', $store->id)
            ->whereDate('reconciliation_date', $periodStart->toDateString())
            ->orderByDesc('date_confirmed')
            ->orderByDesc('created_at');

        $reconciliationRows = $query->get();
        $reconciliationDates = $reconciliationRows
            ->map(fn (Reconciliation $row) => $row->reconciliation_date?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();

        $financeByDate = [];
        if (!empty($reconciliationDates)) {
            $financeRows = FinancialReconciliation::query()
                ->where('store_id', $store->id)
                ->where('period', $period)
                ->whereIn('period_date', $reconciliationDates)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->get();

            foreach ($financeRows as $financeRow) {
                $dateKey = $financeRow->period_date?->toDateString();
                if (!$dateKey || isset($financeByDate[$dateKey])) {
                    continue;
                }

                $financeByDate[$dateKey] = $financeRow;
            }
        }

        $rows = $reconciliationRows->map(function (Reconciliation $r) use ($financeByDate) {
            $dateKey = $r->reconciliation_date?->toDateString();
            /** @var FinancialReconciliation|null $financeSnapshot */
            $financeSnapshot = $dateKey ? ($financeByDate[$dateKey] ?? null) : null;

            return [
                'id' => (int) $r->id,
                'store_name' => optional($r->store)->name ?? null,
                'reconciliation_date' => $r->reconciliation_date?->toDateString() ?? null,
                'gross_revenue' => (float) $r->gross_revenue,
                'net_revenue' => (float) $r->net_revenue,
                'revenue_total' => (float) ($financeSnapshot?->revenue_total ?? $r->gross_revenue ?? 0),
                'revenue_transfer' => (float) ($financeSnapshot?->revenue_transfer ?? 0),
                'revenue_cash' => (float) ($financeSnapshot?->revenue_cash ?? 0),
                'revenue_cod' => (float) ($financeSnapshot?->revenue_cod ?? 0),
                'affiliate_commission' => (float) ($financeSnapshot?->affiliate_commission ?? 0),
                'ticket_reward' => (float) ($financeSnapshot?->ticket_reward ?? 0),
                'net_amount' => (float) ($financeSnapshot?->net_amount ?? $r->net_revenue ?? 0),
                'status' => (string) $r->status,
                'date_confirmed' => optional($r->date_confirmed)?->toIso8601String(),
            ];
        })->toArray();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function filterFinancialReconciliationColumns(array $payload): array
    {
        return array_filter(
            $payload,
            static fn (mixed $value, string $column): bool => Schema::hasColumn('financial_reconciliations', $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function buildStoreFinanceCreditSnapshot(Store $store): array
    {
        $walletBalance = $this->signedMoney($store->wallet_balance ?? 0);
        $ticketDebt = $this->signedMoney($store->ticket_debt ?? 0);
        $accountBalance = $this->signedMoney($walletBalance - $ticketDebt);

        return [
            'wallet_balance' => $walletBalance,
            'ticket_debt' => $ticketDebt,
            'account_balance' => $accountBalance,
            'wallet_balance_for_withdrawal' => $accountBalance,
        ];
    }

    private function sumRefundAmount(string $storeId, Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('order_returns')) {
            return 0.0;
        }

        $amountColumn = Schema::hasColumn('order_returns', 'refund_amount_final') ? 'refund_amount_final' : null;
        if (! $amountColumn) {
            return 0.0;
        }

        $dateColumn = Schema::hasColumn('order_returns', 'refund_processed_at')
            ? 'refund_processed_at'
            : (Schema::hasColumn('order_returns', 'updated_at') ? 'updated_at' : 'created_at');

        $query = DB::table('order_returns')
            ->where('store_id', $storeId)
            ->whereDate($dateColumn, '>=', $from->toDateString())
            ->whereDate($dateColumn, '<=', $to->toDateString());

        if (Schema::hasColumn('order_returns', 'status')) {
            $query->whereRaw("LOWER(COALESCE(status, '')) = ?", ['refunded']);
        }

        return (float) $query->sum($amountColumn);
    }

    private function signedMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function calculateStep(FinancialReconciliation $record): int
    {
        $status = strtolower((string) $record->status);
        if ($status === StoreFinanceReconciliationService::STATUS_REJECTED) {
            return 0;
        }
        if ($status === StoreFinanceReconciliationService::STATUS_PAID || $record->paid_at) {
            return 4;
        }
        if ($status === StoreFinanceReconciliationService::STATUS_WAITING_PAYMENT) {
            return 3;
        }
        if ($status === StoreFinanceReconciliationService::STATUS_PENDING_ADMIN) {
            return 2;
        }
        if (!$record->confirmed_at) {
            return 1;
        }
        if ($record->confirmed_at && !$record->paid_at) {
            return 2;
        }
        return 3;
    }

    private function serializeReconciliation(FinancialReconciliation $record): array
    {
        return [
            'id' => (string) $record->id,
            'step' => $this->calculateStep($record),
            'status' => (string) $record->status,
            'period' => (string) $record->period,
            'period_date' => $record->period_date ? $record->period_date->toDateString() : null,
            'revenue_total' => (float) $record->revenue_total,
            'revenue_cash' => (float) $record->revenue_cash,
            'revenue_transfer' => (float) $record->revenue_transfer,
            'revenue_cod' => (float) $record->revenue_cod,
            'affiliate_commission' => (float) $record->affiliate_commission,
            'ticket_reward' => (float) $record->ticket_reward,
            'refund_amount' => (float) ($record->refund_amount ?? 0),
            'cash_collected_amount' => (float) ($record->cash_collected_amount ?? 0),
            'withdrawable_amount' => (float) ($record->withdrawable_amount ?? $record->net_amount ?? 0),
            'approved_withdrawable_amount' => (float) ($record->approved_withdrawable_amount ?? 0),
            'net_amount' => (float) $record->net_amount,
            'confirmed_at' => optional($record->confirmed_at)?->toIso8601String(),
            'paid_at' => optional($record->paid_at)?->toIso8601String(),
            'submitted_source' => $record->submitted_source ?? null,
            'submitted_at' => optional($record->submitted_at)?->toIso8601String(),
            'updated_at' => optional($record->updated_at)?->toIso8601String(),
        ];
    }

    private function broadcastFinanceUpdate(string $storeId, string $period, string $date, string $action, FinancialReconciliation $record): void
    {
        try {
            event(new StoreFinanceReconciliationUpdated(
                $storeId,
                $period,
                $date,
                $action,
                $this->calculateStep($record),
                $this->serializeReconciliation($record),
            ));
        } catch (\Throwable $ex) {
            logger()->warning('Failed to broadcast finance reconciliation update.', [
                'store_id' => $storeId,
                'period' => $period,
                'date' => $date,
                'action' => $action,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    private function withdrawalErrorMessage(string $code): string
    {
        return match (strtoupper($code)) {
            'RECONCILIATION_NOT_FOUND' => 'Chua co ky doi soat hop le de rut tien.',
            'WAITING_SUPER_ADMIN_APPROVAL' => 'Ky doi soat dang cho super admin duyet.',
            'RECONCILIATION_NOT_APPROVED' => 'Ky doi soat chua duoc duyet de rut tien.',
            'DEBT_LOCKED' => 'Vi cua hang dang bi khoa cong no.',
            'ACCOUNT_BALANCE_NEGATIVE' => 'So du kha dung dang am.',
            'TICKET_DEBT_EXISTS' => 'Cua hang can xu ly cong no phi ve truoc khi rut tien.',
            'MISSING_BANK_ACCOUNT' => 'Cua hang chua co thong tin ngan hang.',
            'PENDING_WITHDRAWAL_EXISTS' => 'Dang co yeu cau rut tien cho ky nay.',
            'NON_POSITIVE_WITHDRAWABLE' => 'Khong co so tien kha dung de rut.',
            'INVALID_WITHDRAWAL_AMOUNT' => 'So tien rut khong hop le.',
            'WITHDRAWAL_AMOUNT_EXCEEDS_ALLOWED' => 'So tien rut vuot qua han muc duoc rut.',
            default => 'Khong the tao yeu cau rut tien.',
        };
    }

    private function buildReconciliationPrompt(string $storeId, string $period, Carbon $baseDate): array
    {
        [$from] = $this->reconciliationService->reconciliationPeriodRange($storeId, $period, $baseDate);
        $targetDate = $from->toDateString();
        $currentSubmitted = $this->reconciliationService->findSubmittedForPeriod($storeId, $period, $baseDate);

        if ($currentSubmitted && (string) $currentSubmitted->status !== StoreFinanceReconciliationService::STATUS_REJECTED) {
            return [
                'show' => false,
                'reason' => null,
                'message' => null,
                'action_label' => null,
                'period' => $period,
                'date' => $targetDate,
                'last_reconciliation' => $this->reconciliationService->serialize($currentSubmitted),
            ];
        }

        $currentRecord = FinancialReconciliation::query()
            ->where('store_id', $storeId)
            ->where('period', $period)
            ->whereDate('period_date', $targetDate)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
        $latestSubmitted = $this->reconciliationService->latestSubmittedBefore($storeId, $period, $baseDate);
        $reason = $latestSubmitted ? 'STALE_RECONCILIATION' : 'MISSING_RECONCILIATION';
        $message = $latestSubmitted
            ? 'Ky doi soat gan nhat da cu. Vui long doi soat ngay de cap nhat so tien rut.'
            : 'Chua co ky doi soat hop le. Vui long doi soat ngay de rut tien.';

        if ($currentRecord && (string) $currentRecord->status === StoreFinanceReconciliationService::STATUS_REJECTED) {
            $reason = 'REJECTED_RECONCILIATION';
            $message = 'Ky doi soat hien tai da bi tu choi. Vui long gui doi soat lai.';
        } elseif ($currentRecord && (string) $currentRecord->status === StoreFinanceReconciliationService::STATUS_PENDING_SHOP) {
            $reason = 'DRAFT_RECONCILIATION';
            $message = 'Ky doi soat hien tai dang o ban nhap. Vui long gui doi soat ngay.';
        }

        return [
            'show' => true,
            'reason' => $reason,
            'message' => $message,
            'action_label' => 'Doi soat ngay',
            'period' => $period,
            'date' => $targetDate,
            'last_reconciliation' => $latestSubmitted ? $this->reconciliationService->serialize($latestSubmitted) : null,
        ];
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        bool $hasStore,
        bool $authenticated,
        ?array $details = null
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }

    /**
     * GET /api/store-admin/finance/wallet-logs
     * Lấy lịch sử giao dịch ví của cửa hàng
     */
    public function walletLogs(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_ticket_transactions')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'request_at' => now()->toIso8601String(),
                ],
            ]);
        }

        $validator = Validator::make($request->query(), [
            'type' => ['nullable', 'string', 'in:all,deposit,withdraw,in,out'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Dữ liệu không hợp lệ.', 422, true, true, $validator->errors()->toArray());
        }

        $params = $validator->validated();
        $type = $params['type'] ?? 'all';
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        $query = DB::table('store_ticket_transactions')
            ->where('store_id', $store->id);

        if ($type === 'deposit' || $type === 'in') {
            $query->where(function ($q) {
                $q->whereIn('event_type', ['WALLET_DEPOSIT', 'REVENUE_CREDIT', 'ORDER_CANCELLED_REVERSAL', 'ORDER_REFUND', 'AFFILIATE_COMMISSION_REFUND', 'RETURN_TICKET_REFUND'])
                  ->orWhere('wallet_delta', '>', 0);
            });
        } elseif ($type === 'withdraw' || $type === 'out') {
            $query->where(function ($q) {
                $q->whereIn('event_type', ['DEBT_REPAYMENT', 'ORDER_ACCEPTED', 'AFFILIATE_COMMISSION_DEDUCTION'])
                  ->orWhere('wallet_delta', '<', 0)
                  ->orWhere('debt_delta', '>', 0)
                  ->orWhere('tickets_consumed', '>', 0);
            });
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $pageSize);
        $hasNext = $page < $totalPages;

        $rows = $query->orderBy('timestamp', 'desc')
            ->orderBy('created_at', 'desc')
            ->forPage($page, $pageSize)
            ->get();

        $data = $rows->map(function ($row) {
            $metadata = $row->metadata ? json_decode($row->metadata, true) : [];
            $walletDelta = isset($row->wallet_delta) ? (float) $row->wallet_delta : 0.0;
            $debtDelta = isset($row->debt_delta) ? (float) $row->debt_delta : 0.0;

            return [
                'transaction_id' => $row->transaction_id,
                'order_id' => $row->order_id,
                'product_id' => $row->product_id ?? null,
                'event_type' => $row->event_type,
                'tickets_consumed' => isset($row->tickets_consumed) ? (int) $row->tickets_consumed : 0,
                'unit_price_at_time' => isset($row->unit_price_at_time) ? (float) $row->unit_price_at_time : 0.0,
                'total_debit_amount' => isset($row->total_debit_amount) ? (float) $row->total_debit_amount : 0.0,
                'amount_vnd' => isset($row->amount_vnd) ? (float) $row->amount_vnd : 0.0,
                'wallet_delta' => $walletDelta,
                'debt_delta' => $debtDelta,
                'wallet_balance_after' => isset($row->wallet_balance_after) ? (float) $row->wallet_balance_after : 0.0,
                'ticket_debt_after' => isset($row->ticket_debt_after) ? (float) $row->ticket_debt_after : 0.0,
                'timestamp' => $row->timestamp,
                'metadata' => $metadata,
                'idempotency_key' => $row->idempotency_key ?? null,
                'source_type' => $row->source_type ?? null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $hasNext,
                'request_at' => now()->toIso8601String(),
            ]
        ]);
    }
}
