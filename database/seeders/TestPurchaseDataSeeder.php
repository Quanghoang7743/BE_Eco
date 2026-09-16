<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestPurchaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $storeCode = 'TEST-STORE-APR8';
        $storeName = 'EcoLuck Test Mua Hang';

        $store = DB::table('stores')->where('store_code', $storeCode)->first();

        if (! $store) {
            $storeId = (string) Str::uuid();
            DB::table('stores')->insert([
                'id' => $storeId,
                'store_code' => $storeCode,
                'name' => $storeName,
                'description' => 'Store test dat hang tu dong',
                'address' => '12 Nguyen Hue, Quan 1, TP.HCM',
                'phone' => '0909000001',
                'email' => 'test-store@ecoluck.local',
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
                'address' => '12 Nguyen Hue, Quan 1, TP.HCM',
                'status' => 'active',
                'is_open' => 1,
                'updated_at' => $now,
            ]);
        }

        DB::statement("UPDATE stores SET location = ST_GeomFromText('POINT(106.70098 10.77689)') WHERE id = ?", [$storeId]);

        $categorySlug = 'test-category-apr8';
        $category = DB::table('product_categories')->where('slug', $categorySlug)->first();

        if (! $category) {
            $categoryId = DB::table('product_categories')->insertGetId([
                'name' => 'Danh muc test mua hang',
                'slug' => $categorySlug,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $categoryId = $category->id;
            DB::table('product_categories')->where('id', $categoryId)->update([
                'name' => 'Danh muc test mua hang',
                'updated_at' => $now,
            ]);
        }

        $productSlug = 'san-pham-test-apr8';
        $product = DB::table('products')->where('slug', $productSlug)->first();

        if (! $product) {
            DB::table('products')->insert([
                'name' => 'San pham test APR8',
                'summary' => 'San pham tao de test mua hang',
                'content' => 'Dung de test quy trinh dat hang',
                'price' => 35000,
                'sale_price' => 29000,
                'slug' => $productSlug,
                'sku' => 'SKU-TEST-APR8',
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'stock' => 500,
                'commission_rate' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('products')->where('id', $product->id)->update([
                'name' => 'San pham test APR8',
                'sale_price' => 29000,
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'stock' => 500,
                'commission_rate' => 10,
                'updated_at' => $now,
            ]);
        }

        $secondProductSlug = 'san-pham-test-apr8-2';
        $secondProduct = DB::table('products')->where('slug', $secondProductSlug)->first();

        if (! $secondProduct) {
            DB::table('products')->insert([
                'name' => 'San pham test APR8 so 2',
                'summary' => 'San pham thu 2 de test mua hang',
                'content' => 'Dung de test nhieu san pham trong 1 cua hang',
                'price' => 45000,
                'sale_price' => 39000,
                'slug' => $secondProductSlug,
                'sku' => 'SKU-TEST-APR8-002',
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'stock' => 350,
                'commission_rate' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('products')->where('id', $secondProduct->id)->update([
                'name' => 'San pham test APR8 so 2',
                'sale_price' => 39000,
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'stock' => 350,
                'commission_rate' => 10,
                'updated_at' => $now,
            ]);
        }

        $itemCode = 'TEST-MENU-APR8-001';
        $menuItem = DB::table('store_menu')
            ->where('store_id', $storeId)
            ->where('item_code', $itemCode)
            ->first();

        if (! $menuItem) {
            DB::table('store_menu')->insert([
                'id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'item_code' => $itemCode,
                'item_name' => 'Combo test mua hang',
                'description' => 'Mon an test cho dat hang',
                'price' => 29000,
                'original_price' => 35000,
                'is_lucky_item' => 1,
                'category' => 'Danh muc test mua hang',
                'sub_category' => 'Combo',
                'is_available' => 1,
                'is_featured' => 1,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('store_menu')->where('id', $menuItem->id)->update([
                'item_name' => 'Combo test mua hang',
                'price' => 29000,
                'original_price' => 35000,
                'is_available' => 1,
                'is_featured' => 1,
                'updated_at' => $now,
            ]);
        }

        $secondItemCode = 'TEST-MENU-APR8-002';
        $secondMenuItem = DB::table('store_menu')
            ->where('store_id', $storeId)
            ->where('item_code', $secondItemCode)
            ->first();

        if (! $secondMenuItem) {
            DB::table('store_menu')->insert([
                'id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'item_code' => $secondItemCode,
                'item_name' => 'Combo test mua hang so 2',
                'description' => 'Mon an test thu 2 cho dat hang',
                'price' => 39000,
                'original_price' => 45000,
                'is_lucky_item' => 1,
                'category' => 'Danh muc test mua hang',
                'sub_category' => 'Combo',
                'is_available' => 1,
                'is_featured' => 0,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('store_menu')->where('id', $secondMenuItem->id)->update([
                'item_name' => 'Combo test mua hang so 2',
                'description' => 'Mon an test thu 2 cho dat hang',
                'price' => 39000,
                'original_price' => 45000,
                'is_available' => 1,
                'is_featured' => 0,
                'sort_order' => 2,
                'updated_at' => $now,
            ]);
        }
    }
}
