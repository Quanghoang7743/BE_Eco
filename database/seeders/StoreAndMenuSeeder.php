<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreMenu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoreAndMenuSeeder extends Seeder
{
    /**
     * Seed real stores and store-specific menus used by EcoLuck K06/K07 flows.
     */
    public function run(): void
    {
        $stores = [
            [
                'id' => '11111111-1111-4111-8111-111111111111',
                'store_code' => 'ECO-Q1',
                'name' => 'EcoLuck Quận 1',
                'description' => 'Cửa hàng trung tâm quận 1, phục vụ nhanh.',
                'address' => '45 Nguyễn Huệ, Quận 1, TP.HCM',
                'phone' => '0901000001',
                'email' => 'q1@ecoluck.vn',
                'status' => 'approved',
                'is_open' => true,
                'open_time' => '07:00:00',
                'close_time' => '22:00:00',
                'lat' => 10.7756587,
                'lng' => 106.7004238,
            ],
            [
                'id' => '22222222-2222-4222-8222-222222222222',
                'store_code' => 'ECO-Q3',
                'name' => 'EcoLuck Quận 3',
                'description' => 'Cửa hàng gần trung tâm văn phòng, nhiều combo trưa.',
                'address' => '122 Cách Mạng Tháng 8, Quận 3, TP.HCM',
                'phone' => '0901000003',
                'email' => 'q3@ecoluck.vn',
                'status' => 'approved',
                'is_open' => true,
                'open_time' => '07:30:00',
                'close_time' => '21:30:00',
                'lat' => 10.7828215,
                'lng' => 106.6889319,
            ],
            [
                'id' => '33333333-3333-4333-8333-333333333333',
                'store_code' => 'ECO-BT',
                'name' => 'EcoLuck Bình Thạnh',
                'description' => 'Cửa hàng khu vực Bình Thạnh với menu đồ uống nổi bật.',
                'address' => '230 Điện Biên Phủ, Bình Thạnh, TP.HCM',
                'phone' => '0901000008',
                'email' => 'binhthanh@ecoluck.vn',
                'status' => 'approved',
                'is_open' => true,
                'open_time' => '08:00:00',
                'close_time' => '22:00:00',
                'lat' => 10.7981127,
                'lng' => 106.7094364,
            ],
        ];

        foreach ($stores as $store) {
            Store::query()->updateOrCreate(
                ['id' => $store['id']],
                [
                    'store_code' => $store['store_code'],
                    'name' => $store['name'],
                    'description' => $store['description'],
                    'address' => $store['address'],
                    'phone' => $store['phone'],
                    'email' => $store['email'],
                    'status' => $store['status'],
                    'is_open' => $store['is_open'],
                    'open_time' => $store['open_time'],
                    'close_time' => $store['close_time'],
                    'commission_rate' => 10,
                    'rating_avg' => 4.7,
                    'rating_count' => 120,
                    'min_order_tickets' => 1,
                    'max_order_tickets' => 100,
                ]
            );

            DB::statement(
                'UPDATE stores SET location = ST_GeomFromText(?) WHERE id = ?',
                [sprintf('POINT(%F %F)', $store['lng'], $store['lat']), $store['id']]
            );
        }

        $menus = [
            [
                'id' => 'a1111111-1111-4111-8111-111111111111',
                'store_id' => '11111111-1111-4111-8111-111111111111',
                'item_code' => 'Q1-001',
                'item_name' => 'Burger Bò Phô Mai',
                'description' => 'Burger signature với phô mai tan chảy.',
                'price' => 55000,
                'original_price' => 65000,
                'category' => 'Burger',
                'image_url' => null,
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'id' => 'a1111111-1111-4111-8111-111111111112',
                'store_id' => '11111111-1111-4111-8111-111111111111',
                'item_code' => 'Q1-002',
                'item_name' => 'Trà Hoa Quả Nhiệt Đới',
                'description' => 'Trà trái cây mát lạnh vị nhiệt đới.',
                'price' => 29000,
                'original_price' => 35000,
                'category' => 'Đồ uống',
                'image_url' => null,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'id' => 'a2222222-2222-4222-8222-222222222221',
                'store_id' => '22222222-2222-4222-8222-222222222222',
                'item_code' => 'Q3-001',
                'item_name' => 'Pizza Thập Cẩm Lớn',
                'description' => 'Pizza đế mỏng topping tổng hợp.',
                'price' => 149000,
                'original_price' => 169000,
                'category' => 'Pizza',
                'image_url' => null,
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'id' => 'a2222222-2222-4222-8222-222222222222',
                'store_id' => '22222222-2222-4222-8222-222222222222',
                'item_code' => 'Q3-002',
                'item_name' => 'Gà Rán Giòn Cay',
                'description' => '2 miếng gà rán giòn vị cay nhẹ.',
                'price' => 89000,
                'original_price' => 99000,
                'category' => 'Gà rán',
                'image_url' => null,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'id' => 'a3333333-3333-4333-8333-333333333331',
                'store_id' => '33333333-3333-4333-8333-333333333333',
                'item_code' => 'BT-001',
                'item_name' => 'Mì Ý Bò Bằm',
                'description' => 'Mì Ý sốt bò bằm đậm vị.',
                'price' => 79000,
                'original_price' => 89000,
                'category' => 'Mì Ý',
                'image_url' => null,
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'id' => 'a3333333-3333-4333-8333-333333333332',
                'store_id' => '33333333-3333-4333-8333-333333333333',
                'item_code' => 'BT-002',
                'item_name' => 'Cà phê Caramel Macchiato',
                'description' => 'Cà phê sữa caramel thơm ngậy.',
                'price' => 45000,
                'original_price' => 55000,
                'category' => 'Đồ uống',
                'image_url' => null,
                'is_featured' => false,
                'sort_order' => 2,
            ],
        ];

        foreach ($menus as $menu) {
            StoreMenu::query()->updateOrCreate(
                ['id' => $menu['id']],
                [
                    'store_id' => $menu['store_id'],
                    'item_code' => $menu['item_code'],
                    'item_name' => $menu['item_name'],
                    'description' => $menu['description'],
                    'price' => $menu['price'],
                    'original_price' => $menu['original_price'],
                    'is_lucky_item' => true,
                    'category' => $menu['category'],
                    'image_url' => $menu['image_url'],
                    'is_available' => true,
                    'is_featured' => $menu['is_featured'],
                    'sort_order' => $menu['sort_order'],
                ]
            );
        }
    }
}
