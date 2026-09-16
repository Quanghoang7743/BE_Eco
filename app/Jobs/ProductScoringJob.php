<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductScoringJob
{
    public function handle(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('stores')) {
            return;
        }

        if (! Schema::hasTable('product_ranking_scores') || ! Schema::hasTable('ranking_configs')) {
            return;
        }

        $weights = $this->resolveRankingWeights('search');

        $commissionMax = (float) DB::table('products as p')
            ->leftJoin('stores as s', 's.id', '=', 'p.store_id')
            ->selectRaw('MAX(GREATEST(COALESCE(p.commission_rate, 0), COALESCE(s.commission_rate, 0))) as max_rate')
            ->value('max_rate');

        $luckyMax = (float) DB::table('products as p')
            ->selectRaw('MAX(CASE WHEN COALESCE(p.ticket_reward_enabled, 0) = 1 THEN COALESCE(p.ticket_reward_quantity, 0) ELSE 0 END) as max_qty')
            ->value('max_qty');

        $salesWindowStart = now()->subDays(90);
        $salesTimestampExpr = $this->resolveOrderItemTimestampExpression('oi', 'o');

        $salesQuery = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id');

        if ($salesTimestampExpr !== null) {
            $salesQuery->whereRaw($salesTimestampExpr . ' >= ?', [$salesWindowStart]);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $salesQuery->whereIn(DB::raw("LOWER(COALESCE(o.status, ''))"), ['delivered', 'completed']);
        }

        $salesRows = $salesQuery
            ->selectRaw('oi.product_id, SUM(COALESCE(oi.quantity, 0)) as sold_qty')
            ->groupBy('oi.product_id')
            ->get();

        $salesMap = $salesRows
            ->pluck('sold_qty', 'product_id')
            ->map(fn ($value) => (float) $value)
            ->all();

        $maxSalesQty = (float) ($salesRows->max('sold_qty') ?? 0);
        $maxSalesLog = $maxSalesQty > 0 ? log($maxSalesQty + 1) : 0.0;

        $ratingMax = (float) DB::table('stores as s')
            ->selectRaw('MAX(COALESCE(s.rating_avg, 0) * COALESCE(s.rating_count, 0)) as max_rating')
            ->value('max_rating');

        $storeStats = $this->loadStoreOrderStats($salesWindowStart);
        $complaintStats = $this->loadStoreComplaintStats($salesWindowStart);

        $now = now();

        DB::table('products as p')
            ->leftJoin('stores as s', 's.id', '=', 'p.store_id')
            ->select([
                'p.id',
                'p.store_id',
                'p.commission_rate as product_commission_rate',
                'p.ticket_reward_quantity',
                'p.ticket_reward_enabled',
                's.commission_rate as store_commission_rate',
                's.rating_avg as store_rating_avg',
                's.rating_count as store_rating_count',
                's.status as store_status',
            ])
            ->orderBy('p.id')
            ->chunkById(300, function ($products) use (
                $weights,
                $commissionMax,
                $luckyMax,
                $salesMap,
                $maxSalesLog,
                $ratingMax,
                $storeStats,
                $complaintStats,
                $now
            ) {
                $scoreRows = [];
                $productScoreRows = [];

                foreach ($products as $product) {
                    $storeId = (string) ($product->store_id ?? '');
                    $commissionRate = max(
                        (float) ($product->product_commission_rate ?? 0),
                        (float) ($product->store_commission_rate ?? 0)
                    );

                    $commissionScore = $this->normalizeScore($commissionRate, $commissionMax);

                    $luckyQty = (int) ($product->ticket_reward_quantity ?? 0);
                    $luckyEnabled = (bool) ($product->ticket_reward_enabled ?? false);
                    $luckyScore = $luckyEnabled ? $this->normalizeScore($luckyQty, $luckyMax) : 0.0;

                    $salesQty = (float) ($salesMap[$product->id] ?? 0);
                    $salesLog = $salesQty > 0 ? log($salesQty + 1) : 0.0;
                    $purchaseScore = $maxSalesLog > 0 ? $this->normalizeScore($salesLog, $maxSalesLog) : 0.0;

                    $ratingValue = (float) ($product->store_rating_avg ?? 0) * (float) ($product->store_rating_count ?? 0);
                    $ratingScore = $this->normalizeScore($ratingValue, $ratingMax);

                    $storeStat = $storeStats[$storeId] ?? ['success' => 0, 'failed' => 0, 'total' => 0];
                    $successRate = $storeStat['total'] > 0
                        ? min(1.0, max(0.0, $storeStat['success'] / $storeStat['total']))
                        : 0.0;

                    $storeStatus = strtolower(trim((string) ($product->store_status ?? '')));
                    $reputationScore = $storeStatus === 'active' ? $successRate * 100 : 0.0;

                    $complaints = (int) ($complaintStats[$storeId]['critical'] ?? 0);
                    $cancelRate = $storeStat['total'] > 0
                        ? min(1.0, max(0.0, $storeStat['failed'] / $storeStat['total']))
                        : 0.0;

                    $riskPenaltyScore = min(100.0, ($complaints * 10.0) + ($cancelRate * 100.0));

                    $baseScore = (
                        $commissionScore * $weights['commission']
                        + $luckyScore * $weights['lucky_ticket']
                        + $purchaseScore * $weights['purchase']
                        + $ratingScore * $weights['rating']
                        + $reputationScore * $weights['reputation']
                        - $riskPenaltyScore * $weights['risk_penalty']
                    );

                    $storeIdValue = $product->store_id;
                    if (! is_scalar($storeIdValue) && $storeIdValue !== null) {
                        $storeIdValue = null;
                    }

                    $payload = [
                        'product_id' => $product->id,
                        'store_id' => $storeIdValue,
                        'base_ranking_score' => round($baseScore, 2),
                        'commission_rate' => round($commissionRate, 2),
                        'commission_score' => round($commissionScore, 2),
                        'lucky_ticket_quantity' => $luckyQty,
                        'lucky_ticket_score' => round($luckyScore, 2),
                        'purchase_qty' => (int) round($salesQty),
                        'purchase_score' => round($purchaseScore, 2),
                        'rating_value' => round($ratingValue, 2),
                        'rating_score' => round($ratingScore, 2),
                        'reputation_score' => round($reputationScore, 2),
                        'risk_penalty_score' => round($riskPenaltyScore, 2),
                        'last_calculated_at' => $now,
                        'meta' => json_encode([
                            'store_status' => $storeStatus,
                            'success_rate' => round($successRate, 4),
                            'cancel_rate' => round($cancelRate, 4),
                            'critical_complaints' => $complaints,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];

                    $scoreRows[] = $payload;
                    $productScoreRows[] = [
                        'id' => $product->id,
                        'base_ranking_score' => $payload['base_ranking_score'],
                        'updated_at' => $now,
                    ];
                }

                if (!empty($scoreRows)) {
                    DB::table('product_ranking_scores')->upsert(
                        $scoreRows,
                        ['product_id'],
                        [
                            'store_id',
                            'base_ranking_score',
                            'commission_rate',
                            'commission_score',
                            'lucky_ticket_quantity',
                            'lucky_ticket_score',
                            'purchase_qty',
                            'purchase_score',
                            'rating_value',
                            'rating_score',
                            'reputation_score',
                            'risk_penalty_score',
                            'last_calculated_at',
                            'meta',
                            'updated_at',
                        ]
                    );
                }

                if (!empty($productScoreRows)) {
                    $this->bulkUpdateProductScores($productScoreRows, $now);
                }
            });
    }

    private function bulkUpdateProductScores(array $rows, mixed $updatedAt): void
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0));
        if (empty($rows)) {
            return;
        }

        $cases = [];
        $bindings = [];
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $row['base_ranking_score'];
            $ids[] = $id;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE products SET base_ranking_score = CASE id '
            . implode(' ', $cases)
            . ' END, updated_at = ? WHERE id IN (' . $placeholders . ')';

        $bindings[] = $updatedAt;
        DB::update($sql, array_merge($bindings, $ids));
    }

    private function resolveRankingWeights(string $screenKey): array
    {
        $defaults = [
            'distance' => 0.20,
            'keyword' => 0.25,
            'lucky_ticket' => 0.08,
            'commission' => 0.05,
            'purchase' => 0.10,
            'rating' => 0.10,
            'history' => 0.07,
            'reputation' => 0.15,
            'risk_penalty' => 0.10,
        ];

        if (! Schema::hasTable('ranking_configs')) {
            return $defaults;
        }

        $row = DB::table('ranking_configs')->where('screen_key', $screenKey)->first();
        if (! $row) {
            return $defaults;
        }

        return [
            'distance' => $this->normalizeWeight($row->weight_distance ?? null, $defaults['distance']),
            'keyword' => $this->normalizeWeight($row->weight_keyword ?? null, $defaults['keyword']),
            'lucky_ticket' => $this->normalizeWeight($row->weight_lucky_ticket ?? null, $defaults['lucky_ticket']),
            'commission' => $this->normalizeWeight($row->weight_commission ?? null, $defaults['commission']),
            'purchase' => $this->normalizeWeight($row->weight_purchase ?? null, $defaults['purchase']),
            'rating' => $this->normalizeWeight($row->weight_rating ?? null, $defaults['rating']),
            'history' => $this->normalizeWeight($row->weight_history ?? null, $defaults['history']),
            'reputation' => $this->normalizeWeight($row->weight_reputation ?? null, $defaults['reputation']),
            'risk_penalty' => $this->normalizeWeight($row->weight_risk_penalty ?? null, $defaults['risk_penalty']),
        ];
    }

    private function normalizeWeight($value, float $fallback): float
    {
        if ($value === null) {
            return $fallback;
        }

        $numeric = (float) $value;

        if ($numeric > 1.0) {
            return $numeric / 100.0;
        }

        if ($numeric < 0) {
            return $fallback;
        }

        return $numeric;
    }

    private function normalizeScore(float $value, float $max): float
    {
        if ($max <= 0) {
            return 0.0;
        }

        return min(100.0, max(0.0, ($value / $max) * 100.0));
    }

    private function resolveOrderItemTimestampExpression(string $orderItemAlias, string $orderAlias): ?string
    {
        $orderItemCreated = Schema::hasColumn('order_items', 'created_at');
        $orderCreated = Schema::hasColumn('orders', 'created_at');

        if ($orderItemCreated && $orderCreated) {
            return 'COALESCE(' . $orderItemAlias . '.created_at, ' . $orderAlias . '.created_at)';
        }

        if ($orderItemCreated) {
            return $orderItemAlias . '.created_at';
        }

        if ($orderCreated) {
            return $orderAlias . '.created_at';
        }

        return null;
    }

    private function loadStoreOrderStats($since): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return [];
        }

        $query = DB::table('orders as o');

        if (Schema::hasColumn('orders', 'created_at')) {
            $query->where('o.created_at', '>=', $since);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $statusExpr = "LOWER(COALESCE(o.status, ''))";
            $query->selectRaw('o.store_id')
                ->selectRaw('SUM(CASE WHEN ' . $statusExpr . " IN ('delivered','completed') THEN 1 ELSE 0 END) as success")
                ->selectRaw('SUM(CASE WHEN ' . $statusExpr . " IN ('cancelled','canceled','returned','refunded') THEN 1 ELSE 0 END) as failed")
                ->selectRaw('COUNT(*) as total')
                ->groupBy('o.store_id');
        } else {
            $query->selectRaw('o.store_id, 0 as success, 0 as failed, COUNT(*) as total')
                ->groupBy('o.store_id');
        }

        $rows = $query->get();
        $stats = [];

        foreach ($rows as $row) {
            $storeId = (string) ($row->store_id ?? '');
            if ($storeId === '') {
                continue;
            }

            $stats[$storeId] = [
                'success' => (float) ($row->success ?? 0),
                'failed' => (float) ($row->failed ?? 0),
                'total' => (float) ($row->total ?? 0),
            ];
        }

        return $stats;
    }

    private function loadStoreComplaintStats($since): array
    {
        if (! Schema::hasTable('complaints') || ! Schema::hasColumn('complaints', 'store_id')) {
            return [];
        }

        $query = DB::table('complaints as c');

        if (Schema::hasColumn('complaints', 'created_at')) {
            $query->where('c.created_at', '>=', $since);
        }

        $severityExpr = "LOWER(COALESCE(c.severity, ''))";

        $rows = $query
            ->selectRaw('c.store_id')
            ->selectRaw('SUM(CASE WHEN ' . $severityExpr . " = 'critical' THEN 1 ELSE 0 END) as critical")
            ->groupBy('c.store_id')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $storeId = (string) ($row->store_id ?? '');
            if ($storeId === '') {
                continue;
            }

            $stats[$storeId] = [
                'critical' => (int) ($row->critical ?? 0),
            ];
        }

        return $stats;
    }
}
