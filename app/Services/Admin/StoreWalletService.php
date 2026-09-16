<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StoreWalletService
{
    /**
     * Danh sách ví cửa hàng, có filter và phân trang.
     */
    public function getWalletList(array $filters): LengthAwarePaginator
    {
        $query = Store::query()
            ->select([
                'id',
                'store_code',
                'name',
                'owner_id',
                'phone',
                'email',
                'wallet_balance',
                'ticket_debt',
                'credit_limit',
                'credit_level',
                'credit_score',
                'is_debt_locked',
                'debt_locked_at',
                'debt_lock_reason',
                'status',
                'is_open',
                'updated_at',
            ]);

        // --- Keyword search ---
        if (!empty($filters['keyword'])) {
            $kw = '%' . $filters['keyword'] . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', $kw)
                  ->orWhere('store_code', 'like', $kw)
                  ->orWhere('phone', 'like', $kw);
            });
        }

        // --- is_debt_locked filter (0 / 1) ---
        if (isset($filters['is_debt_locked']) && $filters['is_debt_locked'] !== '') {
            $query->where('is_debt_locked', (bool) $filters['is_debt_locked']);
        }

        // --- credit_level filter ---
        if (!empty($filters['credit_level'])) {
            $query->where('credit_level', (int) $filters['credit_level']);
        }

        // --- status filter ---
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // --- wallet_balance range ---
        if (isset($filters['min_wallet_balance']) && $filters['min_wallet_balance'] !== '') {
            $query->where('wallet_balance', '>=', (float) $filters['min_wallet_balance']);
        }
        if (isset($filters['max_wallet_balance']) && $filters['max_wallet_balance'] !== '') {
            $query->where('wallet_balance', '<=', (float) $filters['max_wallet_balance']);
        }

        // --- ticket_debt range ---
        if (isset($filters['min_ticket_debt']) && $filters['min_ticket_debt'] !== '') {
            $query->where('ticket_debt', '>=', (float) $filters['min_ticket_debt']);
        }
        if (isset($filters['max_ticket_debt']) && $filters['max_ticket_debt'] !== '') {
            $query->where('ticket_debt', '<=', (float) $filters['max_ticket_debt']);
        }

        // --- Sorting ---
        $allowedSortColumns = [
            'wallet_balance',
            'ticket_debt',
            'credit_level',
            'credit_score',
            'name',
            'store_code',
            'updated_at',
            'created_at',
        ];
        $sortBy        = in_array($filters['sort_by'] ?? '', $allowedSortColumns, true)
            ? $filters['sort_by']
            : 'wallet_balance';
        $sortDirection = strtolower($filters['sort_direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDirection);

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    /**
     * Chi tiết ví một cửa hàng, kèm 10 giao dịch gần nhất.
     */
    public function getWalletDetail(string $storeId): Store
    {
        $store = Store::query()
            ->select([
                'id',
                'store_code',
                'name',
                'owner_id',
                'phone',
                'email',
                'wallet_balance',
                'ticket_debt',
                'credit_limit',
                'credit_level',
                'credit_score',
                'is_debt_locked',
                'debt_locked_at',
                'debt_lock_reason',
                'status',
                'is_open',
                'created_at',
                'updated_at',
            ])
            ->findOrFail($storeId);

        // Load 10 giao dịch gần nhất từ bảng store_ticket_transactions
        $recent = DB::table('store_ticket_transactions')
            ->where('store_id', $storeId)
            ->where('wallet_delta', '!=', 0)
            ->orderByDesc('timestamp')
            ->limit(10)
            ->get();

        $store->setRelation('recentTransactions', $recent);

        return $store;
    }

    /**
     * Lịch sử nạp/rút ví, có filter và phân trang.
     */
    public function getTransactions(string $storeId, array $filters): LengthAwarePaginator
    {
        // Đảm bảo store tồn tại
        Store::findOrFail($storeId);

        $query = DB::table('store_ticket_transactions')
            ->where('store_id', $storeId)
            ->where('wallet_delta', '!=', 0);

        // --- type filter ---
        if (!empty($filters['type'])) {
            if ($filters['type'] === 'topup') {
                $query->where('wallet_delta', '>', 0);
            } elseif ($filters['type'] === 'withdraw') {
                $query->where('wallet_delta', '<', 0);
            } else {
                $query->where('event_type', $filters['type']);
            }
        }

        // --- date range ---
        if (!empty($filters['from_date'])) {
            $query->whereDate('timestamp', '>=', $filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $query->whereDate('timestamp', '<=', $filters['to_date']);
        }

        // --- keyword ---
        if (!empty($filters['keyword'])) {
            $kw = '%' . $filters['keyword'] . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('metadata', 'like', $kw)
                  ->orWhere('event_type', 'like', $kw)
                  ->orWhere('source_id', 'like', $kw);
            });
        }

        $query->orderByDesc('timestamp');

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    /**
     * Thống kê tổng quan ví toàn hệ thống.
     */
    public function getSummary(): array
    {
        $row = Store::query()->select([
            DB::raw('COUNT(*) as total_stores'),
            DB::raw('COALESCE(SUM(wallet_balance), 0) as total_wallet_balance'),
            DB::raw('COALESCE(SUM(ticket_debt), 0) as total_ticket_debt'),
            DB::raw('SUM(CASE WHEN is_debt_locked = 1 THEN 1 ELSE 0 END) as locked_store_count'),
            DB::raw('SUM(CASE WHEN is_debt_locked = 0 THEN 1 ELSE 0 END) as unlocked_store_count'),
        ])->first();

        $levelSummary = Store::query()
            ->select('credit_level', DB::raw('COUNT(*) as count'))
            ->groupBy('credit_level')
            ->orderBy('credit_level')
            ->get()
            ->map(fn ($item) => [
                'credit_level' => (int) $item->credit_level,
                'count'        => (int) $item->count,
            ])
            ->values()
            ->all();

        return [
            'total_stores'          => (int) ($row->total_stores ?? 0),
            'total_wallet_balance'  => number_format((float) ($row->total_wallet_balance ?? 0), 2, '.', ''),
            'total_ticket_debt'     => number_format((float) ($row->total_ticket_debt ?? 0), 2, '.', ''),
            'locked_store_count'    => (int) ($row->locked_store_count ?? 0),
            'unlocked_store_count'  => (int) ($row->unlocked_store_count ?? 0),
            'level_summary'         => $levelSummary,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Tính tổng nạp vào (wallet_delta > 0) và tổng chi ra (abs(wallet_delta) cho wallet_delta < 0) của cửa hàng.
     */
    public function getWalletStats(string $storeId): array
    {
        $stats = DB::table('store_ticket_transactions')
            ->where('store_id', $storeId)
            ->selectRaw('COALESCE(SUM(CASE WHEN wallet_delta > 0 THEN wallet_delta ELSE 0 END), 0) as total_deposit')
            ->selectRaw('COALESCE(SUM(CASE WHEN wallet_delta < 0 THEN ABS(wallet_delta) ELSE 0 END), 0) as total_spent')
            ->first();

        return [
            'total_deposit' => (float)$stats->total_deposit,
            'total_spent'   => (float)$stats->total_spent,
        ];
    }

    /**
     * Nhãn cho loại sự kiện giao dịch.
     */
    public static function getEventTypeLabel(string $eventType): string
    {
        $labels = [
            'WALLET_DEPOSIT'           => 'Nạp tiền vào ví',
            'DEBT_REPAYMENT'           => 'Khấu trừ trả nợ ticket',
            'ORDER_ACCEPTED'           => 'Thanh toán phí ticket đơn hàng',
            'ORDER_CANCELLED_REVERSAL' => 'Hoàn phí hủy đơn',
            'ORDER_DELIVERED'          => 'Khấu trừ phí ticket đơn hàng',
            'ORDER_REFUND'             => 'Hoàn trả đơn hàng',
        ];
        return $labels[$eventType] ?? $eventType;
    }

    /**
     * Format một store thành mảng response cho danh sách / chi tiết.
     */
    public function formatStoreWallet(Store $store): array
    {
        $creditLimit   = (float) ($store->credit_limit ?? 0);
        $ticketDebt    = (float) ($store->ticket_debt ?? 0);
        $isDebtLocked  = (bool) $store->is_debt_locked;

        return [
            'store_id'          => $store->id,
            'store_code'        => $store->store_code,
            'store_name'        => $store->name,
            'owner_id'          => $store->owner_id,
            'phone'             => $store->phone,
            'email'             => $store->email,
            'wallet_balance'    => number_format((float) ($store->wallet_balance ?? 0), 2, '.', ''),
            'ticket_debt'       => number_format($ticketDebt, 2, '.', ''),
            'credit_limit'      => number_format($creditLimit, 2, '.', ''),
            'available_credit'  => number_format(max(0, $creditLimit - $ticketDebt), 2, '.', ''),
            'credit_level'      => (int) ($store->credit_level ?? 1),
            'credit_score'      => (int) ($store->credit_score ?? 0),
            'is_debt_locked'    => $isDebtLocked,
            'debt_status_label' => $isDebtLocked ? 'Đang khóa' : 'Đang mở',
            'debt_locked_at'    => $store->debt_locked_at?->toIso8601String(),
            'debt_lock_reason'  => $store->debt_lock_reason,
            'status'            => $store->status,
            'is_open'           => (bool) $store->is_open,
            'updated_at'        => $store->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Format một transaction thành mảng response.
     */
    public function formatTransaction(object $tx): array
    {
        $walletDelta = (float)($tx->wallet_delta ?? 0);
        $type = $walletDelta > 0 ? 'topup' : 'withdraw';

        // Trích xuất lý do/ghi chú từ metadata hoặc source_type
        $note = '';
        if (!empty($tx->metadata)) {
            $meta = json_decode($tx->metadata, true);
            if (is_array($meta)) {
                if (isset($meta['order_code'])) {
                    $note = "Đơn hàng: " . $meta['order_code'];
                } elseif (isset($meta['reason'])) {
                    $reason = $meta['reason'];
                    if ($reason === 'store_admin_cancelled') {
                        $reason = 'Khách/Cửa hàng hủy đơn';
                    }
                    $note = "Lý do: " . $reason;
                }
            }
        }
        if (empty($note) && !empty($tx->source_type)) {
            $note = "Nguồn: " . $tx->source_type;
        }

        return [
            'id'                     => $tx->transaction_id,
            'store_id'               => $tx->store_id,
            'type'                   => $type,
            'type_label'             => static::getEventTypeLabel($tx->event_type),
            'amount'                 => number_format(abs($walletDelta), 2, '.', ''),
            'wallet_balance_before'  => number_format((float)($tx->wallet_balance_after ?? 0) - $walletDelta, 2, '.', ''),
            'wallet_balance_after'   => number_format((float)($tx->wallet_balance_after ?? 0), 2, '.', ''),
            'note'                   => $note ?: $tx->event_type,
            'reference_code'         => $tx->source_id ?? $tx->order_id ?? '',
            'created_by'             => null,
            'created_at'             => !empty($tx->timestamp) ? \Carbon\Carbon::parse($tx->timestamp)->toIso8601String() : null,
        ];
    }
}
