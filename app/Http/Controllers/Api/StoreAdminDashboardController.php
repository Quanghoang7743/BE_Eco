<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class StoreAdminDashboardController extends Controller
{
    public function __construct(private readonly StoreAdminStaffAccess $access)
    {
    }

    public function reportsSummary(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        /** @var Store|null $store */
        $store = $context['store'];

        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'from' => null,
                    'to' => null,
                ],
                'errors' => [
                    [
                        'code' => 'STORE_NOT_FOUND',
                        'message' => 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.',
                    ],
                ],
            ], 404);
        }

        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'from' => null,
                    'to' => null,
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Du lieu dau vao khong hop le.',
                        'details' => $validator->errors()->toArray(),
                    ],
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $to = $validated['to'] ?? now()->toDateString();
        $from = $validated['from'] ?? now()->subDays(6)->toDateString();

        // ========== REVENUE CALCULATIONS ==========
        $revenueTotal = 0;
        $revenueOrdersCount = 0;
        $ticketRedemptionsTotal = 0;

        if (Schema::hasTable('orders')) {
            $revenueQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $revenueQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
            }
            $this->applyDateRange($revenueQuery, $from, $to, ['delivered_at', 'created_at']);

            if (Schema::hasColumn('orders', 'final_amount')) {
                $revenueTotal = (int) round((float) ($revenueQuery->sum('final_amount') ?? 0));
            } elseif (Schema::hasColumn('orders', 'total_amount')) {
                $revenueTotal = (int) round((float) ($revenueQuery->sum('total_amount') ?? 0));
            } elseif (Schema::hasColumn('orders', 'total')) {
                $revenueTotal = (int) round((float) ($revenueQuery->sum('total') ?? 0));
            }

            $ordersQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $ordersQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
            }
            $this->applyDateRange($ordersQuery, $from, $to, ['delivered_at', 'created_at']);
            $revenueOrdersCount = (int) $ordersQuery->count();

            $ticketsQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $ticketsQuery->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), $this->completedOrderStatuses());
            }
            $this->applyDateRange($ticketsQuery, $from, $to, ['delivered_at', 'created_at']);
            if (Schema::hasColumn('orders', 'total_tickets_used')) {
                $ticketRedemptionsTotal = (int) ($ticketsQuery->sum('total_tickets_used') ?? 0);
            }
        }

        // ========== INVENTORY PULSE ==========
        $inventoryUnavailable = 0;
        $inventoryTotal = 0;

        if (Schema::hasTable('store_menu')) {
            $inventoryBase = DB::table('store_menu')->where('store_id', $store->id);
            $inventoryTotal = (int) (clone $inventoryBase)->count();

            if (Schema::hasColumn('store_menu', 'is_available')) {
                $inventoryUnavailable = (int) (clone $inventoryBase)->where('is_available', false)->count();
            }
        }

        // ========== ATTENTION ITEMS ==========
        $pendingOrders = 0;
        $pendingComplaints = 0;
        $slaWarnings = 0;

        if (Schema::hasTable('orders')) {
            $pendingOrdersQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $pendingOrdersQuery->whereRaw('LOWER(status) = ?', ['pending']);
            }
            $pendingOrders = (int) $pendingOrdersQuery->count();

            $slaWarningsQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'sla_warning_sent')) {
                $slaWarnings = (int) $slaWarningsQuery->where('sla_warning_sent', true)->count();
            }
        }

        if (Schema::hasTable('complaints')) {
            $complaintQuery = DB::table('complaints')->where('store_id', $store->id);
            if (Schema::hasColumn('complaints', 'status')) {
                $complaintQuery->whereRaw('LOWER(status) = ?', ['pending']);
            }
            $pendingComplaints = (int) $complaintQuery->count();
        }

        // ========== APPROVAL STATUS ==========
        $approvalChangedAt = $store->approved_at ?? $store->updated_at ?? $store->created_at;
        $approvalStatus = (string) ($store->status ?? 'pending');
        $latestReason = $approvalStatus === 'rejected' ? ($store->rejection_reason ?: null) : null;

        return response()->json([
            'data' => [
                'revenue' => [
                    'total' => $revenueTotal,
                    'orders_count' => $revenueOrdersCount,
                    'currency' => 'VND',
                ],
                'ticket_redemptions' => [
                    'total' => $ticketRedemptionsTotal,
                ],
                'inventory_pulse' => [
                    'unavailable_items' => $inventoryUnavailable,
                    'total_items' => $inventoryTotal,
                    'availability_rate_percent' => $inventoryTotal > 0
                        ? round((($inventoryTotal - $inventoryUnavailable) / $inventoryTotal) * 100, 2)
                        : 0.0,
                ],
                'attention_items' => [
                    'pending_orders' => $pendingOrders,
                    'pending_complaints' => $pendingComplaints,
                    'sla_warnings' => $slaWarnings,
                ],
                'approval' => [
                    'current_status' => $approvalStatus,
                    'last_changed_at' => $approvalChangedAt ? $approvalChangedAt->toIso8601String() : null,
                    'latest_reason' => $latestReason,
                ],
            ],
            'meta' => [
                'from' => $from,
                'to' => $to,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $store = $context['store'];

        if (!$store) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => false,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'STORE_NOT_FOUND',
                        'message' => 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.',
                    ],
                ],
            ], 404);
        }

        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Du lieu dau vao khong hop le.',
                        'details' => $validator->errors()->toArray(),
                    ],
                ],
            ], 422);
        }

        $filters = $validator->validated();
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $deliveredRevenue = 0.0;
        $ticketRedemption = 0;
        $successfulOrders = 0;
        $cancelledOrders = 0;
        $totalOrdersInRange = 0;

        if (Schema::hasTable('orders')) {
            $deliveredStatuses = $this->completedOrderStatuses();
            $cancelledStatuses = ['cancelled', 'canceled'];

            $revenueQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $revenueQuery->whereIn(DB::raw('LOWER(status)'), $deliveredStatuses);
            }
            $this->applyDateRange($revenueQuery, $from, $to, ['delivered_at', 'created_at']);

            if (Schema::hasColumn('orders', 'final_amount')) {
                $deliveredRevenue = (float) ($revenueQuery->sum('final_amount') ?? 0);
            } elseif (Schema::hasColumn('orders', 'total_amount')) {
                $deliveredRevenue = (float) ($revenueQuery->sum('total_amount') ?? 0);
            } elseif (Schema::hasColumn('orders', 'total')) {
                $deliveredRevenue = (float) ($revenueQuery->sum('total') ?? 0);
            }

            $ticketsQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $ticketsQuery->whereIn(DB::raw('LOWER(status)'), $deliveredStatuses);
            }
            $this->applyDateRange($ticketsQuery, $from, $to, ['delivered_at', 'created_at']);

            if (Schema::hasColumn('orders', 'total_tickets_used')) {
                $ticketRedemption = (int) ($ticketsQuery->sum('total_tickets_used') ?? 0);
            }

            $successfulOrdersQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $successfulOrdersQuery->whereIn(DB::raw('LOWER(status)'), $deliveredStatuses);
            }
            $this->applyDateRange($successfulOrdersQuery, $from, $to, ['delivered_at', 'created_at']);
            $successfulOrders = (int) $successfulOrdersQuery->count();

            $cancelledOrdersQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $cancelledOrdersQuery->whereIn(DB::raw('LOWER(status)'), $cancelledStatuses);
            }
            $this->applyDateRange($cancelledOrdersQuery, $from, $to, ['cancelled_at', 'created_at']);
            $cancelledOrders = (int) $cancelledOrdersQuery->count();

            $totalOrdersQuery = DB::table('orders')->where('store_id', $store->id);
            $this->applyDateRange($totalOrdersQuery, $from, $to, ['created_at']);
            $totalOrdersInRange = (int) $totalOrdersQuery->count();
        }

        $cancelRatePercent = $totalOrdersInRange > 0
            ? round(($cancelledOrders / $totalOrdersInRange) * 100, 2)
            : 0.0;

        $inventoryUnavailable = 0;
        $inventoryTotal = 0;

        if (Schema::hasTable('store_menu')) {
            $inventoryBase = DB::table('store_menu')->where('store_id', $store->id);
            $inventoryTotal = (int) (clone $inventoryBase)->count();

            if (Schema::hasColumn('store_menu', 'is_available')) {
                $inventoryUnavailable = (int) (clone $inventoryBase)
                    ->where('is_available', false)
                    ->count();
            }
        }

        $availabilityRatePercent = $inventoryTotal > 0
            ? round((($inventoryTotal - $inventoryUnavailable) / $inventoryTotal) * 100, 2)
            : 0.0;

        $pendingOrders = 0;
        $pendingComplaints = 0;
        $slaWarnings = 0;

        if (Schema::hasTable('orders')) {
            $pendingOrdersQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'status')) {
                $pendingOrdersQuery->where('status', 'pending');
            }
            $pendingOrders = (int) $pendingOrdersQuery->count();

            $slaWarningsQuery = DB::table('orders')->where('store_id', $store->id);
            if (Schema::hasColumn('orders', 'sla_warning_sent')) {
                $slaWarnings = (int) $slaWarningsQuery->where('sla_warning_sent', true)->count();
            }
        }

        if (Schema::hasTable('complaints')) {
            $pendingComplaintsQuery = DB::table('complaints')->where('store_id', $store->id);
            if (Schema::hasColumn('complaints', 'status')) {
                $pendingComplaintsQuery->where('status', 'pending');
            }
            $pendingComplaints = (int) $pendingComplaintsQuery->count();
        }

        $ticketValueVnd = $ticketRedemption * 5000;

        return response()->json([
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'store_code' => $store->store_code,
                    'status' => $store->status,
                    'is_open' => (bool) $store->is_open,
                ],
                'period' => [
                    'from' => $from,
                    'to' => $to,
                ],
                'revenue' => [
                    'delivered_final_amount' => round($deliveredRevenue, 2),
                    'currency' => 'VND',
                ],
                'ticket_redemption' => [
                    'total_tickets_used' => $ticketRedemption,
                    'estimated_value_vnd' => $ticketValueVnd,
                ],
                'quick_stats' => [
                    'successful_orders' => $successfulOrders,
                    'cancelled_orders' => $cancelledOrders,
                    'total_orders' => $totalOrdersInRange,
                    'cancel_rate_percent' => $cancelRatePercent,
                ],
                'inventory_pulse' => [
                    'unavailable_items' => $inventoryUnavailable,
                    'total_items' => $inventoryTotal,
                    'availability_rate_percent' => $availabilityRatePercent,
                ],
                'attention_items' => [
                    'pending_orders' => $pendingOrders,
                    'pending_complaints' => $pendingComplaints,
                    'sla_warnings' => $slaWarnings,
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

    private function applyDateRange(Builder $query, ?string $from, ?string $to, array $preferredColumns): void
    {
        $column = null;
        foreach ($preferredColumns as $candidate) {
            if (Schema::hasColumn('orders', $candidate)) {
                $column = $candidate;
                break;
            }
        }

        if (!$column) {
            return;
        }

        if ($from) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }

    private function resolveContext(Request $request): array
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);

        return [
            'user' => $user,
            'store' => $store,
            'error' => $error,
        ];
    }

    private function completedOrderStatuses(): array
    {
        return ['delivered', 'completed', 'call_completed'];
    }
}
