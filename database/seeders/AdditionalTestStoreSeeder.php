<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdditionalTestStoreSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $storeCode = 'TEST-STORE-APR9';
        $storeName = 'EcoLuck Test Mua Hang 2';

        $store = DB::table('stores')->where('store_code', $storeCode)->first();

        if (! $store) {
            $storeId = (string) Str::uuid();
            DB::table('stores')->insert([
                'id' => $storeId,
                'store_code' => $storeCode,
                'name' => $storeName,
                'description' => 'Store test thu 2 cho quy trinh mua ve va goi mon',
                'address' => '88 Dien Bien Phu, Binh Thanh, TP.HCM',
                'phone' => '0909000002',
                'email' => 'test-store-2@ecoluck.local',
                'status' => 'active',
                'is_open' => 1,
                'open_time' => '08:00:00',
                'close_time' => '22:00:00',
                'commission_rate' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $storeId = $store->id;
            DB::table('stores')->where('id', $storeId)->update([
                'name' => $storeName,
                'address' => '88 Dien Bien Phu, Binh Thanh, TP.HCM',
                'status' => 'active',
                'is_open' => 1,
                'updated_at' => $now,
            ]);
        }

        DB::statement("UPDATE stores SET location = ST_GeomFromText('POINT(106.71002 10.80024)') WHERE id = ?", [$storeId]);

        $categorySlug = 'test-category-apr9';
        $category = DB::table('product_categories')->where('slug', $categorySlug)->first();

        if (! $category) {
            $categoryId = DB::table('product_categories')->insertGetId([
                'name' => 'Danh muc test mua hang APR9',
                'slug' => $categorySlug,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $categoryId = $category->id;
            DB::table('product_categories')->where('id', $categoryId)->update([
                'name' => 'Danh muc test mua hang APR9',
                'updated_at' => $now,
            ]);
        }

        $productSlug = 'san-pham-test-apr9';
        $product = DB::table('products')->where('slug', $productSlug)->first();

        if (! $product) {
            DB::table('products')->insert([
                'name' => 'San pham test APR9',
                'summary' => 'San pham cua store test thu 2',
                'content' => 'Dung de test tach du lieu giua nhieu store',
                'price' => 69000,
                'sale_price' => 59000,
                'slug' => $productSlug,
                'sku' => 'SKU-TEST-APR9-001',
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'stock' => 300,
                'commission_rate' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('products')->where('id', $product->id)->update([
                'name' => 'San pham test APR9',
                'price' => 69000,
                'sale_price' => 59000,
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'stock' => 300,
                'commission_rate' => 10,
                'updated_at' => $now,
            ]);
        }

        $itemCode = 'TEST-MENU-APR9-001';
        $menuItem = DB::table('store_menu')
            ->where('store_id', $storeId)
            ->where('item_code', $itemCode)
            ->first();

        if (! $menuItem) {
            DB::table('store_menu')->insert([
                'id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'item_code' => $itemCode,
                'item_name' => 'Combo test mua hang APR9',
                'description' => 'Mon an test cua store thu 2',
                'price' => 59000,
                'original_price' => 69000,
                'is_lucky_item' => 1,
                'category' => 'Danh muc test mua hang APR9',
                'sub_category' => 'Combo',
                'is_available' => 1,
                'is_featured' => 1,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('store_menu')->where('id', $menuItem->id)->update([
                'item_name' => 'Combo test mua hang APR9',
                'description' => 'Mon an test cua store thu 2',
                'price' => 59000,
                'original_price' => 69000,
                'is_available' => 1,
                'is_featured' => 1,
                'sort_order' => 1,
                'updated_at' => $now,
            ]);
        }
    }
}
