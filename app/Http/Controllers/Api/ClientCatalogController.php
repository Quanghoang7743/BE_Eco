<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\appSetting as AppSetting;
use App\Models\Room;
use App\Models\User;
use App\Models\Store;
use App\Models\StoreMenu;
use App\Models\StoreReview;
use App\Models\UserArticleInteraction;
use App\Services\FlashSaleService;
use App\Services\TicketPricingService;
use App\Support\StoreMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClientCatalogController extends ClientController
{
    private const FLASH_SALE_TIMEZONE = 'Asia/Ho_Chi_Minh';
    private const HOMEPAGE_NEWS_LIMIT = 5;

    private ?TicketPricingService $ticketPricingService = null;
    private array $popularProductsCache = [];
    private array $reviewBaselineCache = [];
    private ?int $favoriteProductUserId = null;
    private array $favoriteProductIdLookup = [];
    private array $articleInteractionCountCache = [];
    private array $articleInteractionUserCache = [];
    private ?AppSetting $appSettingInstance = null;

    private function getAppSettings(): ?AppSetting 
    {
        if ($this->appSettingInstance === null) {
            $this->appSettingInstance = cache()->remember('app_settings', 3600, function () {
                return AppSetting::first();
            });
        }
        return $this->appSettingInstance;
    }

    private function primeArticleInteractions(iterable $articles, ?int $userId = null): void
    {
        if (! Schema::hasTable('user_article_interactions')) {
            return;
        }

        $articleIds = [];
        foreach ($articles as $item) {
            if ($item instanceof \App\Models\article) {
                $id = (int) $item->id;
            } elseif (is_array($item)) {
                $id = (int) ($item['id'] ?? 0);
            } elseif (is_object($item)) {
                $id = (int) ($item->id ?? 0);
            } else {
                $id = (int) $item;
            }
            if ($id > 0) {
                $articleIds[] = $id;
            }
        }
        $articleIds = array_values(array_unique($articleIds));

        if (empty($articleIds)) {
            return;
        }

        // Fetch counts for is_liked
        $likeCounts = DB::table('user_article_interactions')
            ->select('article_id', DB::raw('count(*) as count'))
            ->whereIn('article_id', $articleIds)
            ->where('is_liked', true)
            ->groupBy('article_id')
            ->pluck('count', 'article_id')
            ->all();

        // Fetch counts for is_saved
        $saveCounts = DB::table('user_article_interactions')
            ->select('article_id', DB::raw('count(*) as count'))
            ->whereIn('article_id', $articleIds)
            ->where('is_saved', true)
            ->groupBy('article_id')
            ->pluck('count', 'article_id')
            ->all();

        foreach ($articleIds as $id) {
            $this->articleInteractionCountCache[$id . ':is_liked'] = (int) ($likeCounts[$id] ?? 0);
            $this->articleInteractionCountCache[$id . ':is_saved'] = (int) ($saveCounts[$id] ?? 0);
        }

        // Fetch user interactions in bulk
        if ($userId) {
            $userInteractions = UserArticleInteraction::query()
                ->where('user_id', $userId)
                ->whereIn('article_id', $articleIds)
                ->get()
                ->keyBy('article_id');

            foreach ($articleIds as $id) {
                $this->articleInteractionUserCache[$userId . ':' . $id] = $userInteractions->get($id);
            }
        }
    }

    private function ticketPricingService(): TicketPricingService
    {
        if ($this->ticketPricingService === null) {
            $this->ticketPricingService = app(TicketPricingService::class);
        }

        return $this->ticketPricingService;
    }

    private function applyPublicStoreDebtVisibility($query, string $storeAlias = 'stores')
    {
        return $this->applyPublicStoreVisibilityScope($query, $storeAlias);
    }

    private function isStorePubliclyVisible(?Store $store): bool
    {
        return $store ? $this->storeIsPubliclyVisible($store) : false;
    }

    private function buildStoreMediaPayload(mixed $logoUrl = null, mixed $coverImage = null, mixed $bannerUrl = null, mixed $bannerUrls = null): array
    {
        $logo = $this->normalizeStoreMediaUrl($logoUrl);
        $cover = $this->normalizeStoreMediaUrl($coverImage) ?? $logo;
        $bannerList = $this->normalizeStoreMediaUrlList($bannerUrls, $bannerUrl);
        $banner = $bannerList[0] ?? $this->normalizeStoreMediaUrl($bannerUrl);

        return [
            'image' => $logo,
            'logo_url' => $logo,
            'logoUrl' => $logo,
            'avatar' => $logo,
            'avatar_url' => $logo,
            'avatarUrl' => $logo,
            'cover_image' => $cover,
            'cover_image_url' => $cover,
            'coverImage' => $cover,
            'coverImageUrl' => $cover,
            'banner_url' => $banner,
            'bannerUrl' => $banner,
            'banner_urls' => $bannerList,
            'bannerUrls' => $bannerList,
        ];
    }

    private function normalizeStoreMediaUrlList(mixed $value, mixed $fallback = null): array
    {
        $items = [];

        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $items = is_array($decoded) ? $decoded : [$value];
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            $items[] = $fallback;
        }

        $urls = [];
        foreach ($items as $item) {
            $url = $this->normalizeStoreMediaUrl($item);
            if (! $url) {
                continue;
            }
            $urls[$url] = $url;
        }

        return array_slice(array_values($urls), 0, 10);
    }

    private function countStoreFollowers(string $storeId): int
    {
        if (
            ! Schema::hasTable('user_store_preferences')
            || ! Schema::hasColumn('user_store_preferences', 'store_id')
            || ! Schema::hasColumn('user_store_preferences', 'is_following')
        ) {
            return 0;
        }

        return (int) DB::table('user_store_preferences')
            ->where('store_id', $storeId)
            ->where('is_following', true)
            ->count();
    }

    private function resolvePopularSalesWindows(): array
    {
        $timezone = 'Asia/Ho_Chi_Minh';
        $now = now($timezone);

        return [
            'timezone' => $timezone,
            'now' => $now,
            'last_6h_start' => $now->copy()->subHours(6),
            'last_24h_start' => $now->copy()->subHours(24),
            'last_7d_start' => $now->copy()->subDays(7),
        ];
    }

    private function serializePopularSalesWindows(array $windows): array
    {
        return [
            'timezone' => (string) ($windows['timezone'] ?? 'Asia/Ho_Chi_Minh'),
            'current_at' => ($windows['now'] ?? now('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s'),
            'last_6h_start_at' => $windows['last_6h_start']->format('Y-m-d H:i:s'),
            'last_24h_start_at' => $windows['last_24h_start']->format('Y-m-d H:i:s'),
            'last_7d_start_at' => $windows['last_7d_start']->format('Y-m-d H:i:s'),
        ];
    }

    private function resolvePopularityScoreWeights(): array
    {
        return [
            'velocity_0_6h' => 1.0,
            'velocity_6_24h' => 0.5,
            'velocity_24h_7d' => 0.15,
            'cvr_multiplier' => 30.0,
            'cancel_penalty' => 15.0,
            'refund_penalty' => 40.0,
            'rating_floor' => 3.0,
            'rating_bonus_scale' => 5.0,
        ];
    }

    private function resolveReviewScoreConfig(): array
    {
        $halfLifeDays = 90.0;

        return [
            'min_reviews' => 10,
            'half_life_days' => $halfLifeDays,
            'decay_mu' => log(2) / $halfLifeDays,
            'default_global_average' => 4.0,
        ];
    }

    private function resolveCatalogOrderItemTimestampExpression(): ?string
    {
        $hasOrderItemsCreatedAt = Schema::hasColumn('order_items', 'created_at');
        $hasOrdersTable = Schema::hasTable('orders');
        $hasOrdersCreatedAt = $hasOrdersTable && Schema::hasColumn('orders', 'created_at');

        if ($hasOrderItemsCreatedAt && $hasOrdersCreatedAt) {
            return 'COALESCE(order_items.created_at, orders.created_at)';
        }

        if ($hasOrderItemsCreatedAt) {
            return 'order_items.created_at';
        }

        if ($hasOrdersCreatedAt) {
            return 'orders.created_at';
        }

        return null;
    }

    private function resolveCatalogOrderStatusExpression(): ?string
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'status')) {
            return null;
        }

        return 'orders.status';
    }

    private function resolveGlobalReviewBaseline(string $scopeColumn): float
    {
        if (array_key_exists($scopeColumn, $this->reviewBaselineCache)) {
            return $this->reviewBaselineCache[$scopeColumn];
        }

        $cacheKey = 'global_review_baseline:' . $scopeColumn;

        $val = cache()->remember($cacheKey, 3600, function () use ($scopeColumn) {
            $config = $this->resolveReviewScoreConfig();
            $fallback = (float) $config['default_global_average'];

            if (
                ! Schema::hasTable('store_reviews')
                || ! Schema::hasColumn('store_reviews', $scopeColumn)
                || ! Schema::hasColumn('store_reviews', 'rating')
            ) {
                return $fallback;
            }

            $query = DB::table('store_reviews')
                ->whereNotNull($scopeColumn)
                ->whereNotNull('rating');

            if (Schema::hasColumn('store_reviews', 'is_hidden')) {
                $query->where('is_hidden', false);
            }

            if (Schema::hasColumn('store_reviews', 'created_at')) {
                $decayMu = round((float) $config['decay_mu'], 12);
                $weightExpression = 'EXP(-' . $decayMu . ' * GREATEST(TIMESTAMPDIFF(DAY, created_at, NOW()), 0))';
                $row = $query
                    ->selectRaw('SUM(rating * ' . $weightExpression . ') as weighted_sum')
                    ->selectRaw('SUM(' . $weightExpression . ') as weighted_count')
                    ->first();

                $weightedCount = (float) ($row->weighted_count ?? 0);
                if ($weightedCount > 0) {
                    return (float) ($row->weighted_sum / $weightedCount);
                }

                return $fallback;
            }

            return (float) ($query->avg('rating') ?? $fallback);
        });

        return $this->reviewBaselineCache[$scopeColumn] = $val;
    }

    private function primeFavoriteProductLookup(Request $request, iterable $productsOrIds): void
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        $userId = $user ? (int) $user->id : null;

        if ($this->favoriteProductUserId !== $userId) {
            $this->favoriteProductUserId = $userId;
            $this->favoriteProductIdLookup = [];
        }

        if (! $userId || ! Schema::hasTable('user_product_favorites')) {
            return;
        }

        $productIds = collect($productsOrIds)
            ->map(function ($item): int {
                if ($item instanceof Product) {
                    return (int) ($item->id ?? 0);
                }

                if (is_array($item)) {
                    return (int) ($item['id'] ?? $item['product_id'] ?? 0);
                }

                if (is_object($item)) {
                    return (int) ($item->id ?? $item->product_id ?? 0);
                }

                return (int) $item;
            })
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $missingProductIds = $productIds
            ->reject(fn (int $productId): bool => array_key_exists($productId, $this->favoriteProductIdLookup))
            ->values();

        if ($missingProductIds->isEmpty()) {
            return;
        }

        $favoriteIds = DB::table('user_product_favorites')
            ->where('user_id', $userId)
            ->whereIn('product_id', $missingProductIds->all())
            ->pluck('product_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        foreach ($missingProductIds as $productId) {
            $this->favoriteProductIdLookup[(int) $productId] = false;
        }

        foreach ($favoriteIds as $productId) {
            $this->favoriteProductIdLookup[(int) $productId] = true;
        }
    }

    private function applyFavoriteStateToCatalogProduct($product)
    {
        $productId = (int) data_get($product, 'id', 0);
        $isFavorite = $productId > 0 && (bool) ($this->favoriteProductIdLookup[$productId] ?? false);

        if (is_object($product) && method_exists($product, 'setAttribute')) {
            $product->setAttribute('is_favorite', $isFavorite);

            return $product;
        }

        if (is_array($product)) {
            $product['is_favorite'] = $isFavorite;
        }

        return $product;
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
            'distance' => $this->normalizeRankingWeight($row->weight_distance ?? null, $defaults['distance']),
            'keyword' => $this->normalizeRankingWeight($row->weight_keyword ?? null, $defaults['keyword']),
            'lucky_ticket' => $this->normalizeRankingWeight($row->weight_lucky_ticket ?? null, $defaults['lucky_ticket']),
            'commission' => $this->normalizeRankingWeight($row->weight_commission ?? null, $defaults['commission']),
            'purchase' => $this->normalizeRankingWeight($row->weight_purchase ?? null, $defaults['purchase']),
            'rating' => $this->normalizeRankingWeight($row->weight_rating ?? null, $defaults['rating']),
            'history' => $this->normalizeRankingWeight($row->weight_history ?? null, $defaults['history']),
            'reputation' => $this->normalizeRankingWeight($row->weight_reputation ?? null, $defaults['reputation']),
            'risk_penalty' => $this->normalizeRankingWeight($row->weight_risk_penalty ?? null, $defaults['risk_penalty']),
        ];
    }

    private function normalizeRankingWeight($value, float $fallback): float
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

    private function buildKeywordScoreExpression(string $keyword, array &$bindings): string
    {
        $needle = '%' . mb_strtolower(trim($keyword)) . '%';
        $bindings[] = $needle;
        $bindings[] = $needle;
        $bindings[] = $needle;
        $bindings[] = $needle;

        return "CASE WHEN LOWER(products.name) LIKE ? THEN 100 "
            . "WHEN LOWER(product_categories.name) LIKE ? THEN 80 "
            . "WHEN LOWER(stores.name) LIKE ? THEN 60 "
            . "WHEN LOWER(products.slug) LIKE ? THEN 50 "
            . "ELSE 0 END";
    }

    private function buildCategoryScoreExpression(string $keyword, array &$bindings, bool $hasProductCategories): string
    {
        if (! $hasProductCategories) {
            return '0';
        }

        $needle = '%' . mb_strtolower(trim($keyword)) . '%';
        $bindings[] = $needle;

        return "CASE WHEN LOWER(product_categories.name) LIKE ? THEN 100 ELSE 0 END";
    }

    private function buildDistanceScoreExpression(?array $coordinates, float $radiusKm, array &$bindings): string
    {
        if ($coordinates === null || ! Schema::hasColumn('stores', 'location')) {
            return '0';
        }

        $bindings[] = (float) $coordinates['lng'];
        $bindings[] = (float) $coordinates['lat'];
        $bindings[] = $radiusKm;

        $distanceKmExpr = 'ST_Distance_Sphere(stores.location, POINT(?, ?)) / 1000';

        return 'CASE WHEN stores.location IS NULL THEN 0 '
            . 'ELSE GREATEST(0, 100 - LEAST(100, ((' . $distanceKmExpr . ') / ?) * 100)) END';
    }

    private function normalizeReviewTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function resolveReviewDecayWeight(mixed $createdAt, float $halfLifeDays): float
    {
        $timestamp = $this->normalizeReviewTimestamp($createdAt);
        if (! $timestamp || $halfLifeDays <= 0) {
            return 1.0;
        }

        $elapsedDays = max(0.0, $timestamp->diffInSeconds(now('Asia/Ho_Chi_Minh'), false) / 86400);
        $decayMu = log(2) / $halfLifeDays;

        return exp(-$decayMu * $elapsedDays);
    }

    private function calculateBayesianRatingMetrics(
        iterable $reviews,
        float $globalAverage,
        int $minReviews = 10,
        float $halfLifeDays = 90.0
    ): array {
        $rawCount = 0;
        $rawSum = 0.0;
        $weightedCount = 0.0;
        $weightedSum = 0.0;

        foreach ($reviews as $review) {
            $rating = (float) data_get($review, 'rating');
            if ($rating <= 0) {
                continue;
            }

            $weight = $this->resolveReviewDecayWeight(data_get($review, 'created_at'), $halfLifeDays);

            $rawCount++;
            $rawSum += $rating;
            $weightedCount += $weight;
            $weightedSum += $rating * $weight;
        }

        if ($rawCount === 0 || $weightedCount <= 0) {
            return [
                'raw_average' => null,
                'review_count' => 0,
                'weighted_count' => 0.0,
                'rating_score' => null,
            ];
        }

        return [
            'raw_average' => round($rawSum / $rawCount, 2),
            'review_count' => $rawCount,
            'weighted_count' => $weightedCount,
            'rating_score' => round(
                ($weightedSum + ($globalAverage * $minReviews))
                / ($weightedCount + $minReviews),
                2
            ),
        ];
    }

    private function resolveStoreRatingScoreMap(iterable $storeIds): array
    {
        $normalizedStoreIds = collect($storeIds)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values();

        if (
            $normalizedStoreIds->isEmpty()
            || ! Schema::hasTable('store_reviews')
            || ! Schema::hasColumn('store_reviews', 'store_id')
            || ! Schema::hasColumn('store_reviews', 'rating')
        ) {
            return [];
        }

        $query = StoreReview::query()
            ->select(['store_id', 'rating', 'created_at'])
            ->whereIn('store_id', $normalizedStoreIds->all())
            ->whereNotNull('rating');

        if (Schema::hasColumn('store_reviews', 'is_hidden')) {
            $query->where('is_hidden', false);
        }

        $reviewsByStore = $query
            ->get()
            ->groupBy(fn ($review) => (string) $review->store_id);

        $config = $this->resolveReviewScoreConfig();
        $globalAverage = $this->resolveGlobalReviewBaseline('store_id');
        $result = [];

        foreach ($reviewsByStore as $storeId => $reviews) {
            $metrics = $this->calculateBayesianRatingMetrics(
                $reviews,
                $globalAverage,
                (int) $config['min_reviews'],
                (float) $config['half_life_days']
            );

            $result[$storeId] = $metrics;
        }

        return $result;
    }

    private function resolveStoreRatingPayload(
        string $storeId,
        mixed $fallbackAverage = null,
        mixed $fallbackCount = null,
        array $ratingScoreMap = []
    ): array {
        $fallbackAverageValue = is_numeric($fallbackAverage) && (float) $fallbackAverage > 0
            ? round((float) $fallbackAverage, 2)
            : null;
        $fallbackCountValue = max(0, (int) ($fallbackCount ?? 0));
        $ratingMetrics = $ratingScoreMap[$storeId] ?? null;

        $displayRating = isset($ratingMetrics['rating_score'])
            ? (float) $ratingMetrics['rating_score']
            : $fallbackAverageValue;
        $rawAverage = isset($ratingMetrics['raw_average'])
            ? (float) $ratingMetrics['raw_average']
            : $fallbackAverageValue;
        $reviewCount = isset($ratingMetrics['review_count'])
            ? (int) $ratingMetrics['review_count']
            : $fallbackCountValue;
        $weightedCount = isset($ratingMetrics['weighted_count'])
            ? round((float) $ratingMetrics['weighted_count'], 3)
            : null;

        return [
            'rating_avg' => $displayRating,
            'rating_score' => $displayRating,
            'rating_raw_avg' => $rawAverage,
            'rating_count' => $reviewCount,
            'weighted_review_count' => $weightedCount,
        ];
    }

    private function normalizeStoreMediaUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                return $this->normalizeStoreMediaUrls($trimmed)[0] ?? null;
            }
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        $path = ltrim($normalized, '/');
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            return url('/' . $path);
        }

        if (str_starts_with($path, 'uploads/')) {
            return url('/storage/' . $path);
        }

        return url('/storage/' . $path);
    }

    private function normalizeStoreMediaUrls(mixed $value): array
    {
        $urls = [];

        foreach (StoreMedia::urls($value) as $url) {
            $normalized = $this->normalizeStoreMediaUrl($url);
            if ($normalized !== null) {
                $urls[$normalized] = $normalized;
            }
        }

        return array_values($urls);
    }

    private function resolvePopularProducts(array $filters = []): Collection
    {
        $cacheKey = md5(json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (array_key_exists($cacheKey, $this->popularProductsCache)) {
            return $this->popularProductsCache[$cacheKey];
        }

        if (
            ! Schema::hasTable('order_items')
            || ! Schema::hasColumn('order_items', 'product_id')
            || ! Schema::hasColumn('order_items', 'quantity')
        ) {
            return $this->popularProductsCache[$cacheKey] = collect();
        }

        $windows = $this->resolvePopularSalesWindows();
        $hasOrdersTable = Schema::hasTable('orders');
        $timestampExpression = $this->resolveCatalogOrderItemTimestampExpression();
        $statusExpression = $this->resolveCatalogOrderStatusExpression();
        $quantityExpression = 'COALESCE(order_items.quantity, 0)';
        $query = Product::select(
            'products.id',
            'products.name',
            'products.slug',
            'products.thumbnail',
            'products.sale_price',
            'products.stock',
            'products.price'
        )
            ->join('order_items', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with([
                'category',
                'group',
                'variants.attributeValues.attribute',
                'combos.bonusProduct',
                'reviews:id,product_id,rating,created_at',
            ])
            ->whereIn('products.status', ['published', 'approved', 'active']);

        $this->applyPublicStoreDebtVisibility($query);

        if ($hasOrdersTable) {
            $query->leftJoin('orders', 'orders.id', '=', 'order_items.order_id');
        }

        if ($timestampExpression !== null) {
            $query->whereRaw(
                $timestampExpression . ' >= ?',
                [$windows['last_7d_start']->format('Y-m-d H:i:s')]
            );

            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? THEN ' . $quantityExpression . ' ELSE 0 END) as sales_0_6h',
                [$windows['last_6h_start']->format('Y-m-d H:i:s')]
            );
            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? AND ' . $timestampExpression . ' < ? THEN ' . $quantityExpression . ' ELSE 0 END) as sales_6_24h',
                [
                    $windows['last_24h_start']->format('Y-m-d H:i:s'),
                    $windows['last_6h_start']->format('Y-m-d H:i:s'),
                ]
            );
            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? AND ' . $timestampExpression . ' < ? THEN ' . $quantityExpression . ' ELSE 0 END) as sales_24h_7d',
                [
                    $windows['last_7d_start']->format('Y-m-d H:i:s'),
                    $windows['last_24h_start']->format('Y-m-d H:i:s'),
                ]
            );
            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? THEN ' . $quantityExpression . ' ELSE 0 END) as total_qty_7d',
                [$windows['last_7d_start']->format('Y-m-d H:i:s')]
            );
        } else {
            $query->selectRaw('0 as sales_0_6h');
            $query->selectRaw('0 as sales_6_24h');
            $query->selectRaw('0 as sales_24h_7d');
            $query->selectRaw('SUM(' . $quantityExpression . ') as total_qty_7d');
        }

        if ($statusExpression !== null && $timestampExpression !== null) {
            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? AND ' . $statusExpression . " IN ('delivered', 'completed') THEN "
                . $quantityExpression . ' ELSE 0 END) as successful_qty_7d',
                [$windows['last_7d_start']->format('Y-m-d H:i:s')]
            );
            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? AND ' . $statusExpression . " IN ('cancelled', 'canceled') THEN "
                . $quantityExpression . ' ELSE 0 END) as cancelled_qty_7d',
                [$windows['last_7d_start']->format('Y-m-d H:i:s')]
            );
            $query->selectRaw(
                'SUM(CASE WHEN ' . $timestampExpression . ' >= ? AND ' . $statusExpression . " IN ('returned', 'refunded') THEN "
                . $quantityExpression . ' ELSE 0 END) as refunded_qty_7d',
                [$windows['last_7d_start']->format('Y-m-d H:i:s')]
            );
        } else {
            $query->selectRaw('SUM(' . $quantityExpression . ') as successful_qty_7d');
            $query->selectRaw('0 as cancelled_qty_7d');
            $query->selectRaw('0 as refunded_qty_7d');
        }

        $filterName = trim((string) ($filters['filter_name'] ?? ''));
        $filterMinSales = max(0, (float) ($filters['filter_min_sales'] ?? 0));
        $filterMinPrice = max(0, (float) ($filters['filter_min_price'] ?? 0));
        $filterMaxPrice = max(0, (float) ($filters['filter_max_price'] ?? 0));
        $filterMinDiscount = max(0, min(100, (float) ($filters['filter_min_discount'] ?? 0)));
        $filterInStock = (bool) ($filters['filter_in_stock'] ?? false);

        if ($filterName !== '') {
            $query->where('products.name', 'like', '%' . $filterName . '%');
        }
        if ($filterMinPrice > 0) {
            $query->where('products.sale_price', '>=', $filterMinPrice);
        }
        if ($filterMaxPrice > 0) {
            $query->where('products.sale_price', '<=', $filterMaxPrice);
        }
        if ($filterInStock) {
            $query->where('products.stock', '>', 0);
        }
        if ($filterMinDiscount > 0) {
            $query->whereRaw(
                'CASE WHEN COALESCE(products.price, 0) > 0 THEN '
                . '((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0) * 100) '
                . 'ELSE 0 END >= ?',
                [$filterMinDiscount]
            );
        }

        $products = $query
            ->groupBy(
                'products.id',
                'products.name',
                'products.slug',
                'products.thumbnail',
                'products.sale_price',
                'products.stock',
                'products.price',
                'stores.name',
                'stores.logo_url',
                'stores.location'
            )
            ->orderByRaw('SUM(COALESCE(order_items.quantity, 0)) DESC')
            ->limit(100)
            ->get()
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values();

        $products = $this->decoratePopularProductScores($products);

        if ($filterMinSales > 0) {
            $products = $products
                ->filter(fn ($product) => (float) ($product->raw_sold_count_7d ?? 0) >= $filterMinSales)
                ->values();
        }

        return $this->popularProductsCache[$cacheKey] = $products;
    }

    private function resolveDiscoveryCoordinates(Request $request): ?array
    {
        $latitude = $request->query('lat', $request->query('latitude'));
        $longitude = $request->query('lng', $request->query('longitude'));

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    private function calculateDistanceKm(
        float $originLat,
        float $originLng,
        float $targetLat,
        float $targetLng
    ): float {
        $earthRadiusKm = 6371.0;

        $deltaLat = deg2rad($targetLat - $originLat);
        $deltaLng = deg2rad($targetLng - $originLng);
        $originLatRad = deg2rad($originLat);
        $targetLatRad = deg2rad($targetLat);

        $haversine = sin($deltaLat / 2) ** 2
            + cos($originLatRad) * cos($targetLatRad) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1, sqrt($haversine)));
    }

    private function blendDiscoveryProducts(Collection $newestProducts, Collection $popularProducts): Collection
    {
        $blended = collect();
        $seenProductIds = [];
        $newestIndex = 0;
        $popularIndex = 0;
        $newestCount = $newestProducts->count();
        $popularCount = $popularProducts->count();

        $appendUniqueProducts = function (Collection $source, int &$cursor, int $take) use (&$blended, &$seenProductIds) {
            $taken = 0;

            while ($cursor < $source->count() && $taken < $take) {
                $product = $source->get($cursor);
                $cursor += 1;

                $productId = (string) data_get($product, 'id', '');
                if ($productId === '' || isset($seenProductIds[$productId])) {
                    continue;
                }

                $seenProductIds[$productId] = true;
                $blended->push($product);
                $taken += 1;
            }
        };

        // Freshness-led discovery: keep new items prominent, but inject proven winners
        // in between to mimic common marketplace ranking behavior.
        while ($newestIndex < $newestCount || $popularIndex < $popularCount) {
            $beforeCount = $blended->count();

            $appendUniqueProducts($newestProducts, $newestIndex, 2);
            $appendUniqueProducts($popularProducts, $popularIndex, 1);

            if ($blended->count() === $beforeCount) {
                break;
            }
        }

        return $blended;
    }

    private function paginateDiscoveryProducts(
        Collection $products,
        int $page,
        int $perPage,
        array $meta = []
    ): array {
        $total = $products->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $currentPage = min(max(1, $page), $lastPage);
        $offset = ($currentPage - 1) * $perPage;

        return [
            'products' => $products->slice($offset, $perPage)->values(),
            'meta' => array_merge([
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_next' => $currentPage < $lastPage,
            ], $meta),
        ];
    }

    private function resolveDiscoveryProducts(
        Request $request,
        int $page,
        int $perPage
    ): array {
        $coordinates = $this->resolveDiscoveryCoordinates($request);
        $radiusKm = max(1.0, min(50.0, (float) $request->query('radius_km', 10)));

        if ($coordinates !== null) {
            $query = Product::query()
                ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
                ->select('products.*')
                ->selectRaw('stores.name as store_name')
                ->selectRaw('stores.logo_url as store_image_url')
                ->selectRaw('ST_Y(stores.location) as store_lat')
                ->selectRaw('ST_X(stores.location) as store_lng')
                ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
                ->whereIn('products.status', ['published', 'approved', 'active'])
                ->when(Schema::hasColumn('stores', 'is_debt_locked'), function ($builder) {
                    $builder->where(function ($visibilityQuery) {
                        $visibilityQuery->whereNull('stores.is_debt_locked')
                            ->orWhere('stores.is_debt_locked', false);
                    });
                });

            $this->applyPublicStoreDebtVisibility($query);

            $query->selectRaw(
                '(ST_Distance_Sphere(stores.location, POINT(?, ?)) / 1000) as distance_km',
                [(float) $coordinates['lng'], (float) $coordinates['lat']]
            )
            ->having('distance_km', '<=', $radiusKm);

            $query->orderBy('distance_km', 'asc')
                ->orderByDesc('products.created_at');

            $paginated = $query->paginate($perPage, ['*'], 'discovery_page', $page);

            if ($paginated->total() > 0) {
                $this->primeFavoriteProductLookup($request, $paginated->items());
                
                $nearbyProducts = collect($paginated->items())->map(function ($product) {
                    if ($product->distance_km !== null) {
                        $product->setAttribute('distance_km', round((float)$product->distance_km, 3));
                    }
                    return $this->decorateCatalogProduct($product);
                });

                $nearbyStoreCount = 0;
                if (Schema::hasColumn('products', 'store_id')) {
                    $nearbyStoreCount = (int) (clone $query)->distinct()->count('products.store_id');
                }

                return [
                    'products' => $nearbyProducts->values(),
                    'meta' => [
                        'current_page' => $paginated->currentPage(),
                        'per_page' => $paginated->perPage(),
                        'total' => $paginated->total(),
                        'last_page' => $paginated->lastPage(),
                        'has_next' => $paginated->hasMorePages(),
                        'mode' => 'nearby',
                        'has_coordinates' => true,
                        'nearby_radius_km' => $radiusKm,
                        'nearby_store_count' => $nearbyStoreCount,
                        'fallback_reason' => null,
                    ]
                ];
            }
        }

        // Fallback or missing coordinates: blend newest products and popular products.
        $newestQuery = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->when(Schema::hasColumn('stores', 'is_debt_locked'), function ($builder) {
                $builder->where(function ($visibilityQuery) {
                    $visibilityQuery->whereNull('stores.is_debt_locked')
                        ->orWhere('stores.is_debt_locked', false);
                });
            })
            ->latest('products.created_at')
            ->limit(100);

        $this->applyPublicStoreDebtVisibility($newestQuery);
        $newestRows = $newestQuery->get();
        $this->primeFavoriteProductLookup($request, $newestRows);
        $newestDeco = $newestRows->map(fn($p) => $this->decorateCatalogProduct($p));

        $allTopProducts = $this->resolvePopularProducts();
        $fallbackProducts = $this->blendDiscoveryProducts($newestDeco, $allTopProducts);

        return $this->paginateDiscoveryProducts($fallbackProducts, $page, $perPage, [
            'mode' => 'fallback_mixed',
            'has_coordinates' => $coordinates !== null,
            'nearby_radius_km' => $coordinates !== null ? $radiusKm : null,
            'nearby_store_count' => 0,
            'fallback_reason' => $coordinates !== null ? 'no_nearby_store' : 'missing_coordinates',
        ]);
    }

    private function decoratePopularProductScores(Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }

        $weights = $this->resolvePopularityScoreWeights();
        $reviewConfig = $this->resolveReviewScoreConfig();
        $globalAverage = $this->resolveGlobalReviewBaseline('product_id');

        $scored = $products->map(function ($product) use ($weights, $reviewConfig, $globalAverage) {
            $sales0To6h = (float) ($product->sales_0_6h ?? 0);
            $sales6To24h = (float) ($product->sales_6_24h ?? 0);
            $sales24hTo7d = (float) ($product->sales_24h_7d ?? 0);
            $totalQty7d = max(0.0, (float) ($product->total_qty_7d ?? 0));
            $successfulQty7d = max(0.0, (float) ($product->successful_qty_7d ?? 0));
            $cancelledQty7d = max(0.0, (float) ($product->cancelled_qty_7d ?? 0));
            $refundedQty7d = max(0.0, (float) ($product->refunded_qty_7d ?? 0));

            $velocity = ($sales0To6h * $weights['velocity_0_6h'])
                + ($sales6To24h * $weights['velocity_6_24h'])
                + ($sales24hTo7d * $weights['velocity_24h_7d']);

            $denominator = max(1.0, $totalQty7d);
            $conversionRate = min(1.0, max(0.0, $successfulQty7d / $denominator));
            $cancelRate = min(1.0, max(0.0, $cancelledQty7d / $denominator));
            $refundRate = min(1.0, max(0.0, $refundedQty7d / $denominator));

            $ratingMetrics = $this->calculateBayesianRatingMetrics(
                $product->reviews ?? [],
                $globalAverage,
                (int) $reviewConfig['min_reviews'],
                (float) $reviewConfig['half_life_days']
            );
            $reviewCount = (int) ($ratingMetrics['review_count'] ?? 0);
            $ratingScore = $ratingMetrics['rating_score'] !== null
                ? (float) $ratingMetrics['rating_score']
                : $globalAverage;
            $ratingBonus = $reviewCount > 0
                ? ($ratingScore - $weights['rating_floor'])
                    * $weights['rating_bonus_scale']
                    * log(1 + $reviewCount)
                : 0.0;

            $popularScore = $velocity
                + ($conversionRate * $weights['cvr_multiplier'])
                - ($cancelRate * $weights['cancel_penalty'])
                - ($refundRate * $weights['refund_penalty'])
                + $ratingBonus;

            $product->setAttribute('popular_score', round($popularScore, 3));
            $product->setAttribute('velocity_score', round($velocity, 3));
            $product->setAttribute('conversion_rate', round($conversionRate, 4));
            $product->setAttribute('cancel_rate', round($cancelRate, 4));
            $product->setAttribute('refund_rate', round($refundRate, 4));
            $product->setAttribute('rating_bonus', round($ratingBonus, 3));
            $product->setAttribute('rating_score', $ratingMetrics['rating_score']);
            $product->setAttribute('rating_raw_avg', $ratingMetrics['raw_average']);
            $product->setAttribute('rating_review_count', $reviewCount);
            $product->setAttribute('today_sold_count', (int) round($sales0To6h));
            $product->setAttribute('daily_sold_count', (int) round($velocity));
            $product->setAttribute('total_sold', (int) round($velocity));
            $product->setAttribute('raw_sold_count_7d', (int) round($successfulQty7d > 0 ? $successfulQty7d : $totalQty7d));
            $product->setAttribute('sales_0_6h', round($sales0To6h, 3));
            $product->setAttribute('sales_6_24h', round($sales6To24h, 3));
            $product->setAttribute('sales_24h_7d', round($sales24hTo7d, 3));

            return $product;
        });

        return $scored
            ->sort(function ($left, $right) {
                $comparisons = [
                    (float) ($right->popular_score ?? 0) <=> (float) ($left->popular_score ?? 0),
                    (float) ($right->velocity_score ?? 0) <=> (float) ($left->velocity_score ?? 0),
                    (float) ($right->raw_sold_count_7d ?? 0) <=> (float) ($left->raw_sold_count_7d ?? 0),
                    (float) ($right->average_rating ?? 0) <=> (float) ($left->average_rating ?? 0),
                ];

                foreach ($comparisons as $comparison) {
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return strcmp((string) ($left->id ?? ''), (string) ($right->id ?? ''));
            })
            ->values();
    }

    public function getInfoApp()
    {
        $appSetting = $this->getAppSettings();

        return response()->json([
            'appSetting' => $this->transformAppSetting($appSetting),
        ]);
    }

    public function heroBanner()
    {
        $displayData = $this->resolveHomeBannerDisplayData();
        $banner = $displayData['banners']->first();

        return response()->json([
            'data' => $this->buildHeroBannerPayload($banner),
            'meta' => [
                'selected_mode' => $displayData['selected_mode'],
            ],
        ]);
    }

    public function heroBanners()
    {
        $displayData = $this->resolveHomeBannerDisplayData();
        $banners = $displayData['banners'];

        $data = $banners
            ->map(fn ($banner) => $this->buildHeroBannerPayload($banner))
            ->values();

        if ($data->isEmpty()) {
            $data = collect([$this->defaultHeroBannerPayload()]);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'selected_mode' => $displayData['selected_mode'],
                'total' => $data->count(),
            ],
        ]);
    }

    private function activeHomeBanners()
    {
        $now = now();

        return \App\Models\banner::query()
            ->where('status', 1)
            ->whereIn('position', ['1', 1])
            ->where(function ($query) use ($now) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $now);
            })
            ->orderBy('order')
            ->orderByDesc('id')
            ->get();
    }

    private function resolveHomeBannerDisplayData(): array
    {
        $allBanners = $this->activeHomeBanners();
        $selectedMode = $this->resolveSelectedHeroDisplayMode($allBanners);
        $filteredBanners = $this->filterBannersByDisplayMode($allBanners, $selectedMode);

        if ($selectedMode === 'room_static') {
            $filteredBanners = $filteredBanners->take(1)->values();
        }

        return [
            'selected_mode' => $selectedMode,
            'banners' => $filteredBanners,
        ];
    }

    private function resolveSelectedHeroDisplayMode(Collection $banners): string
    {
        $firstBanner = $banners->first();
        if (!$firstBanner) {
            return 'room_static';
        }

        $type = $this->normalizeHeroBannerType($firstBanner->banner_type ?? null);
        return $this->resolveHeroDisplayModeFromType($type);
    }

    private function filterBannersByDisplayMode(Collection $banners, string $selectedMode): Collection
    {
        if ($banners->isEmpty()) {
            return $banners;
        }

        $filtered = $banners
            ->filter(function ($banner) use ($selectedMode) {
                $type = $this->normalizeHeroBannerType($banner->banner_type ?? null);
                return $this->resolveHeroDisplayModeFromType($type) === $selectedMode;
            })
            ->values();

        return $filtered->isNotEmpty() ? $filtered : $banners->values();
    }

    private function defaultHeroBannerPayload(): array
    {
        return [
            'id' => null,
            'type' => 'room_link',
            'display_mode' => 'room_static',
            'is_static' => true,
            'room_selection_mode' => 'selected_room',
            'eyebrow' => 'Cơ Hội Trúng Lớn',
            'headline' => 'Mỗi món = 1 vé quay thưởng',
            'description' => 'Đủ 1000 vé -> quay trúng quà!',
            'cta_label' => 'Xem giải thưởng',
            'cta_link' => '/draw-room',
            'image' => null,
            'image_url' => null,
            'room_id' => null,
            'show_room_progress' => true,
            'updated_at' => null,
        ];
    }

    private function buildHeroBannerPayload($banner): array
    {
        $payload = $this->defaultHeroBannerPayload();

        if (!$banner) {
            return $payload;
        }

        $type = $this->normalizeHeroBannerType($banner->banner_type ?? null);
        $displayMode = $this->resolveHeroDisplayModeFromType($type);
        $bannerTitle = trim((string) $banner->title);
        $bannerLink = trim((string) $banner->link);
        $bannerHeadline = trim((string) ($banner->headline ?? ''));
        $bannerDescription = trim((string) ($banner->description ?? ''));
        $bannerCtaLabel = trim((string) ($banner->cta_label ?? ''));
        $bannerRoomId = trim((string) ($banner->room_id ?? ''));
        $roomSelectionMode = $this->normalizeHeroBannerRoomSelectionMode($banner->room_selection_mode ?? null);
        $resolvedRoomId = null;

        if ($type === 'room_link') {
            $resolvedRoomId = $roomSelectionMode === 'top_voted'
                ? $this->resolveTopVotedRoomId()
                : ($bannerRoomId !== '' ? $bannerRoomId : null);
        }

        $payload['id'] = (int) $banner->id;
        $payload['type'] = $type;
        $payload['display_mode'] = $displayMode;
        $payload['is_static'] = $displayMode === 'room_static';
        $payload['room_selection_mode'] = $type === 'room_link' ? $roomSelectionMode : null;
        $payload['show_room_progress'] = $type === 'room_link';
        $payload['room_id'] = $type === 'room_link' && $resolvedRoomId !== null
            ? $resolvedRoomId
            : null;

        if ($bannerTitle !== '') {
            $payload['eyebrow'] = $bannerTitle;
        }

        if ($bannerHeadline !== '') {
            $payload['headline'] = $bannerHeadline;
        }

        if ($bannerDescription !== '') {
            $payload['description'] = $bannerDescription;
        }

        if ($bannerCtaLabel !== '') {
            $payload['cta_label'] = $bannerCtaLabel;
        }

        if ($type === 'room_link' && $bannerLink === '') {
            $payload['cta_link'] = $resolvedRoomId !== null
                ? '/draw-room?roomId=' . urlencode($resolvedRoomId)
                : '/draw-room';
        } elseif ($bannerLink !== '') {
            $payload['cta_link'] = $bannerLink;
        }

        $payload['image'] = $banner->image ? (string) $banner->image : null;
        $payload['image_url'] = $this->resolveBannerImageUrl($banner->image);
        $payload['updated_at'] = optional($banner->updated_at)->toIso8601String();

        return $payload;
    }

    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        $banners = \App\Models\banner::where('position', 1)->where('status', 1)->get();

        // Pagination parameters for nearby products
        $nearbyPage = max(1, (int)$request->query('nearby_page', 1));
        $nearbyPerPage = max(1, (int)$request->query('nearby_per_page', 20));

        // Pagination parameters for best sellers
        $bestPage = max(1, (int)$request->query('best_page', 1));
        $bestPerPage = max(1, (int)$request->query('best_per_page', 10));

        // Fetch paginated products for nearby section
        $nearbyQuery = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->latest('products.created_at');

        $this->applyPublicStoreDebtVisibility($nearbyQuery, 'stores');

        $paginatedProducts = $nearbyQuery->paginate($nearbyPerPage, ['*'], 'nearby_page', $nearbyPage);

        $this->primeFavoriteProductLookup($request, $paginatedProducts->items());
        $homepageBlock = $this->resolveHomepageBlock();
        $products = collect($paginatedProducts->items())->map(fn ($product) => $this->decorateCatalogProduct($product))->values();

        $totalNearbyProducts = $paginatedProducts->total();
        $nearbyLastPage = $paginatedProducts->lastPage();

        $articleUserId = $user ? (int) $user->id : null;
        $articlesData = $this->orderArticlesForClient(\App\Models\article::with('category'))
            ->take(self::HOMEPAGE_NEWS_LIMIT)
            ->get();
        $this->primeArticleInteractions($articlesData, $articleUserId);
        $articles = $articlesData->map(fn ($article) => $this->serializeArticleForClient($article, $articleUserId))
            ->values();

        $menu1 = \App\Models\banner::where('position', 2)->where('status', 1)->get();
        $topProducts = collect();
        $popularSalesWindows = $this->resolvePopularSalesWindows();
        $allTopProducts = $this->resolvePopularProducts();
        $discoveryFeed = $this->resolveDiscoveryProducts(
            $request,
            $nearbyPage,
            $nearbyPerPage
        );

        if ($allTopProducts->isNotEmpty()) {
            // Calculate best products pagination
            $totalBestProducts = $allTopProducts->count();
            $bestLastPage = max(1, (int)ceil($totalBestProducts / $bestPerPage));
            $bestPage = min($bestPage, $bestLastPage);
            $bestOffset = ($bestPage - 1) * $bestPerPage;
            $topProducts = $allTopProducts->slice($bestOffset, $bestPerPage)->values();
        } else {
            $totalBestProducts = 0;
            $bestLastPage = 1;
        }

        $finalTop = collect();

        if (
            Schema::hasColumn('order_items', 'referrer_id')
            && Schema::hasColumn('order_items', 'price')
            && Schema::hasColumn('order_items', 'quantity')
        ) {
            $rawItems = DB::table('order_items')
                ->select('referrer_id', DB::raw('SUM(price * quantity) as total_sales'))
                ->whereNotNull('referrer_id')
                ->groupBy('referrer_id')
                ->orderByDesc('total_sales')
                ->take(20)
                ->get();

            $referrerIds = $rawItems->pluck('referrer_id')->unique()->all();
            $users = User::whereIn('id', $referrerIds)
                ->select('id', 'full_name', 'avatar')
                ->get()
                ->keyBy('id');

            $finalTop = $rawItems->map(function ($item) use ($users) {
                $user = $users->get($item->referrer_id);

                return [
                    'id' => $item->referrer_id,
                    'name' => $user?->full_name ?? 'Không rõ',
                    'avatar_url' => $user?->avatar,
                    'total_sales' => $item->total_sales,
                ];
            });
        }

        return response()->json([
            'homepage_block' => $homepageBlock,
            'newestBlock' => $this->resolveNewestBlock(),
            'bestsellersBlock' => $this->resolveBestsellersBlock(),
            'product_blocks' => $this->resolveHomepageProductBlocks(),
            'news_blocks' => $this->resolveHomepageNewsBlocks(),
            'banners' => $banners,
            'products' => $products,
            'products_meta' => [
                'current_page' => $nearbyPage,
                'per_page' => $nearbyPerPage,
                'total' => $totalNearbyProducts,
                'last_page' => $nearbyLastPage,
                'has_next' => $nearbyPage < $nearbyLastPage,
            ],
            'discovery_products' => $discoveryFeed['products'],
            'discovery_products_meta' => $discoveryFeed['meta'],
            'topProducts' => $topProducts,
            'topProducts_meta' => [
                'current_page' => $bestPage,
                'per_page' => $bestPerPage,
                'total' => $totalBestProducts,
                'last_page' => $bestLastPage,
                'has_next' => $bestPage < $bestLastPage,
                'window' => $this->serializePopularSalesWindows($popularSalesWindows),
            ],
            'articles' => $articles,
            'menu1' => $menu1,
            'finalTop' => $finalTop,
            'user' => $user,
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function resolveHomepageBlock(): array
    {
        $setting = $this->getAppSettings();
        if (!$setting) {
            return [
                'title' => '',
                'description' => '',
                'mode' => 'auto',
                'background_image' => null,
                'products' => [],
            ];
        }

        $rawBlocks = array_values((array) ($setting->homepage_product_blocks ?? []));
        $featuredBlock = collect($rawBlocks)->first(fn ($b) => ($b['type'] ?? '') === 'featured');

        if ($featuredBlock) {
            $value = $featuredBlock['value'] ?? '';
            return [
                'title' => trim((string) ($featuredBlock['title'] ?? '')),
                'description' => '',
                'mode' => 'manual',
                'background_image' => null,
                'products' => $this->resolveFeaturedProducts($value),
            ];
        }

        $config = (array) ($setting->homepage_block ?? []);
        $mode = in_array(($config['mode'] ?? 'auto'), ['auto', 'manual'], true) ? (string) ($config['mode'] ?? 'auto') : 'auto';

        return [
            'title' => trim((string) ($config['title'] ?? '')),
            'description' => trim((string) ($config['description'] ?? '')),
            'mode' => $mode,
            'background_image' => isset($config['background_image']) ? (string) $config['background_image'] : null,
            'products' => $this->resolveHomepageBlockProducts($config, $mode),
        ];
    }

    private function resolveHomepageProductBlocks(): array
    {
        $setting = $this->getAppSettings();
        if (!$setting) {
            return [];
        }

        $rawBlocks = array_values((array) ($setting->homepage_product_blocks ?? []));

        return collect($rawBlocks)
            ->map(function (array $block, int $index) {
                $type = $block['type'] ?? 'criteria';
                $title = trim((string) ($block['title'] ?? ''));
                $value = $block['value'] ?? '';

                $products = [];
                if ($type === 'flashsale') {
                    $products = $this->resolveFlashSaleProducts($value);
                } elseif ($type === 'featured') {
                    $products = $this->resolveFeaturedProducts($value);
                } elseif ($type === 'criteria') {
                    $sortBy = in_array($value, ['by_sales', 'by_newest', 'by_discount', 'by_price_low', 'by_price_high'], true) ? $value : 'by_newest';
                    $products = $this->resolveHomepageProductBlockProducts($sortBy, 10, $block);
                }

                return [
                    'id' => $index + 1,
                    'title' => $title,
                    'description' => '',
                    'badge' => '',
                    'type' => $type,
                    'sort_by' => $type === 'criteria' ? $value : 'by_newest',
                    'limit' => 10,
                    'products' => $products,
                ];
            })
            ->filter(fn (array $block) => $block['title'] !== '' || !empty($block['products']))
            ->values()
            ->all();
    }

    private function resolveFlashSaleProducts($flashSaleId): array
    {
        if (!Schema::hasTable('flash_sales') || !Schema::hasTable('flash_sale_items')) {
            return [];
        }

        $now = now('Asia/Ho_Chi_Minh');
        $query = DB::table('flash_sale_items as fsi')
            ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
            ->join('products as p', 'p.id', '=', 'fsi.product_id')
            ->where('fs.is_active', true)
            ->where('fs.end_time', '>=', $now)
            ->whereIn('p.status', ['published', 'approved', 'active']);

        if (!empty($flashSaleId) && $flashSaleId !== 'all') {
            $query->where('fs.id', $flashSaleId);
        }

        $productIds = $query->pluck('p.id')->unique()->all();
        if (empty($productIds)) {
            return [];
        }

        return Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.id', $productIds)
            ->limit(10)
            ->get()
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values()
            ->all();
    }

    private function resolveFeaturedProducts($categoryId): array
    {
        $query = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.status', ['published', 'approved', 'active']);

        $this->applyPublicStoreDebtVisibility($query);

        if (!empty($categoryId) && $categoryId !== 'all') {
            $query->where('products.category_id', $categoryId);
        }

        return $query
            ->limit(10)
            ->get()
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values()
            ->all();
    }

    private function resolveNewsBlockArticles($categoryId): array
    {
        $query = \App\Models\article::query()->with('category');
        if (!empty($categoryId) && $categoryId !== 'all') {
            $query->where('category_id', $categoryId);
        }

        $articles = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $this->primeArticleInteractions($articles, null);

        return $articles
            ->map(function ($article) {
                $payload = $this->serializeArticleForClient($article);
                return [
                    'id' => $payload['id'],
                    'title' => $payload['title'],
                    'slug' => $payload['slug'],
                    'summary' => $payload['summary'] ?? null,
                    'image' => $payload['image'] ?? null,
                    'image_url' => $payload['image_url'] ?? null,
                    'category' => $payload['category'] ?? null,
                    'is_hot' => $payload['is_hot'] ?? false,
                    'published_at' => $payload['published_at'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveHomepageBlockProducts(array $config, string $mode): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', (array) ($config['manual_product_ids'] ?? []))));

        $query = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.status', ['published', 'approved', 'active']);

        $this->applyPublicStoreDebtVisibility($query);

        if ($mode === 'manual' && !empty($selectedIds)) {
            $query->whereIn('products.id', $selectedIds)
                ->orderByRaw('FIELD(products.id, ' . implode(',', $selectedIds) . ')');
        } else {
            $query->orderByRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0)) ELSE 0 END DESC');
        }

        $products = $query
            ->limit(4)
            ->get()
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values()
            ->all();

        return $products;
    }

    private function resolveHomepageNewsBlocks(): array
    {
        $setting = $this->getAppSettings();
        if (!$setting) {
            return [];
        }

        $rawBlocks = array_values((array) ($setting->homepage_product_blocks ?? []));

        return collect($rawBlocks)
            ->filter(fn ($block) => ($block['type'] ?? '') === 'news')
            ->map(function (array $block, int $index) {
                return [
                    'id' => $index + 1,
                    'title' => trim((string) ($block['title'] ?? 'Tin tức mới')),
                    'description' => '',
                    'badge' => '',
                    'limit' => self::HOMEPAGE_NEWS_LIMIT,
                    'articles' => $this->resolveNewsBlockArticles($block['value'] ?? ''),
                ];
            })
            ->filter(fn (array $block) => $block['title'] !== '' || !empty($block['articles']))
            ->values()
            ->all();
    }

    private function resolveHomepageNewsBlockArticles(array $config): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', (array) ($config['manual_article_ids'] ?? []))));
        $limit = self::HOMEPAGE_NEWS_LIMIT;

        $query = \App\Models\article::query()
            ->with('category');

        if (!empty($selectedIds)) {
            $query->whereIn('id', $selectedIds)
                ->orderByRaw('FIELD(id, ' . implode(',', $selectedIds) . ')');
        } else {
            $this->orderArticlesForClient($query);
        }

        $articles = $query
            ->limit($limit)
            ->get();

        $this->primeArticleInteractions($articles, null);

        return $articles
            ->map(function ($article) {
                $payload = $this->serializeArticleForClient($article);

                return [
                    'id' => $payload['id'],
                    'title' => $payload['title'],
                    'slug' => $payload['slug'],
                    'summary' => $payload['summary'] ?? null,
                    'image' => $payload['image'] ?? null,
                    'image_url' => $payload['image_url'] ?? null,
                    'category' => $payload['category'] ?? null,
                    'is_hot' => $payload['is_hot'] ?? false,
                    'published_at' => $payload['published_at'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get top 10 bestselling products with metadata
     * @return array
     */
    private function resolveBestsellersBlock(): array
    {
        $setting = $this->getAppSettings();
        $config = (array) ($setting->bestsellers_block ?? []);
        
        $title = trim((string) ($config['title'] ?? 'Sản phẩm bán chạy'));
        $description = trim((string) ($config['description'] ?? 'Những sản phẩm được yêu thích nhất'));
        $badge = trim((string) ($config['badge'] ?? ''));
        $sortBy = in_array(($config['sort_by'] ?? 'by_sales'), ['by_sales', 'by_discount', 'by_price_low', 'by_price_high'], true) 
            ? (string) ($config['sort_by'] ?? 'by_sales') 
            : 'by_sales';

        if (!Schema::hasColumn('order_items', 'product_id') || !Schema::hasColumn('order_items', 'quantity')) {
            return [
                'title' => $title,
                'description' => $description,
                'badge' => $badge,
                'sort_by' => $sortBy,
                'products' => [],
            ];
        }

        $products = $this->resolvePopularProducts();

        switch ($sortBy) {
            case 'by_discount':
                $products = $products
                    ->sortByDesc(function ($product) {
                        $price = max(0.0, (float) ($product->price ?? 0));
                        $salePrice = max(0.0, (float) ($product->sale_price ?? 0));

                        return $price > 0 ? (($price - $salePrice) / $price) : 0;
                    })
                    ->values();
                break;
            case 'by_price_low':
                $products = $products
                    ->sortBy(fn ($product) => (float) ($product->price ?? 0))
                    ->values();
                break;
            case 'by_price_high':
                $products = $products
                    ->sortByDesc(fn ($product) => (float) ($product->price ?? 0))
                    ->values();
                break;
            case 'by_sales':
            default:
                break;
        }

        return $products
            ->take(4)
            ->values()
            ->all();
    }

    /**
                 * @return array<int, mixed>
                 */
                private function resolveHomepageProductBlockProducts(string $sortBy, int $limit = 10, array $filters = []): array
                {
                    $limit = max(1, min($limit, 10));

                    // Extract filter parameters
                    $filterName = trim((string) ($filters['filter_name'] ?? ''));
                    $filterMinSales = max(0, (int) ($filters['filter_min_sales'] ?? 0));
                    $filterMinPrice = max(0, (float) ($filters['filter_min_price'] ?? 0));
                    $filterMaxPrice = (float) ($filters['filter_max_price'] ?? 0);
                    $filterMinDiscount = max(0, min(100, (float) ($filters['filter_min_discount'] ?? 0)));
                    $filterInStock = (bool) ((int) ($filters['filter_in_stock'] ?? 0));

                    if ($sortBy === 'by_sales') {
                        if (!Schema::hasColumn('order_items', 'product_id') || !Schema::hasColumn('order_items', 'quantity')) {
                            $sortBy = 'by_newest';
                        } else {
                            return $this->resolvePopularProducts([
                                'filter_name' => $filterName,
                                'filter_min_sales' => $filterMinSales,
                                'filter_min_price' => $filterMinPrice,
                                'filter_max_price' => $filterMaxPrice,
                                'filter_min_discount' => $filterMinDiscount,
                                'filter_in_stock' => $filterInStock,
                            ])
                                ->take($limit)
                                ->values()
                                ->all();
                        }
                    }

                    $query = Product::query()
                        ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
                        ->select('products.*')
                        ->selectRaw('stores.name as store_name')
                        ->selectRaw('stores.logo_url as store_image_url')
                        ->selectRaw('ST_Y(stores.location) as store_lat')
                        ->selectRaw('ST_X(stores.location) as store_lng')
                        ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
                        ->whereIn('products.status', ['published', 'approved', 'active']);

                    $this->applyPublicStoreDebtVisibility($query);

                    // Apply filters
                    if ($filterName !== '') {
                        $query->where('products.name', 'like', "%{$filterName}%");
                    }
                    if ($filterMinPrice > 0) {
                        $query->where('products.sale_price', '>=', $filterMinPrice);
                    }
                    if ($filterMaxPrice > 0) {
                        $query->where('products.sale_price', '<=', $filterMaxPrice);
                    }
                    if ($filterInStock) {
                        $query->where('products.stock', '>', 0);
                    }
                    if ($filterMinDiscount > 0) {
                        $query->whereRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0) * 100) ELSE 0 END >= ?', [$filterMinDiscount]);
                    }

                    switch ($sortBy) {
                        case 'by_discount':
                            $query->orderByRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0)) ELSE 0 END DESC');
                            break;
                        case 'by_price_low':
                            $query->orderBy('products.price', 'asc');
                            break;
                        case 'by_price_high':
                            $query->orderBy('products.price', 'desc');
                            break;
                        case 'by_newest':
                        default:
                            $query->orderByDesc('products.created_at');
                            break;
                    }

                    return $query
                        ->limit($limit)
                        ->get()
                        ->map(fn ($product) => $this->decorateCatalogProduct($product))
                        ->values()
                        ->all();
                }
        
    

    /**
     * Get top 10 newest products with metadata
     * @return array
     */
    private function resolveNewestBlock(): array
    {
        $setting = $this->getAppSettings();
        $config = (array) ($setting->newest_block ?? []);
        
        $title = trim((string) ($config['title'] ?? 'Sản phẩm mới'));
        $description = trim((string) ($config['description'] ?? 'Những sản phẩm vừa được thêm'));
        $badge = trim((string) ($config['badge'] ?? ''));
        $sortBy = in_array(($config['sort_by'] ?? 'by_newest'), ['by_newest', 'by_discount', 'by_price_low', 'by_price_high'], true) 
            ? (string) ($config['sort_by'] ?? 'by_newest') 
            : 'by_newest';

        $query = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.status', ['published', 'approved', 'active']);

        $this->applyPublicStoreDebtVisibility($query);

        // Apply sort criteria
        switch ($sortBy) {
            case 'by_discount':
                $query->orderByRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0)) ELSE 0 END DESC');
                break;
            case 'by_price_low':
                $query->orderBy('products.price', 'asc');
                break;
            case 'by_price_high':
                $query->orderBy('products.price', 'desc');
                break;
            case 'by_newest':
            default:
                $query->orderByDesc('products.created_at');
                break;
        }

        $products = $query->limit(10)
            ->get()
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values()
            ->all();

        return [
            'title' => $title,
            'description' => $description,
            'badge' => $badge,
            'sort_by' => $sortBy,
            'products' => $products,
        ];
    }

    /**
     * Get top 10 bestselling products
     * @return array<int, mixed>
     */
    private function resolveBestsellersBlockOld(): array
    {
        if (!Schema::hasColumn('order_items', 'product_id') || !Schema::hasColumn('order_items', 'quantity')) {
            return [];
        }

        return $this->resolvePopularProducts()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Get top 10 newest products
     * @return array<int, mixed>
     */
    private function resolveNewestBlockOld(): array
    {
        return Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct')
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->when(Schema::hasColumn('stores', 'is_debt_locked'), function ($builder) {
                $builder->where(function ($visibilityQuery) {
                    $visibilityQuery->whereNull('stores.is_debt_locked')
                        ->orWhere('stores.is_debt_locked', false);
                });
            })
            ->orderByDesc('products.created_at')
            ->limit(10)
            ->get()
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values()
            ->all();
    }

    private function transformAppSetting(?AppSetting $appSetting): array
    {
        if (!$appSetting) {
            return [];
        }

        $homepageBlock = (array) ($appSetting->homepage_block ?? []);
        unset($homepageBlock['enabled']);

        return [
            'app_name' => $appSetting->app_name,
            'description' => $appSetting->description,
            'logo_path' => $appSetting->logo_path,
            'favicon_path' => $appSetting->favicon_path,
            'banner_path' => $appSetting->banner_path,
            'default_color' => $appSetting->default_color,
            'homepage_block' => $homepageBlock,
        ];
    }

    private function calculateProductRating($product)
    {
        $defaultRatingBreakdown = [
            '5' => 0,
            '4' => 0,
            '3' => 0,
            '2' => 0,
            '1' => 0,
        ];

        if (!($product instanceof Product) || !$product->id) {
            return [
                'average_rating' => 0,
                'review_count' => 0,
                'rating_breakdown' => $defaultRatingBreakdown,
            ];
        }

        $reviews = $product->relationLoaded('reviews')
            ? $product->reviews
            : StoreReview::where('product_id', $product->id)
                ->where('is_hidden', false)
                ->get();

        $totalReviews = $reviews->count();

        if ($totalReviews === 0) {
            return [
                'average_rating' => 0,
                'review_count' => 0,
                'rating_breakdown' => $defaultRatingBreakdown,
            ];
        }

        $totalRating = $reviews->sum('rating');
        $averageRating = round($totalReviews > 0 ? $totalRating / $totalReviews : 0, 2);

        $ratingBreakdown = [
            '5' => $reviews->where('rating', 5)->count(),
            '4' => $reviews->where('rating', 4)->count(),
            '3' => $reviews->where('rating', 3)->count(),
            '2' => $reviews->where('rating', 2)->count(),
            '1' => $reviews->where('rating', 1)->count(),
        ];

        return [
            'average_rating' => $averageRating,
            'review_count' => $totalReviews,
            'rating_breakdown' => $ratingBreakdown,
        ];
    }

    private function decorateCatalogProduct($product)
    {
        $ticketPricingService = $this->ticketPricingService();
        $flashSaleService = app(FlashSaleService::class);

        if ($product instanceof Product) {
            if ($product->relationLoaded('variants')) {
                $product->setRelation('variants', $this->filterEnabledVariants($product->variants));
            }

            $summary = $this->resolveCatalogPricingSummary(
                (float) ($product->price ?? 0),
                (float) ($product->sale_price ?? 0),
                $product->relationLoaded('variants') ? $product->variants : collect()
            );

            $storeId = $product->store_id !== null ? (string) $product->store_id : null;
            $productFlashSale = $flashSaleService->resolveEffectivePrice(
                $storeId,
                (int) $product->id,
                null,
                (float) ($product->sale_price ?? $product->price ?? 0)
            );

            $minVariantFlashPrice = null;
            if ($product->relationLoaded('variants')) {
                $decoratedVariants = $product->variants
                    ->map(function ($variant) use ($ticketPricingService, $flashSaleService, $storeId, $product, &$minVariantFlashPrice) {
                        $decorated = $this->decorateCatalogVariantWithTicketPreview($variant, $ticketPricingService);
                        $variantBasePrice = (float) ($decorated->sale_price ?? $decorated->price ?? 0);
                        $variantFlashSale = $flashSaleService->resolveEffectivePrice(
                            $storeId,
                            (int) $product->id,
                            (int) ($decorated->id ?? 0),
                            $variantBasePrice
                        );

                        if (!empty($variantFlashSale['is_flash_sale'])) {
                            $decorated->setAttribute('flash_sale', $variantFlashSale['flash_sale']);
                            $flashPrice = (float) ($variantFlashSale['price'] ?? 0);
                            if ($flashPrice > 0 && ($minVariantFlashPrice === null || $flashPrice < $minVariantFlashPrice)) {
                                $minVariantFlashPrice = $flashPrice;
                            }
                        }

                        return $decorated;
                    });
                $product->setRelation('variants', $decoratedVariants);
            }

            $productTicketTypeCode = $product->ticket_type_code !== null ? (string) $product->ticket_type_code : null;
            $productBasePrice = max(0, (float) ($summary['display_price'] ?? ($product->sale_price ?? $product->price ?? 0)));
            $productTicketQuantity = max(0, (int) ($product->ticket_reward_quantity ?? 0));

            $displayPrice = $summary['display_price'];
            $displayOriginalPrice = $summary['display_original_price'];

            if (!empty($productFlashSale['is_flash_sale'])) {
                $displayOriginalPrice = $displayPrice;
                $displayPrice = (float) ($productFlashSale['price'] ?? $displayPrice);
                $product->setAttribute('flash_sale', $productFlashSale['flash_sale']);
            } elseif ($minVariantFlashPrice !== null && $minVariantFlashPrice > 0) {
                $displayOriginalPrice = $displayPrice;
                $displayPrice = min((float) $displayPrice, (float) $minVariantFlashPrice);
                $product->setAttribute('has_flash_sale_variants', true);
                $product->setAttribute('flash_sale_min_price', (float) $minVariantFlashPrice);
            }

            $product->setAttribute('display_price', $displayPrice);
            $product->setAttribute('display_original_price', $displayOriginalPrice);
            $product->setAttribute('pricing_label', $summary['pricing_label']);
            $product->setAttribute('has_variants', $summary['has_variants']);
            $menuAvailability = $this->resolveProductMenuAvailability((int) $product->id, $storeId);
            if ($menuAvailability !== null) {
                $product->setAttribute('in_stock', $menuAvailability);
                $product->setAttribute('is_available', $menuAvailability);
            }
            $product->setAttribute('ticket_type_code', $productTicketTypeCode);
            $product->setAttribute(
                'ticket_pricing_preview',
                $ticketPricingService->calculatePreview($productBasePrice, $productTicketQuantity, $productTicketTypeCode)
            );

            // Bổ sung rating data
            $ratingData = $this->calculateProductRating($product);
            $product->setAttribute('average_rating', $ratingData['average_rating']);
            $product->setAttribute('review_count', $ratingData['review_count']);
            $product->setAttribute('rating_breakdown', $ratingData['rating_breakdown']);
            $this->applyFavoriteStateToCatalogProduct($product);

            return $product;
        }

        if (is_array($product)) {
            if (isset($product['variants']) && is_iterable($product['variants'])) {
                $product['variants'] = $this->filterEnabledVariants($product['variants'])->values()->all();
            }

            $summary = $this->resolveCatalogPricingSummary(
                (float) ($product['price'] ?? 0),
                (float) ($product['sale_price'] ?? 0),
                $product['variants'] ?? []
            );

            $storeId = isset($product['store_id']) && $product['store_id'] !== null
                ? (string) $product['store_id']
                : null;
            $productFlashSale = $flashSaleService->resolveEffectivePrice(
                $storeId,
                (int) ($product['id'] ?? 0),
                null,
                (float) ($product['sale_price'] ?? $product['price'] ?? 0)
            );

            $minVariantFlashPrice = null;
            if (isset($product['variants']) && is_iterable($product['variants'])) {
                $product['variants'] = collect($product['variants'])
                    ->map(function ($variant) use ($ticketPricingService, $flashSaleService, $storeId, $product, &$minVariantFlashPrice) {
                        $decorated = $this->decorateCatalogVariantWithTicketPreview($variant, $ticketPricingService);
                        $variantId = (int) data_get($decorated, 'id', 0);
                        $variantBasePrice = (float) (data_get($decorated, 'sale_price', 0) ?: data_get($decorated, 'price', 0));

                        $variantFlashSale = $flashSaleService->resolveEffectivePrice(
                            $storeId,
                            (int) ($product['id'] ?? 0),
                            $variantId > 0 ? $variantId : null,
                            $variantBasePrice
                        );

                        if (!empty($variantFlashSale['is_flash_sale'])) {
                            data_set($decorated, 'flash_sale', $variantFlashSale['flash_sale']);
                            $flashPrice = (float) ($variantFlashSale['price'] ?? 0);
                            if ($flashPrice > 0 && ($minVariantFlashPrice === null || $flashPrice < $minVariantFlashPrice)) {
                                $minVariantFlashPrice = $flashPrice;
                            }
                        }

                        return $decorated;
                    })
                    ->values()
                    ->all();
            }

            $productTicketTypeCode = isset($product['ticket_type_code']) && $product['ticket_type_code'] !== null
                ? (string) $product['ticket_type_code']
                : null;
            $productBasePrice = max(0, (float) ($summary['display_price'] ?? ($product['sale_price'] ?? $product['price'] ?? 0)));
            $productTicketQuantity = max(0, (int) ($product['ticket_reward_quantity'] ?? 0));

            $displayPrice = $summary['display_price'];
            $displayOriginalPrice = $summary['display_original_price'];

            if (!empty($productFlashSale['is_flash_sale'])) {
                $displayOriginalPrice = $displayPrice;
                $displayPrice = (float) ($productFlashSale['price'] ?? $displayPrice);
                $product['flash_sale'] = $productFlashSale['flash_sale'];
            } elseif ($minVariantFlashPrice !== null && $minVariantFlashPrice > 0) {
                $displayOriginalPrice = $displayPrice;
                $displayPrice = min((float) $displayPrice, (float) $minVariantFlashPrice);
                $product['has_flash_sale_variants'] = true;
                $product['flash_sale_min_price'] = (float) $minVariantFlashPrice;
            }

            $product['display_price'] = $displayPrice;
            $product['display_original_price'] = $displayOriginalPrice;
            $product['pricing_label'] = $summary['pricing_label'];
            $product['has_variants'] = $summary['has_variants'];
            $menuAvailability = $this->resolveProductMenuAvailability((int) ($product['id'] ?? 0), $storeId);
            if ($menuAvailability !== null) {
                $product['in_stock'] = $menuAvailability;
                $product['is_available'] = $menuAvailability;
            }
            $product['ticket_type_code'] = $productTicketTypeCode;
            $product['ticket_pricing_preview'] = $ticketPricingService->calculatePreview(
                $productBasePrice,
                $productTicketQuantity,
                $productTicketTypeCode
            );

            // Bổ sung rating data cho array product
            $product['average_rating'] = 0;
            $product['review_count'] = 0;
            $product['rating_breakdown'] = [
                '5' => 0,
                '4' => 0,
                '3' => 0,
                '2' => 0,
                '1' => 0,
            ];
            $product = $this->applyFavoriteStateToCatalogProduct($product);

            return $product;
        }

        return $product;
    }

    private function decorateCatalogVariantWithTicketPreview($variant, TicketPricingService $ticketPricingService)
    {
        $variantOriginalPrice = max(0, (float) data_get($variant, 'price', 0));
        $variantSalePrice = max(0, (float) data_get($variant, 'sale_price', 0));
        $variantBasePrice = $variantSalePrice > 0 ? $variantSalePrice : $variantOriginalPrice;
        $variantTicketQuantity = max(0, (int) data_get($variant, 'ticket_reward_quantity', 0));
        $variantTicketTypeCode = data_get($variant, 'ticket_type_code');
        $variantTicketTypeCode = $variantTicketTypeCode !== null ? (string) $variantTicketTypeCode : null;

        $preview = $ticketPricingService->calculatePreview($variantBasePrice, $variantTicketQuantity, $variantTicketTypeCode);

        if (is_object($variant) && method_exists($variant, 'setAttribute')) {
            $variant->setAttribute('ticket_type_code', $variantTicketTypeCode);
            $variant->setAttribute('ticket_pricing_preview', $preview);

            return $variant;
        }

        if (is_array($variant)) {
            $variant['ticket_type_code'] = $variantTicketTypeCode;
            $variant['ticket_pricing_preview'] = $preview;
        }

        return $variant;
    }

    private function filterEnabledVariants(iterable $variants)
    {
        $collection = collect($variants);

        if (!Schema::hasColumn('product_variants', 'enabled')) {
            return $collection->values();
        }

        return $collection
            ->filter(static fn ($variant): bool => (bool) data_get($variant, 'enabled', true))
            ->values();
    }

    private function resolveCatalogPricingSummary(float $basePrice, float $baseSalePrice, iterable $variants): array
    {
        $hasVariants = false;
        $variantDisplayPrices = [];
        $variantOriginalPrices = [];

        foreach ($variants as $variant) {
            $hasVariants = true;

            $variantOriginalPrice = max(0, (float) data_get($variant, 'price', 0));
            $variantSalePrice = max(0, (float) data_get($variant, 'sale_price', 0));
            $variantDisplayPrice = $variantSalePrice > 0 ? $variantSalePrice : $variantOriginalPrice;

            if ($variantDisplayPrice > 0) {
                $variantDisplayPrices[] = $variantDisplayPrice;
            }

            if ($variantOriginalPrice > 0) {
                $variantOriginalPrices[] = $variantOriginalPrice;
            }
        }

        if ($hasVariants) {
            $displayPrice = !empty($variantDisplayPrices)
                ? min($variantDisplayPrices)
                : ($baseSalePrice > 0 ? $baseSalePrice : $basePrice);

            $displayOriginalPrice = !empty($variantOriginalPrices)
                ? max($variantOriginalPrices)
                : ($basePrice > 0 ? $basePrice : $displayPrice);

            $pricingLabel = null;
            if ($displayPrice > 0) {
                $uniqueDisplayPrices = array_values(array_unique(array_map(static fn ($value) => round((float) $value), $variantDisplayPrices)));
                $pricingLabel = count($uniqueDisplayPrices) > 1
                    ? 'Từ ' . $this->formatCatalogPrice($displayPrice)
                    : $this->formatCatalogPrice($displayPrice);
            }

            return [
                'display_price' => $displayPrice,
                'display_original_price' => $displayOriginalPrice,
                'pricing_label' => $pricingLabel,
                'has_variants' => true,
            ];
        }

        $displayPrice = $baseSalePrice > 0 ? $baseSalePrice : $basePrice;
        $displayOriginalPrice = $basePrice > 0 ? $basePrice : $displayPrice;

        return [
            'display_price' => $displayPrice,
            'display_original_price' => $displayOriginalPrice,
            'pricing_label' => null,
            'has_variants' => false,
        ];
    }

    private function formatCatalogPrice(float $price): string
    {
        return number_format((float) round($price), 0, ',', '.') . ' đ';
    }

    private function resolveBannerImageUrl(?string $imagePath): ?string
    {
        $normalizedPath = trim((string) $imagePath);

        if ($normalizedPath === '') {
            return null;
        }

        if (str_starts_with($normalizedPath, 'http://') || str_starts_with($normalizedPath, 'https://')) {
            return $normalizedPath;
        }

        $cleanPath = ltrim($normalizedPath, '/');

        if (str_starts_with($cleanPath, 'storage/')) {
            return url($cleanPath);
        }

        return url('storage/' . $cleanPath);
    }

    private function normalizeHeroBannerType(?string $type): string
    {
        $normalized = strtolower(trim((string) $type));

        if ($normalized === '') {
            return 'room_link';
        }

        if (in_array($normalized, ['room_link', 'room_static', 'fixed_room'], true)) {
            return 'room_link';
        }

        if (in_array($normalized, ['image_only', 'image_slider'], true)) {
            return 'image_only';
        }

        if (in_array($normalized, ['promo', 'remaining_slider', 'content_slider'], true)) {
            return 'promo';
        }

        return 'promo';
    }

    private function resolveHeroDisplayModeFromType(string $type): string
    {
        if ($type === 'room_link') {
            return 'room_static';
        }

        if ($type === 'image_only') {
            return 'image_slider';
        }

        return 'remaining_slider';
    }

    private function normalizeHeroBannerRoomSelectionMode(?string $mode): string
    {
        $normalized = strtolower(trim((string) $mode));

        if (in_array($normalized, ['selected_room', 'top_voted'], true)) {
            return $normalized;
        }

        return 'selected_room';
    }

    private function resolveTopVotedRoomId(): ?string
    {
        $today = now('Asia/Ho_Chi_Minh')->toDateString();

        $room = Room::query()
            ->whereDate('room_date', $today)
            ->whereIn('status', ['waiting', 'filling', 'full'])
            ->orderByDesc('current_votes')
            ->orderBy('room_number')
            ->first(['id']);

        return $room ? (string) $room->id : null;
    }

    public function showProduct($slug, Request $request)
    {
        $productQuery = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->where('products.slug', $slug)
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->with(['variants.attributeValues.attribute', 'reviews', 'toppings']);
        $this->applyPublicStoreDebtVisibility($productQuery);
        $product = $productQuery->firstOrFail();

        if (Schema::hasColumn('products', 'view_count')) {
            Product::query()->whereKey($product->id)->increment('view_count');
            $product->view_count = (int) ($product->view_count ?? 0) + 1;
        }

        $relatedProductRowsQuery = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->where('products.category_id', $product->category_id)
            ->where('products.id', '!=', $product->id)
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->with('reviews')
            ->latest('products.created_at')
            ->take(4);
        $this->applyPublicStoreDebtVisibility($relatedProductRowsQuery);
        $relatedProductRows = $relatedProductRowsQuery->get();

        $this->primeFavoriteProductLookup($request, collect([$product])->merge($relatedProductRows));

        $product = $this->decorateCatalogProduct($product);
        $variantData = $product->variants->map(function ($variant) {
            return [
                'attributes' => $variant->attributeValues->pluck('value', 'attribute.name')->toArray(),
                'price' => $variant->price,
                'sale_price' => $variant->sale_price,
                'stock' => $variant->stock,
                'image_url' => $variant->image,
            ];
        })->toArray();

        $relatedProducts = $relatedProductRows
            ->map(fn ($relatedProduct) => $this->decorateCatalogProduct($relatedProduct))
            ->values();

        if ($request->has('ref')) {
            session(['referrer_id' => \App\Models\User::resolveReferrerId($request->query('ref'))]);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
            'related_products' => $relatedProducts,
            'variantData' => $variantData,
            'toppings' => $product->relationLoaded('toppings')
                ? $product->toppings
                    ->filter(fn ($t) => (bool) $t->enabled)
                    ->values()
                    ->map(fn ($topping) => [
                        'id' => $topping->id,
                        'name' => $topping->name,
                        'price' => (float) $topping->price,
                        'sale_price' => $topping->sale_price !== null ? (float) $topping->sale_price : null,
                        'stock' => $topping->stock !== null ? (int) $topping->stock : null,
                        'sku' => $topping->sku,
                        'enabled' => (bool) $topping->enabled,
                    ])
                    ->toArray()
                : [],
        ]);
    }

    public function showProductId($id)
    {
        $productQuery = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->where('products.id', $id)
            ->whereIn('products.status', ['published', 'approved', 'active']);
        $this->applyPublicStoreDebtVisibility($productQuery);
        $product = $productQuery->first();

        if (!$product) {
            return response()->json(['error' => 'Sản phẩm không tồn tại!'], 404);
        }

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'stock' => $product->stock,
            'price' => $product->sale_price,
            'thumbnail' => $product->thumbnail,
        ]);
    }

    private function articleViewCountColumnExists(): bool
    {
        return Schema::hasColumn('articles', 'view_count');
    }

    private function incrementArticleViewCount($article): void
    {
        if (! $article || ! $this->articleViewCountColumnExists()) {
            return;
        }

        DB::table('articles')
            ->where('id', $article->id)
            ->increment('view_count');

        $article->view_count = (int) ($article->view_count ?? 0) + 1;
    }

    private function resolveArticleImagePath(?string $image): ?string
    {
        $path = trim((string) $image);
        if ($path === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path;
    }

    private function articleImageUrl(?string $imagePath): ?string
    {
        if (! $imagePath) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $imagePath)) {
            return $imagePath;
        }

        return '/storage/' . ltrim($imagePath, '/');
    }

    private function countArticleInteractionsForClient(int $articleId, string $column): int
    {
        if (! Schema::hasTable('user_article_interactions')) {
            return 0;
        }

        $key = $articleId . ':' . $column;
        if (! array_key_exists($key, $this->articleInteractionCountCache)) {
            $this->articleInteractionCountCache[$key] = (int) UserArticleInteraction::query()
                ->where('article_id', $articleId)
                ->where($column, true)
                ->count();
        }

        return $this->articleInteractionCountCache[$key];
    }

    private function articleInteractionForUser(int $articleId, ?int $userId): ?UserArticleInteraction
    {
        if (! $userId || ! Schema::hasTable('user_article_interactions')) {
            return null;
        }

        $key = $userId . ':' . $articleId;
        if (! array_key_exists($key, $this->articleInteractionUserCache)) {
            $this->articleInteractionUserCache[$key] = UserArticleInteraction::query()
                ->where('user_id', $userId)
                ->where('article_id', $articleId)
                ->first();
        }

        return $this->articleInteractionUserCache[$key];
    }

    private function isArticleInHotCategory($article): bool
    {
        $category = $article->category ?? null;
        if (! $category) {
            return false;
        }

        $slug = strtolower(trim((string) ($category->slug ?? '')));
        $title = strtolower(trim((string) ($category->title ?? $category->name ?? '')));

        return in_array($slug, ['hot', 'tin-hot'], true)
            || in_array($title, ['hot', 'tin hot'], true);
    }

    private function orderArticlesForClient($query)
    {
        return $query
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->orderByDesc('id');
    }

    private function serializeArticleForClient($article, ?int $userId = null): array
    {
        $article->loadMissing('category');
        $payload = $article->toArray();
        $articleId = (int) $article->id;
        $viewCount = (int) ($article->view_count ?? 0);
        $imagePath = $this->resolveArticleImagePath($article->image ?? null);
        $category = $article->category;
        $interaction = $this->articleInteractionForUser($articleId, $userId);

        $payload['view_count'] = $viewCount;
        $payload['views'] = $viewCount;
        $payload['share_count'] = Schema::hasColumn('articles', 'share_count')
            ? (int) ($article->share_count ?? 0)
            : 0;
        $payload['like_count'] = $this->countArticleInteractionsForClient($articleId, 'is_liked');
        $payload['save_count'] = $this->countArticleInteractionsForClient($articleId, 'is_saved');
        $payload['is_liked'] = (bool) ($interaction?->is_liked ?? false);
        $payload['is_saved'] = (bool) ($interaction?->is_saved ?? false);
        $payload['image'] = $imagePath;
        $payload['image_url'] = $this->articleImageUrl($imagePath);
        $payload['pdf_path'] = $article->pdf_path;
        $payload['pdf_url'] = $article->pdf_path ? $this->articleImageUrl($article->pdf_path) : null;
        $payload['additional_image'] = $article->additional_image;
        $payload['additional_image_url'] = $article->additional_image ? $this->articleImageUrl($article->additional_image) : null;
        $payload['category'] = $category ? [
            'id' => (int) $category->id,
            'title' => $category->title,
            'name' => $category->title,
            'slug' => $category->slug,
        ] : null;
        $payload['is_hot'] = (bool) ($article->is_hot ?? false) || $this->isArticleInHotCategory($article);
        $payload['published_at'] = $article->published_at ? $article->published_at->format('Y-m-d H:i:s') : ($article->created_at ? $article->created_at->format('Y-m-d H:i:s') : null);

        return $payload;
    }

    public function articleTabs()
    {
        $categoryTabs = \App\Models\Category::query()
            ->join('articles', 'articles.category_id', '=', 'categories.id')
            ->select([
                'categories.id',
                'categories.title',
                'categories.slug',
            ])
            ->selectRaw('COUNT(articles.id) as article_count')
            ->groupBy('categories.id', 'categories.title', 'categories.slug')
            ->orderBy('categories.title')
            ->get()
            ->map(function ($category, int $index) {
                return [
                    'id' => 'category:' . $category->id,
                    'label' => $category->title,
                    'type' => 'category',
                    'category_id' => (int) $category->id,
                    'slug' => $category->slug,
                    'article_count' => (int) $category->article_count,
                    'sort_order' => $index + 10,
                ];
            });

        $tabs = collect([
            [
                'id' => 'featured',
                'label' => 'Nổi bật',
                'type' => 'featured',
                'sort_order' => 0,
            ],
            [
                'id' => 'hot',
                'label' => 'Hot',
                'type' => 'hot',
                'sort_order' => 1,
            ],
            [
                'id' => 'latest',
                'label' => 'Mới nhất',
                'type' => 'latest',
                'sort_order' => 2,
            ],
        ])->merge($categoryTabs);

        return response()->json([
            'success' => true,
            'tabs' => $tabs->values(),
        ]);
    }

    public function showArticle(Request $request, $slug)
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        $articleUserId = $user ? (int) $user->id : null;
        $article = \App\Models\article::with('category')->where('slug', $slug)->firstOrFail();
        if ($request->boolean('track_view', true)) {
            $this->incrementArticleViewCount($article);
        }

        $relatedArticles = $this->orderArticlesForClient(
            \App\Models\article::with('category')
                ->where('category_id', $article->category_id)
                ->where('id', '!=', $article->id)
        )
            ->take(3)
            ->get();

        $this->primeArticleInteractions(collect([$article])->concat($relatedArticles), $articleUserId);

        return response()->json([
            'success' => true,
            'article' => $this->serializeArticleForClient($article, $articleUserId),
            'relatedArticles' => $relatedArticles
                ->map(fn ($relatedArticle) => $this->serializeArticleForClient($relatedArticle, $articleUserId))
                ->values(),
        ]);
    }

    public function showArticlesBySection(Request $request, string $displayArea)
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        $articleUserId = $user ? (int) $user->id : null;
        $allowedDisplayAreas = ['all', 'article', 'rules', 'policy'];

        if (!in_array($displayArea, $allowedDisplayAreas, true)) {
            return response()->json(['error' => 'Khu hiển thị không hợp lệ!'], 422);
        }

        $slug = trim((string) $request->query('slug', ''));

        $articleQuery = \App\Models\article::with('category');
        if ($displayArea !== 'article' && $displayArea !== 'all') {
            $articleQuery->where('display_area', $displayArea);
        }

        $article = $slug !== ''
            ? (clone $articleQuery)->where('slug', $slug)->first()
            : null;

        if (!$article) {
            $article = $this->orderArticlesForClient(clone $articleQuery)->first();
        }

        if (!$article) {
            return response()->json([
                'success' => true,
                'article' => null,
                'relatedArticles' => [],
            ]);
        }

        if ($slug !== '' && $article->slug === $slug) {
            $this->incrementArticleViewCount($article);
        }

        $relatedQuery = \App\Models\article::with('category');
        if ($displayArea !== 'article' && $displayArea !== 'all') {
            $relatedQuery->where('display_area', $displayArea);
        }

        $relatedArticles = $this->orderArticlesForClient(
            $relatedQuery->where('id', '!=', $article->id)
        )
            ->take(3)
            ->get();

        $this->primeArticleInteractions(collect([$article])->concat($relatedArticles), $articleUserId);

        return response()->json([
            'success' => true,
            'article' => $this->serializeArticleForClient($article, $articleUserId),
            'relatedArticles' => $relatedArticles
                ->map(fn ($relatedArticle) => $this->serializeArticleForClient($relatedArticle, $articleUserId))
                ->values(),
        ]);
    }

    public function listArticlesBySection(Request $request, string $displayArea)
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        $articleUserId = $user ? (int) $user->id : null;
        $allowedDisplayAreas = ['all', 'article', 'rules', 'policy'];

        if (!in_array($displayArea, $allowedDisplayAreas, true)) {
            return response()->json(['error' => 'Khu hiển thị không hợp lệ!'], 422);
        }

        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = \App\Models\article::with('category');
        if ($displayArea !== 'article' && $displayArea !== 'all') {
            $query->where('display_area', $displayArea);
        }

        $articles = $this->orderArticlesForClient($query)->paginate($perPage);

        $this->primeArticleInteractions($articles->items(), $articleUserId);

        return response()->json([
            'success' => true,
            'displayArea' => $displayArea,
            'articles' => $articles
                ->getCollection()
                ->map(fn ($article) => $this->serializeArticleForClient($article, $articleUserId))
                ->values(),
            'pagination' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
            ],
        ]);
    }

    public function checkStock(Request $request, $productId)
    {
        $product = Product::query()
            ->where('id', $productId)
            ->whereIn('status', ['published', 'approved', 'active'])
            ->first();

        if (!$product) {
            return response()->json(['error' => 'Sản phẩm không tồn tại!'], 404);
        }

        $variantId = $request->query('variant_id');
        $variant = null;

        if ($variantId !== null && $variantId !== '') {
            $variantQuery = ProductVariant::query()
                ->where('id', (int) $variantId)
                ->where('product_id', (int) $product->id);
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantQuery->where('enabled', true);
            }
            $variant = $variantQuery->first();

            if (!$variant) {
                return response()->json(['error' => 'Biến thể sản phẩm không tồn tại!'], 404);
            }
        }

        $availableStock = $variant
            ? max(0, (int) ($variant->stock ?? 0))
            : max(0, (int) ($product->stock ?? 0));

        if ($availableStock < 1) {
            return response()->json([
                'error' => 'Sản phẩm đã hết hàng!',
                'out_of_stock' => true,
                'stock' => 0,
                'variant_id' => $variant ? (int) $variant->id : null,
            ], 400);
        }

        return response()->json([
            'success' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sale_price' => $product->sale_price,
                'thumbnail' => $product->thumbnail,
                'stock' => $availableStock,
                'category_id' => $product->category_id,
                'variant_id' => $variant ? (int) $variant->id : null,
            ],
        ]);
    }

    public function getAllProduct(Request $request)
    {
        $search = trim((string) $request->query('search', $request->query('keyword', $request->query('q', ''))));
        $storeId = trim((string) $request->query('store_id', ''));
        $perPage = max(1, min(100, (int) $request->query('per_page', 100)));
        $debugScore = filter_var($request->query('debug_score', false), FILTER_VALIDATE_BOOLEAN);

        $hasProductRankingScores = Schema::hasTable('product_ranking_scores');
        $hasRankingConfigs = Schema::hasTable('ranking_configs');
        $hasBaseRankingColumn = Schema::hasColumn('products', 'base_ranking_score');
        $hasProductCategories = Schema::hasTable('product_categories');

        // Parse advanced filter inputs
        $categoryId = trim((string) $request->query('category_id', $request->query('categoryId', '')));
        $minPrice = $request->query('min_price', $request->query('minPrice'));
        $maxPrice = $request->query('max_price', $request->query('maxPrice'));
        $onlyDeal = filter_var($request->query('only_deal', $request->query('onlyDeal', false)), FILTER_VALIDATE_BOOLEAN);
        $minDiscount = (int) $request->query('min_discount', $request->query('minDiscount', 0));
        $onlyOpen = filter_var($request->query('only_open', $request->query('onlyOpen', false)), FILTER_VALIDATE_BOOLEAN);
        $radiusKm = $request->query('radius_km', $request->query('radiusKm'));

        $categoryIds = [];
        if ($categoryId !== '' && $hasProductCategories) {
            $categoryIds[] = (int) $categoryId;
            $childrenIds = DB::table('product_categories')->where('parent_id', $categoryId)->pluck('id')->toArray();
            if (!empty($childrenIds)) {
                $categoryIds = array_merge($categoryIds, array_map('intval', $childrenIds));
                $grandChildrenIds = DB::table('product_categories')->whereIn('parent_id', $childrenIds)->pluck('id')->toArray();
                if (!empty($grandChildrenIds)) {
                    $categoryIds = array_merge($categoryIds, array_map('intval', $grandChildrenIds));
                }
            }
        }

        $coordinates = $this->resolveDiscoveryCoordinates($request);

        $query = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->when($hasProductCategories, function ($builder) {
                $builder->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id');
            })
            ->when($hasProductRankingScores, function ($builder) {
                $builder->leftJoin('product_ranking_scores as prs', 'prs.product_id', '=', 'products.id');
            })
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.zalo_link as zalo_link')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->when($hasProductRankingScores || $hasBaseRankingColumn, function ($builder) use ($hasProductRankingScores, $hasBaseRankingColumn) {
                if ($hasProductRankingScores && $hasBaseRankingColumn) {
                    $builder->selectRaw('COALESCE(prs.base_ranking_score, products.base_ranking_score, 0) as base_ranking_score');
                    return;
                }

                if ($hasProductRankingScores) {
                    $builder->selectRaw('COALESCE(prs.base_ranking_score, 0) as base_ranking_score');
                    return;
                }

                if ($hasBaseRankingColumn) {
                    $builder->selectRaw('COALESCE(products.base_ranking_score, 0) as base_ranking_score');
                }
            })
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews')
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->when(Schema::hasColumn('stores', 'status'), function ($builder) {
                $builder->whereIn('stores.status', ['active']);
            })
            ->when(Schema::hasColumn('stores', 'is_debt_locked'), function ($builder) {
                $builder->where(function ($visibilityQuery) {
                    $visibilityQuery->whereNull('stores.is_debt_locked')
                        ->orWhere('stores.is_debt_locked', false);
                });
            })
            ->when(
                Schema::hasTable('store_menu')
                    && Schema::hasColumn('store_menu', 'store_id')
                    && Schema::hasColumn('store_menu', 'product_id')
                    && Schema::hasColumn('store_menu', 'is_available'),
                function ($builder) {
                    $builder->where(function ($availabilityQuery) {
                        $availabilityQuery
                            ->whereNotExists(function ($menuQuery) {
                                $menuQuery->select(DB::raw(1))
                                    ->from('store_menu')
                                    ->whereColumn('store_menu.product_id', 'products.id')
                                    ->whereColumn('store_menu.store_id', 'products.store_id');
                            })
                            ->orWhereExists(function ($menuQuery) {
                                $menuQuery->select(DB::raw(1))
                                    ->from('store_menu')
                                    ->whereColumn('store_menu.product_id', 'products.id')
                                    ->whereColumn('store_menu.store_id', 'products.store_id')
                                    ->where('store_menu.is_available', true);
                            });
                    });
                }
            )
            ->when($search !== '', function ($builder) use ($search, $hasProductCategories) {
                $builder->where(function ($subQuery) use ($search, $hasProductCategories) {
                    $subQuery->where('products.name', 'like', '%' . $search . '%')
                        ->orWhere('products.slug', 'like', '%' . $search . '%');

                    if (Schema::hasColumn('products', 'description')) {
                        $subQuery->orWhere('products.description', 'like', '%' . $search . '%');
                    }

                    $subQuery->orWhere('stores.name', 'like', '%' . $search . '%');

                    if ($hasProductCategories) {
                        $subQuery->orWhere('product_categories.name', 'like', '%' . $search . '%');
                    }
                });
            })
            ->when($storeId !== '', function ($builder) use ($storeId) {
                $builder->where('products.store_id', $storeId);
            })
            ->when(!empty($categoryIds), function ($builder) use ($categoryIds) {
                $builder->whereIn('products.category_id', $categoryIds);
            })
            ->when($minPrice !== null && $minPrice !== '', function ($builder) use ($minPrice) {
                $builder->whereRaw('CASE WHEN products.sale_price > 0 THEN products.sale_price ELSE products.price END >= ?', [(float) $minPrice]);
            })
            ->when($maxPrice !== null && $maxPrice !== '', function ($builder) use ($maxPrice) {
                $builder->whereRaw('CASE WHEN products.sale_price > 0 THEN products.sale_price ELSE products.price END <= ?', [(float) $maxPrice]);
            })
            ->when($onlyDeal, function ($builder) {
                $builder->where('products.sale_price', '>', 0)
                    ->whereColumn('products.sale_price', '<', 'products.price');
            })
            ->when($minDiscount > 0, function ($builder) use ($minDiscount) {
                $builder->where('products.sale_price', '>', 0)
                    ->whereColumn('products.sale_price', '<', 'products.price')
                    ->whereRaw('(products.price - products.sale_price) / products.price * 100 >= ?', [$minDiscount]);
            })
            ->when($onlyOpen, function ($builder) {
                $builder->where('stores.is_open', true);
            })
            ->when($search === '' && $coordinates !== null && is_numeric($radiusKm) && (float)$radiusKm > 0 && Schema::hasColumn('stores', 'location'), function ($builder) use ($coordinates, $radiusKm) {
                $builder->whereRaw('ST_Distance_Sphere(stores.location, POINT(?, ?)) / 1000 <= ?', [
                    (float) $coordinates['lng'],
                    (float) $coordinates['lat'],
                    (float) $radiusKm
                ]);
            });

        if ($search !== '' && $hasRankingConfigs) {
            $weights = $this->resolveRankingWeights('search');
            $keywordBindings = [];
            $categoryBindings = [];
            $distanceBindings = [];
            $distanceKmBindings = [];
            $radiusKm = max(1.0, min(50.0, (float) $request->query('radius_km', 10)));
            $coordinates = $this->resolveDiscoveryCoordinates($request);

            $keywordScoreExpr = $this->buildKeywordScoreExpression($search, $keywordBindings);
            $categoryScoreExpr = $this->buildCategoryScoreExpression($search, $categoryBindings, $hasProductCategories);
            $distanceScoreExpr = $this->buildDistanceScoreExpression($coordinates, $radiusKm, $distanceBindings);

            $distanceKmExpr = 'NULL';
            if ($coordinates !== null && Schema::hasColumn('stores', 'location')) {
                $distanceKmBindings[] = (float) $coordinates['lng'];
                $distanceKmBindings[] = (float) $coordinates['lat'];
                $distanceKmExpr = 'ST_Distance_Sphere(stores.location, POINT(?, ?)) / 1000';
            }

            $personalScoreExpr = '0';
            $user = $request->user();

            if ($user && Schema::hasTable('order_items') && Schema::hasTable('orders')) {
                $historyQuery = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->join('products as hp', 'hp.id', '=', 'oi.product_id')
                    ->select('hp.category_id', DB::raw('COUNT(*) as cnt'))
                    ->where('o.user_id', $user->id)
                    ->whereNotNull('hp.category_id')
                    ->groupBy('hp.category_id')
                    ->orderByDesc('cnt')
                    ->limit(3);

                if (Schema::hasColumn('orders', 'status')) {
                    $historyQuery->whereIn('o.status', ['delivered', 'completed']);
                }

                if (Schema::hasColumn('orders', 'created_at')) {
                    $historyQuery->where('o.created_at', '>=', now()->subDays(90));
                }

                $query->leftJoinSub($historyQuery, 'user_pref', function ($join) {
                    $join->on('user_pref.category_id', '=', 'products.category_id');
                });

                $personalScoreExpr = 'CASE WHEN user_pref.category_id IS NULL THEN 0 ELSE 100 END';
            }

            $baseScoreExpr = '0';
            if ($hasProductRankingScores && $hasBaseRankingColumn) {
                $baseScoreExpr = 'COALESCE(prs.base_ranking_score, products.base_ranking_score, 0)';
            } elseif ($hasProductRankingScores) {
                $baseScoreExpr = 'COALESCE(prs.base_ranking_score, 0)';
            } elseif ($hasBaseRankingColumn) {
                $baseScoreExpr = 'COALESCE(products.base_ranking_score, 0)';
            }

            $totalScoreExpr = $baseScoreExpr
                . ' + (' . $keywordScoreExpr . ') * ?'
                . ' + (' . $distanceScoreExpr . ') * ?'
                . ' + (' . $personalScoreExpr . ') * ?';

            $scoreBindings = array_merge(
                $keywordBindings,
                [$weights['keyword']],
                $distanceBindings,
                [$weights['distance'], $weights['history']]
            );

            $query->orderByRaw($totalScoreExpr . ' DESC', $scoreBindings)
                ->orderByDesc('products.created_at')
                ->orderByDesc('products.id');

            if ($debugScore) {
                $query->selectRaw($keywordScoreExpr . ' as keyword_score', $keywordBindings)
                    ->selectRaw($categoryScoreExpr . ' as category_score', $categoryBindings)
                    ->selectRaw($distanceScoreExpr . ' as distance_score', $distanceBindings)
                    ->selectRaw($personalScoreExpr . ' as history_score')
                    ->selectRaw($totalScoreExpr . ' as total_score', $scoreBindings)
                    ->selectRaw($distanceKmExpr . ' as distance_km', $distanceKmBindings);

                if ($hasProductRankingScores) {
                    $query->selectRaw('COALESCE(prs.commission_score, 0) as commission_score')
                        ->selectRaw('COALESCE(prs.lucky_ticket_score, 0) as lucky_ticket_score')
                        ->selectRaw('COALESCE(prs.purchase_score, 0) as order_score')
                        ->selectRaw('COALESCE(prs.rating_score, 0) as rating_score')
                        ->selectRaw('COALESCE(prs.reputation_score, 0) as seller_reputation_score')
                        ->selectRaw('COALESCE(prs.risk_penalty_score, 0) as penalty_score')
                        ->selectRaw('COALESCE(prs.purchase_qty, 0) as order_count')
                        ->selectRaw('COALESCE(prs.rating_value, 0) as rating_value');
                } else {
                    $query->selectRaw('0 as commission_score')
                        ->selectRaw('0 as lucky_ticket_score')
                        ->selectRaw('0 as order_score')
                        ->selectRaw('0 as rating_score')
                        ->selectRaw('0 as seller_reputation_score')
                        ->selectRaw('0 as penalty_score')
                        ->selectRaw('0 as order_count')
                        ->selectRaw('0 as rating_value');
                }
            }
        } else {
            $query->orderByDesc('products.created_at');
        }

        $products = $query->paginate($perPage);
        $this->primeFavoriteProductLookup($request, $products->items());
        $productItems = collect($products->items())
            ->map(fn ($product) => $this->decorateCatalogProduct($product))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $productItems,
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function showCategoryById($id, Request $request)
    {
        $query = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->where('products.category_id', $id)
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct', 'reviews');
        $this->applyPublicStoreDebtVisibility($query);
        $products = $query->limit(100)->get();

        if ($products->isEmpty()) {
            return response()->json(['error' => 'Không có sản phẩm nào trong danh mục này!'], 404);
        }

        $this->primeFavoriteProductLookup($request, $products);

        return response()->json(
            $products->map(fn ($product) => $this->decorateCatalogProduct($product))->values()
        );
    }

    public function getAllCategory()
    {
        $categories = ProductCategory::all();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function recommended(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $productRows = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($subQuery) use ($keyword) {
                    $subQuery->where('products.name', 'like', "%{$keyword}%")
                        ->orWhere('products.slug', 'like', "%{$keyword}%");
                });
            })
            ->with('reviews')
            ->whereIn('products.status', ['published', 'approved', 'active'])
            ->orderByDesc('products.created_at')
            ->limit(20);
        $this->applyPublicStoreDebtVisibility($productRows);
        $productRows = $productRows->get();

        $this->primeFavoriteProductLookup($request, $productRows);

        $products = $productRows
            ->map(function ($product) {
                $ratingData = $this->calculateProductRating($product);
                $product->setAttribute('average_rating', $ratingData['average_rating']);
                $product->setAttribute('review_count', $ratingData['review_count']);
                $product->setAttribute('rating_breakdown', $ratingData['rating_breakdown']);
                $this->applyFavoriteStateToCatalogProduct($product);
                return $product;
            });

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    public function stations()
    {
        try {
            $storesQuery = Store::query()
                ->select([
                    'id',
                    'name',
                    'address',
                    'logo_url',
                    Schema::hasColumn('stores', 'cover_image') ? 'cover_image' : DB::raw('NULL as cover_image'),
                    Schema::hasColumn('stores', 'banner_url') ? 'banner_url' : DB::raw('NULL as banner_url'),
                    Schema::hasColumn('stores', 'banner_urls') ? 'banner_urls' : DB::raw('NULL as banner_urls'),
                    Schema::hasColumn('stores', 'rating_avg') ? 'rating_avg' : DB::raw('NULL as rating_avg'),
                    Schema::hasColumn('stores', 'rating_count') ? 'rating_count' : DB::raw('NULL as rating_count'),
                    'is_open',
                    'status',
                ])
                ->selectRaw('ST_Y(location) as lat')
                ->selectRaw('ST_X(location) as lng');
            $this->applyPublicStoreDebtVisibility($storesQuery);
            $stores = $storesQuery
                ->orderByDesc('is_open')
                ->limit(50)
                ->get();
            $ratingScoreMap = $this->resolveStoreRatingScoreMap($stores->pluck('id'));

            $stores = $stores->map(function ($store) use ($ratingScoreMap) {
                    $ratingPayload = $this->resolveStoreRatingPayload(
                        (string) $store->id,
                        $store->rating_avg ?? null,
                        $store->rating_count ?? 0,
                        $ratingScoreMap
                    );

                    return array_merge([
                        'id' => (string) $store->id,
                        'name' => (string) $store->name,
                        'address' => (string) $store->address,
                        'lat' => is_null($store->lat) ? null : (float) $store->lat,
                        'lng' => is_null($store->lng) ? null : (float) $store->lng,
                        'is_open' => (bool) $store->is_open,
                        'status' => (string) $store->status,
                    ], $ratingPayload, $this->buildStoreMediaPayload(
                        $store->logo_url ?? null,
                        $store->cover_image ?? null,
                        $store->banner_url ?? null,
                        $store->banner_urls ?? null,
                    ));
                })
                ->values();

            return response()->json($stores);
        } catch (\Throwable $e) {
            return response()->json([]);
        }
    }

    public function storeMenu(string $storeId, Request $request)
    {
        $storeQuery = Store::query()->where('id', $storeId);
        $this->applyPublicStoreDebtVisibility($storeQuery);
        $store = $storeQuery->first();

        if (! $store) {
            return response()->json([
                'message' => 'Không tìm thấy cửa hàng.',
            ], 404);
        }

        $keyword = trim((string) $request->query('q', ''));

        $itemsQuery = StoreMenu::query()
            ->where('store_id', $storeId)
            ->where('is_available', true)
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($subQuery) use ($keyword) {
                    $subQuery->where('item_name', 'like', '%' . $keyword . '%')
                        ->orWhere('description', 'like', '%' . $keyword . '%')
                        ->orWhere('category', 'like', '%' . $keyword . '%');
                });
            });

        $this->applyPurchasableMenuItemScope($itemsQuery);

        $items = $itemsQuery
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('item_name')
            ->get([
                'id',
                'item_code',
                'item_name',
                'description',
                'product_id',
                'variant_id',
                'price',
                'original_price',
                'category',
                'image_url',
                'is_available',
                'is_featured',
            ]);

        return response()->json([
            'store' => array_merge([
                'id' => (string) $store->id,
                'name' => (string) $store->name,
                'address' => (string) $store->address,
                'is_open' => (bool) $store->is_open,
            ], $this->buildStoreMediaPayload(
                $store->logo_url ?? null,
                $store->cover_image ?? null,
                $store->banner_url ?? null,
                $store->banner_urls ?? null,
            )),
            'items' => $items,
        ]);
    }

    public function storeDetail(string $storeId, Request $request)
    {
        $storeSelects = [
            's.id',
            's.name',
            's.logo_url',
            's.address',
            's.is_open',
            's.status',
            's.open_time',
            's.close_time',
        ];

        $optionalStoreColumns = [
            'slug',
            'description',
            'phone',
            'email',
            'cover_image',
            'banner_url',
            'banner_urls',
            'slogan',
            'brand_color',
            'zalo_link',
            'facebook_link',
            'rating_avg',
            'rating_count',
            'min_order_tickets',
            'max_order_tickets',
        ];

        foreach ($optionalStoreColumns as $column) {
            if (Schema::hasColumn('stores', $column)) {
                $storeSelects[] = 's.' . $column;
            } else {
                $storeSelects[] = DB::raw('NULL as ' . $column);
            }
        }

        if (Schema::hasColumn('stores', 'location')) {
            $storeSelects[] = DB::raw('ST_Y(s.location) as latitude');
            $storeSelects[] = DB::raw('ST_X(s.location) as longitude');
        } else {
            $storeSelects[] = DB::raw('NULL as latitude');
            $storeSelects[] = DB::raw('NULL as longitude');
        }

        $query = DB::table('stores as s')
            ->select($storeSelects)
            ->where(function ($storeQuery) use ($storeId) {
                $storeQuery->where('s.id', $storeId);

                if (Schema::hasColumn('stores', 'slug')) {
                    $storeQuery->orWhere('s.slug', $storeId);
                }
            });
        $this->applyPublicStoreDebtVisibility($query, 's');

        $hasSaleId = Schema::hasColumn('stores', 'sale_id');
        $hasUsersTable = Schema::hasTable('users');

        if ($hasSaleId && $hasUsersTable) {
            $query->leftJoin('users as sale', 'sale.id', '=', 's.sale_id');

            $saleNameExpr = "''";
            $hasSaleFullName = Schema::hasColumn('users', 'full_name');
            $hasSaleName = Schema::hasColumn('users', 'name');
            if ($hasSaleFullName && $hasSaleName) {
                $saleNameExpr = "COALESCE(NULLIF(sale.full_name, ''), NULLIF(sale.name, ''), '')";
            } elseif ($hasSaleFullName) {
                $saleNameExpr = "COALESCE(NULLIF(sale.full_name, ''), '')";
            } elseif ($hasSaleName) {
                $saleNameExpr = "COALESCE(NULLIF(sale.name, ''), '')";
            }

            $query->addSelect([
                DB::raw($saleNameExpr . ' as sale_full_name'),
                DB::raw(Schema::hasColumn('users', 'phone') ? 'sale.phone as sale_phone' : 'NULL as sale_phone'),
                DB::raw(Schema::hasColumn('users', 'zalo_id') ? 'sale.zalo_id as sale_zalo_id' : 'NULL as sale_zalo_id'),
                DB::raw(Schema::hasColumn('users', 'email') ? 'sale.email as sale_email' : 'NULL as sale_email'),
            ]);
        } else {
            $query->addSelect([
                DB::raw("NULL as sale_full_name"),
                DB::raw("NULL as sale_phone"),
                DB::raw("NULL as sale_zalo_id"),
                DB::raw("NULL as sale_email"),
            ]);
        }

        $store = $query->first();

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cửa hàng.',
            ], 404);
        }

        $ratingScoreMap = $this->resolveStoreRatingScoreMap([(string) $store->id]);
        $ratingPayload = $this->resolveStoreRatingPayload(
            (string) $store->id,
            $store->rating_avg ?? null,
            $store->rating_count ?? 0,
            $ratingScoreMap
        );
        $followersCount = $this->countStoreFollowers((string) $store->id);

        $menuSelects = [
            'id',
            'item_name',
            'price',
            'image_url',
        ];

        $menuOptionalColumns = [
            'description',
            'original_price',
            'is_lucky_item',
            'category',
            'sub_category',
            'is_featured',
        ];

        foreach ($menuOptionalColumns as $column) {
            if (Schema::hasColumn('store_menu', $column)) {
                $menuSelects[] = $column;
            } else {
                $menuSelects[] = DB::raw('NULL as ' . $column);
            }
        }

        $menuQuery = StoreMenu::query()
            ->where('store_id', $store->id)
            ->where('is_available', true)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('item_name');

        $usePagination = $request->has('page') || $request->has('per_page');
        $productsMeta = null;

        if ($usePagination) {
            $perPage = max(1, min(200, (int) $request->query('per_page', 50)));
            $page = max(1, (int) $request->query('page', 1));
            $productsPaginator = $menuQuery->paginate($perPage, $menuSelects, 'page', $page);
            $productsCollection = collect($productsPaginator->items());
            $productsMeta = [
                'current_page' => $productsPaginator->currentPage(),
                'per_page' => $productsPaginator->perPage(),
                'total' => $productsPaginator->total(),
                'last_page' => $productsPaginator->lastPage(),
            ];
        } else {
            $productsCollection = $menuQuery->get($menuSelects);
        }

        $products = $productsCollection
            ->map(function ($menu) {
                return [
                    'name' => (string) $menu->item_name,
                    'price' => (float) $menu->price,
                    'image_url' => $menu->image_url,
                    'description' => $menu->description ?? null,
                    'original_price' => $menu->original_price !== null ? (float) $menu->original_price : null,
                    'is_lucky_item' => $menu->is_lucky_item !== null ? (bool) $menu->is_lucky_item : false,
                    'category' => $menu->category ?? null,
                    'sub_category' => $menu->sub_category ?? null,
                    'is_featured' => $menu->is_featured !== null ? (bool) $menu->is_featured : false,
                ];
            })
            ->values()
            ->toArray();
        $categories = $this->resolvePublicStoreCategories((string) $store->id);

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug ?? null,
                'brand_color' => $store->brand_color ?? null,
                'slogan' => $store->slogan ?? null,
                'description' => $store->description ?? null,
                'phone' => $store->phone ?? null,
                'email' => $store->email ?? null,
                'address' => $store->address,
                'latitude' => $store->latitude !== null ? (float) $store->latitude : 10.7769,
                'longitude' => $store->longitude !== null ? (float) $store->longitude : 106.6906,
                'is_open' => (bool) $store->is_open,
                'status' => $store->is_open ? 'open' : 'closed',
                'is_operating' => (bool) ($store->is_operating ?? false),
                'min_order_tickets' => $store->min_order_tickets !== null ? (int) $store->min_order_tickets : null,
                'max_order_tickets' => $store->max_order_tickets !== null ? (int) $store->max_order_tickets : null,
                'open_time' => $store->open_time ?? '08:00:00',
                'close_time' => $store->close_time ?? '22:00:00',
                'sale_contact' => ($store->sale_full_name || $store->sale_phone || $store->sale_zalo_id || $store->sale_email)
                    ? [
                        'full_name' => $store->sale_full_name ?: null,
                        'phone' => $store->sale_phone ?: null,
                        'zalo_id' => $store->sale_zalo_id ?: null,
                        'email' => $store->sale_email ?: null,
                    ]
                    : null,
                'zalo_link' => $store->zalo_link ?? null,
                'facebook_link' => $store->facebook_link ?? null,
                'followers_count' => $followersCount,
                'followersCount' => $followersCount,
                'products' => $products,
                'categories' => $categories,
            ], $ratingPayload, $this->buildStoreMediaPayload(
                $store->logo_url ?? null,
                $store->cover_image ?? null,
                $store->banner_url ?? null,
                $store->banner_urls ?? null,
            )),
            'meta' => [
                'source' => 'database',
                'products_meta' => $productsMeta,
            ],
        ]);
    }

    private function resolvePublicStoreCategories(string $storeId): array
    {
        if (
            ! Schema::hasTable('product_categories') ||
            ! Schema::hasColumn('product_categories', 'store_id')
        ) {
            return [];
        }

        $selects = ['id', 'name', 'store_id'];
        foreach (['slug', 'parent_id', 'image', 'sort_order', 'level'] as $column) {
            if (Schema::hasColumn('product_categories', $column)) {
                $selects[] = $column;
            }
        }

        $categories = DB::table('product_categories')
            ->select($selects)
            ->where('store_id', $storeId)
            ->when(Schema::hasColumn('product_categories', 'status'), function ($query) {
                $query->where(function ($statusQuery) {
                    $statusQuery->whereNull('status')
                        ->orWhereIn('status', ['active', 'published', 'approved']);
                });
            })
            ->orderBy(Schema::hasColumn('product_categories', 'sort_order') ? 'sort_order' : 'name')
            ->orderBy('name')
            ->get();

        if ($categories->isEmpty()) {
            return [];
        }

        $productCounts = collect();
        if (
            Schema::hasTable('products') &&
            Schema::hasColumn('products', 'store_id') &&
            Schema::hasColumn('products', 'category_id')
        ) {
            $productCounts = DB::table('products')
                ->select('category_id', DB::raw('COUNT(*) as product_count'))
                ->where('store_id', $storeId)
                ->whereNotNull('category_id')
                ->when(Schema::hasColumn('products', 'status'), function ($query) {
                    $query->whereIn('status', ['published', 'approved', 'active']);
                })
                ->groupBy('category_id')
                ->pluck('product_count', 'category_id');
        }

        return $categories
            ->map(function ($category) use ($productCounts) {
                $categoryId = (int) $category->id;

                return [
                    'id' => $categoryId,
                    'category_id' => $categoryId,
                    'name' => (string) $category->name,
                    'slug' => property_exists($category, 'slug') ? $category->slug : null,
                    'parent_id' => property_exists($category, 'parent_id') && $category->parent_id !== null
                        ? (int) $category->parent_id
                        : null,
                    'image' => property_exists($category, 'image') ? $category->image : null,
                    'sort_order' => property_exists($category, 'sort_order') ? (int) ($category->sort_order ?? 0) : 0,
                    'level' => property_exists($category, 'level') ? (int) ($category->level ?? 1) : null,
                    'store_id' => $category->store_id,
                    'product_count' => (int) ($productCounts->get($category->id) ?? 0),
                    'productCount' => (int) ($productCounts->get($category->id) ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    public function refreshCart(Request $request)
    {
        $cart = $request->input('cart', []);
        $refreshed = [];

        $productIds = collect($cart)
            ->pluck('id')
            ->filter(static fn ($productId): bool => is_numeric($productId))
            ->map(static fn ($productId): int => (int) $productId)
            ->unique()
            ->values();

        $productsQuery = Product::query()
            ->whereIn('id', $productIds)
            ->whereIn('status', ['published', 'approved', 'active']);
        $this->applyPublicProductStoreVisibilityScope($productsQuery);
        $productsById = $productsQuery
            ->get()
            ->keyBy('id');

        $variantsByProductQuery = ProductVariant::query()
            ->whereIn('product_id', $productIds);
        if (Schema::hasColumn('product_variants', 'enabled')) {
            $variantsByProductQuery->where('enabled', true);
        }
        $variantsByProductId = $variantsByProductQuery
            ->get()
            ->groupBy('product_id');

        $variantIds = collect($cart)
            ->pluck('variant_id')
            ->filter(static fn ($variantId): bool => is_numeric($variantId))
            ->map(static fn ($variantId): int => (int) $variantId)
            ->unique()
            ->values();

        $variantsByIdQuery = ProductVariant::query()
            ->whereIn('id', $variantIds);
        if (Schema::hasColumn('product_variants', 'enabled')) {
            $variantsByIdQuery->where('enabled', true);
        }
        $variantsById = $variantsByIdQuery
            ->get()
            ->keyBy('id');

        foreach ($cart as $item) {
            $productId = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
            $product = $productId ? $productsById->get($productId) : null;
            if ($product) {
                $productVariants = $variantsByProductId->get((int) $product->id, collect());
                $variantId = isset($item['variant_id']) && is_numeric($item['variant_id'])
                    ? (int) $item['variant_id']
                    : null;

                /** @var ProductVariant|null $variant */
                $variant = null;

                if ($variantId) {
                    $candidateVariant = $variantsById->get($variantId);
                    if ($candidateVariant && (int) $candidateVariant->product_id === (int) $product->id) {
                        $variant = $candidateVariant;
                    } else {
                        continue;
                    }
                } else {
                    if ($productVariants->count() > 1) {
                        continue;
                    }

                    if ($productVariants->count() === 1) {
                        $variant = $productVariants->first();
                        $variantId = (int) $variant->id;
                    }
                }

                $productPrice = $product->price !== null ? (float) $product->price : null;
                $productSalePrice = $product->sale_price !== null ? (float) $product->sale_price : null;
                $variantPrice = $variant && $variant->price !== null ? (float) $variant->price : null;
                $variantSalePrice = $variant && $variant->sale_price !== null ? (float) $variant->sale_price : null;

                $effectivePrice = null;

                if ($variant !== null) {
                    if ($variantSalePrice !== null && $variantSalePrice > 0) {
                        $effectivePrice = $variantSalePrice;
                    } elseif ($variantPrice !== null && $variantPrice > 0) {
                        $effectivePrice = $variantPrice;
                    } else {
                        continue;
                    }
                } else {
                    if ($productSalePrice !== null && $productSalePrice > 0) {
                        $effectivePrice = $productSalePrice;
                    } elseif ($productPrice !== null && $productPrice > 0) {
                        $effectivePrice = $productPrice;
                    } else {
                        continue;
                    }
                }

                $availableStock = $variant !== null
                    ? max(0, (int) ($variant->stock ?? 0))
                    : max(0, (int) ($product->stock ?? 0));

                if ($availableStock <= 0) {
                    continue;
                }

                $requestedQuantity = isset($item['quantity']) && is_numeric($item['quantity'])
                    ? max(1, (int) $item['quantity'])
                    : 1;

                $normalizedQuantity = min($requestedQuantity, $availableStock, 100);

                $refreshed[] = [
                    'id' => $product->id,
                    'variant_id' => $variantId,
                    'name' => $product->name,
                    'image' => $product->thumbnail,
                    'price' => $effectivePrice,
                    'effective_price' => $effectivePrice,
                    'product_price' => $productPrice,
                    'product_sale_price' => $productSalePrice,
                    'variant_price' => $variantPrice,
                    'variant_sale_price' => $variantSalePrice,
                    'stock' => $availableStock,
                    'category_id' => $product->category_id,
                    'quantity' => $normalizedQuantity,
                    'ticket_reward_quantity' => $variant !== null ? (int) ($variant->ticket_reward_quantity ?? 0) : (int) ($product->ticket_reward_quantity ?? 0),
                ];
            }
        }

        return response()->json($refreshed);
    }

    public function imageProxy(Request $request)
    {
        $url = $request->query('url');
        if (!$url || !is_string($url)) {
            return response('Invalid URL', 400);
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            return response('Invalid URL', 400);
        }

        $scheme = strtolower($parsedUrl['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return response('Invalid scheme', 400);
        }

        $host = strtolower($parsedUrl['host']);
        
        // Prevent local/private address resolution in host name
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]') {
            return response('Restricted host', 403);
        }

        $ip = gethostbyname($host);
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return response('Cannot resolve host', 400);
        }

        $isPrivate = !filter_var(
            $ip, 
            FILTER_VALIDATE_IP, 
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($isPrivate) {
            return response('Restricted IP', 403);
        }

        $port = isset($parsedUrl['port']) ? (int) $parsedUrl['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            return response('Restricted port', 403);
        }

        try {
            $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '/';
            $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
            $requestUrl = $scheme . '://' . $ip . ($port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '') . $path . $query;

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Host' => $parsedUrl['host']
            ])->withoutVerifying()->timeout(5)->get($requestUrl);

            if ($response->successful()) {
                return response($response->body())
                    ->header('Content-Type', $response->header('Content-Type') ?: 'image/jpeg')
                    ->header('Access-Control-Allow-Origin', '*');
            }
        } catch (\Throwable $e) {
        }

        return response('Failed to fetch image', 500);
    }

    /**
     * Get all stores with their information, top 3 products, and operating hours.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllStoresWithProducts()
    {
        try {
            $storesQuery = Store::query()->select(
                    'id',
                    'name',
                    'logo_url',
                    Schema::hasColumn('stores', 'cover_image') ? 'cover_image' : DB::raw('NULL as cover_image'),
                    Schema::hasColumn('stores', 'banner_url') ? 'banner_url' : DB::raw('NULL as banner_url'),
                    Schema::hasColumn('stores', 'banner_urls') ? 'banner_urls' : DB::raw('NULL as banner_urls'),
                    Schema::hasColumn('stores', 'rating_avg') ? 'rating_avg' : DB::raw('NULL as rating_avg'),
                    Schema::hasColumn('stores', 'rating_count') ? 'rating_count' : DB::raw('NULL as rating_count'),
                    'location',
                    'is_open',
                    'address',
                    'open_time',
                    'close_time',
                    'status',
                )
                ->selectRaw('ST_Y(location) as latitude')
                ->selectRaw('ST_X(location) as longitude')
                ->with([
                    'products' => function ($query) {
                        $query->whereIn('status', ['published', 'approved', 'active'])
                            ->with('reviews')
                            ->select('id', 'store_id', 'name', 'price', 'sale_price', 'thumbnail', 'slug');
                    },
                    'menus' => function ($query) {
                        $query->where('is_available', true)
                            ->select('id', 'store_id', 'item_name', 'price', 'image_url');
                    },
                ]);
            $this->applyPublicStoreDebtVisibility($storesQuery);
            $stores = $storesQuery
                ->limit(50)
                ->get();
            $ratingScoreMap = $this->resolveStoreRatingScoreMap($stores->pluck('id'));

            $stores = $stores->map(function ($store) use ($ratingScoreMap) {
                    $ratingPayload = $this->resolveStoreRatingPayload(
                        (string) $store->id,
                        $store->rating_avg ?? null,
                        $store->rating_count ?? 0,
                        $ratingScoreMap
                    );

                    return array_merge([
                        'id' => $store->id,
                        'name' => $store->name,
                        'latitude' => (float) ($store->latitude ?? 10.7769),
                        'longitude' => (float) ($store->longitude ?? 106.6906),
                        'is_open' => (bool) $store->is_open,
                        'status' => $store->is_open ? 'open' : 'closed',
                        'is_operating' => (bool) ($store->is_operating ?? false),
                        'address' => $store->address ?? 'TP.HCM',
                        'open_time' => $store->open_time ?? '08:00:00',
                        'close_time' => $store->close_time ?? '22:00:00',
                        'products' => $this->buildStorePreviewItems($store),
                    ], $ratingPayload, $this->buildStoreMediaPayload(
                        $store->logo_url ?? null,
                        $store->cover_image ?? null,
                        $store->banner_url ?? null,
                        $store->banner_urls ?? null,
                    ));
                });

            return response()->json([
                'success' => true,
                'data' => $stores,
                'meta' => [
                    'total' => $stores->count(),
                    'source' => 'database',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load stores from database.',
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'source' => 'database',
                ],
            ], 500);
        }
    }

    public function customerFlashSaleProducts(Request $request)
    {
        if (!Schema::hasTable('flash_sales') || !Schema::hasTable('flash_sale_items')) {
            return response()->json([
                'data' => [
                    'items' => [],
                ],
                'meta' => [
                    'page' => 1,
                    'page_size' => 12,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'timezone' => 'Asia/Ho_Chi_Minh',
                    'request_at' => now('Asia/Ho_Chi_Minh')->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $page = max(1, (int) ($validated['page'] ?? 1));
        $pageSize = (int) ($validated['page_size'] ?? $validated['limit'] ?? 12);
        $now = now('Asia/Ho_Chi_Minh');

        $baseQuery = DB::table('flash_sale_items as fsi')
            ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
            ->join('products as p', 'p.id', '=', 'fsi.product_id')
            ->join('stores as s', 's.id', '=', 'fs.store_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'fsi.variant_id')
            ->where('fs.is_active', true)
            ->where('fs.end_time', '>=', $now)
            ->whereRaw('COALESCE(fsi.sold_quantity, 0) < COALESCE(fsi.sale_quantity, 0)')
            ->whereIn('p.status', ['published', 'approved', 'active'])
            ->where('s.status', 'active')
            ->when(Schema::hasColumn('stores', 'is_debt_locked'), function ($builder) {
                $builder->where(function ($visibilityQuery) {
                    $visibilityQuery->whereNull('s.is_debt_locked')
                        ->orWhere('s.is_debt_locked', false);
                });
            })
            ->select([
                'fsi.id as flash_sale_item_id',
                'fs.id as flash_sale_id',
                'fs.name as flash_sale_name',
                'fs.start_time as flash_sale_start_time',
                'fs.end_time as flash_sale_end_time',
                'fs.is_active as flash_sale_is_active',
                'fsi.product_id',
                'fsi.variant_id',
                'fsi.flash_sale_price',
                'fsi.sale_quantity',
                'fsi.sold_quantity',
                'p.name as product_name',
                'p.slug as product_slug',
                'p.thumbnail as product_thumbnail',
                'p.price as product_price',
                'p.sale_price as product_sale_price',
                'p.store_id',
                's.name as store_name',
                's.logo_url as store_logo',
                's.status as store_status',
                'pv.price as variant_price',
                'pv.sale_price as variant_sale_price',
                'pv.image as variant_image',
                'fsi.per_user_limit',
            ])
            ->orderBy('fs.end_time')
            ->orderByDesc('fsi.sold_quantity')
            ->orderByDesc('fsi.sale_quantity');

        $paginator = $baseQuery->paginate($pageSize, ['*'], 'page', $page);
        $productIds = collect($paginator->items())
            ->pluck('product_id')
            ->filter(static fn ($value): bool => $value !== null)
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $variantsByProductId = collect();
        if ($productIds->isNotEmpty()) {
            $flashVariantQuery = ProductVariant::query()
                ->with(['attributeValues.attribute'])
                ->whereIn('product_id', $productIds->all());
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $flashVariantQuery->where('enabled', true);
            }
            $variantsByProductId = $flashVariantQuery
                ->get()
                ->groupBy(static fn (ProductVariant $variant): int => (int) $variant->product_id);
        }

        $user = $request->user();

        $flashSaleIds = collect($paginator->items())->pluck('flash_sale_item_id')->filter()->all();
        $userUsed = [];
        if ($user && !empty($flashSaleIds)) {
            $userUsed = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.user_id', $user->id)
                ->whereIn('order_items.flash_sale_item_id', $flashSaleIds)
                ->whereNotIn('orders.status', ['cancelled', 'canceled'])
                ->select('order_items.flash_sale_item_id', DB::raw('SUM(order_items.quantity) as qty'))
                ->groupBy('order_items.flash_sale_item_id')
                ->pluck('qty', 'flash_sale_item_id')
                ->all();
        }

        $items = collect($paginator->items())
            ->map(function ($row) use ($variantsByProductId, $user, $userUsed) {
                $originalPrice = $row->variant_id
                    ? (float) (($row->variant_sale_price ?? 0) > 0 ? $row->variant_sale_price : ($row->variant_price ?? 0))
                    : (float) (($row->product_sale_price ?? 0) > 0 ? $row->product_sale_price : ($row->product_price ?? 0));

                $soldQuantity = (int) ($row->sold_quantity ?? 0);
                $saleQuantity = (int) ($row->sale_quantity ?? 0);
                $percentSold = $saleQuantity > 0
                    ? (int) round(($soldQuantity / $saleQuantity) * 100)
                    : 0;

                $thumbnail = $row->variant_image ?: $row->product_thumbnail;
                $productVariants = $variantsByProductId->get((int) $row->product_id, collect());
                $resolvedVariantId = $row->variant_id ? (int) $row->variant_id : null;
                $resolvedVariant = $resolvedVariantId !== null
                    ? $productVariants->firstWhere('id', $resolvedVariantId)
                    : null;

                $resolvedVariantLabel = $resolvedVariant !== null
                    ? $this->resolveFlashSaleVariantLabel($resolvedVariant)
                    : null;
                $serializedVariants = $resolvedVariant !== null
                    ? [
                        [
                            'id' => (int) $resolvedVariant->id,
                            'stock' => (int) $resolvedVariant->stock,
                            'price' => isset($resolvedVariant->price) ? (float) $resolvedVariant->price : null,
                            'sale_price' => isset($resolvedVariant->sale_price) ? (float) $resolvedVariant->sale_price : null,
                            'image' => $resolvedVariant->image,
                            'ticket_reward_quantity' => (int) ($resolvedVariant->ticket_reward_quantity ?? 0),
                            'attributes' => $resolvedVariant->attributeValues
                                ->map(static function ($attributeValue): array {
                                    return [
                                        'id' => (int) $attributeValue->id,
                                        'attribute_id' => (int) $attributeValue->attribute_id,
                                        'attribute_name' => $attributeValue->attribute?->name,
                                        'value' => (string) $attributeValue->value,
                                    ];
                                })
                                ->values()
                                ->all(),
                            'variant_label' => $resolvedVariantLabel,
                        ],
                    ]
                    : [];

                $perUserLimit = isset($row->per_user_limit) ? (int) $row->per_user_limit : null;
                $purchasedByCurrentUser = null;
                $remainingUserQuota = null;
                if ($user && $perUserLimit !== null) {
                    $purchasedByCurrentUser = (int) ($userUsed[$row->flash_sale_item_id] ?? 0);
                    $remainingUserQuota = max(0, $perUserLimit - $purchasedByCurrentUser);
                }

                return [
                    'flash_sale_item_id' => (int) $row->flash_sale_item_id,
                    'flash_sale_id' => (string) $row->flash_sale_id,
                    'flash_sale_name' => (string) $row->flash_sale_name,
                    'flash_sale_price' => (float) $row->flash_sale_price,
                    'sale_quantity' => $saleQuantity,
                    'sold_quantity' => $soldQuantity,
                    'remaining_quantity' => max(0, $saleQuantity - $soldQuantity),
                    'per_user_limit' => $perUserLimit,
                    'purchased_by_current_user' => $purchasedByCurrentUser,
                    'remaining_user_quota' => $remainingUserQuota,
                    'percent_sold' => max(0, min(100, $percentSold)),
                    'start_time' => $this->serializeFlashSaleDateTime($row->flash_sale_start_time ?? null),
                    'end_time' => $this->serializeFlashSaleDateTime($row->flash_sale_end_time ?? null),
                    'product' => [
                        'id' => (int) $row->product_id,
                        'name' => (string) $row->product_name,
                        'slug' => (string) $row->product_slug,
                        'thumbnail' => $thumbnail,
                        'original_price' => $originalPrice,
                        'price' => (float) ($row->product_price ?? 0),
                        'sale_price' => (float) ($row->product_sale_price ?? 0),
                        'variant_id' => $row->variant_id ? (int) $row->variant_id : null,
                        'variant_label' => $resolvedVariantLabel,
                        'variant_price' => $row->variant_id ? (float) ($row->variant_price ?? 0) : null,
                        'variant_sale_price' => $row->variant_id ? (float) ($row->variant_sale_price ?? 0) : null,
                        'variants' => $serializedVariants,
                    ],
                    'store' => [
                        'id' => (string) $row->store_id,
                        'name' => (string) $row->store_name,
                        'logo_url' => $row->store_logo,
                        'status' => (string) $row->store_status,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'section' => [
                    'key' => 'flash_sale_multi_store',
                    'title' => 'Flash Sale',
                    'subtitle' => 'Tất cả sản phẩm - Nhiều cửa hàng',
                ],
                'items' => $items,
            ],
            'meta' => [
                'page' => (int) $paginator->currentPage(),
                'page_size' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'total_pages' => (int) $paginator->lastPage(),
                'has_next' => (bool) $paginator->hasMorePages(),
                'timezone' => 'Asia/Ho_Chi_Minh',
                'request_at' => now('Asia/Ho_Chi_Minh')->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Return lightweight schedule of upcoming (not-yet-started) flash sales.
     * Used by the client to know which future time-slots have configured programs.
     */
    public function upcomingFlashSaleSchedule(Request $request)
    {
        if (!Schema::hasTable('flash_sales')) {
            return response()->json(['data' => []]);
        }

        $now = now('Asia/Ho_Chi_Minh');

        $schedules = DB::table('flash_sales')
            ->where('is_active', true)
            ->where('start_time', '>', $now)
            ->select(['id', 'name', 'start_time', 'end_time'])
            ->orderBy('start_time')
            ->limit(20)
            ->get()
            ->map(function ($fs) {
                return [
                    'id' => (string) $fs->id,
                    'name' => (string) $fs->name,
                    'start_time' => $this->serializeFlashSaleDateTime($fs->start_time ?? null),
                    'end_time' => $this->serializeFlashSaleDateTime($fs->end_time ?? null),
                ];
            })
            ->values();

        return response()->json(['data' => $schedules]);
    }

    private function serializeFlashSaleDateTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value, self::FLASH_SALE_TIMEZONE)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveFlashSaleVariantLabel(ProductVariant $variant): string
    {
        $parts = $variant->attributeValues
            ->map(static fn ($attributeValue): string => trim((string) $attributeValue->value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        if (!empty($parts)) {
            return implode(' / ', $parts);
        }

        return 'Phân loại #' . $variant->id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStorePreviewItems(Store $store): array
    {
        // Try products relation first
        if ($store->relationLoaded('products') && $store->products->isNotEmpty()) {
            return $store->products
                ->take(3)
                ->map(function ($product) {
                    $ratingData = $this->calculateProductRating($product);

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'price' => (float) ($product->sale_price ?? $product->price),
                        'image_url' => $product->thumbnail,
                        'average_rating' => $ratingData['average_rating'],
                        'review_count' => $ratingData['review_count'],
                    ];
                })
                ->values()
                ->toArray();
        }

        // Fallback to store_menu if products are empty or filtered out
        if ($store->relationLoaded('menus') && $store->menus->isNotEmpty()) {
            return $store->menus
                ->take(3)
                ->map(function ($menu) {
                    return [
                        'id' => $menu->id,
                        'name' => $menu->item_name,
                        'slug' => null,
                        'price' => (float) ($menu->price ?? 0),
                        'image_url' => $menu->image_url,
                        'average_rating' => 0,
                        'review_count' => 0,
                    ];
                })
                ->values()
                ->toArray();
        }

        return [];
    }
}
