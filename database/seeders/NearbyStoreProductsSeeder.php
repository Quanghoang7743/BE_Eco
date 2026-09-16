<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NearbyStoreProductsSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('stores') || !Schema::hasTable('product_categories')) {
            return;
        }

        $now = now();
        $categorySlug = 'demo-nearby-distance-products';

        $category = DB::table('product_categories')->where('slug', $categorySlug)->first();
        if (!$category) {
            $categoryId = DB::table('product_categories')->insertGetId([
                'name' => 'San pham demo khoang cach',
                'slug' => $categorySlug,
                'sort_order' => 99,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $categoryId = (int) $category->id;
            DB::table('product_categories')->where('id', $categoryId)->update([
                'name' => 'San pham demo khoang cach',
                'updated_at' => $now,
            ]);
        }

        $stores = DB::table('stores')
            ->select('id', 'name')
            ->whereNotNull('location')
            ->whereIn(DB::raw('LOWER(status)'), ['active', 'approved'])
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->values();

        if ($stores->isEmpty()) {
            $stores = DB::table('stores')
                ->select('id', 'name')
                ->orderBy('created_at')
                ->limit(6)
                ->get()
                ->values();
        }

        if ($stores->isEmpty()) {
            return;
        }

        $storeCount = $stores->count();
        $hasStatus = Schema::hasColumn('products', 'status');
        $hasPublishedAt = Schema::hasColumn('products', 'published_at');
        $hasApprovedAt = Schema::hasColumn('products', 'approved_at');

        for ($i = 1; $i <= 36; $i++) {
            $store = $stores[($i - 1) % $storeCount];
            $index = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $slug = 'demo-khoang-cach-' . $index;
            $basePrice = 30000 + ($i * 2000);
            $salePrice = $basePrice - (($i % 5) * 1000);

            $payload = [
                'name' => 'Demo Khoang Cach ' . $index,
                'summary' => 'San pham demo de test sap xep gan den xa theo store',
                'content' => 'Seed tu dong cho pagination 20 san pham moi trang.',
                'thumbnail' => null,
                'price' => $basePrice,
                'sale_price' => $salePrice,
                'slug' => $slug,
                'sku' => 'DIST-DEMO-' . $index,
                'category_id' => $categoryId,
                'store_id' => (string) $store->id,
                'stock' => 200 + $i,
                'commission_rate' => 10,
                'updated_at' => $now,
            ];

            if ($hasStatus) {
                $payload['status'] = 'approved';
            }
            if ($hasPublishedAt) {
                $payload['published_at'] = $now;
            }
            if ($hasApprovedAt) {
                $payload['approved_at'] = $now;
            }

            $existing = DB::table('products')->where('slug', $slug)->first();
            if ($existing) {
                DB::table('products')->where('id', $existing->id)->update($payload);
            } else {
                $payload['created_at'] = $now;
                DB::table('products')->insert($payload);
            }
        }
    }
}
