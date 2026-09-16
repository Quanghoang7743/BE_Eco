<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DongTamNguProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Tìm cửa hàng Đồng Tâm Ngu
        $store = DB::table('stores')->where('name', 'Đồng Tâm Ngu')->first()
            ?? DB::table('stores')->where('name', 'like', '%Đồng Tâm%')->first();

        if (!$store) {
            $this->command?->error('Không tìm thấy cửa hàng Đồng Tâm Ngu.');
            return;
        }

        $storeId = $store->id;
        $this->command?->info("Đang thêm 100 sản phẩm mới cho cửa hàng: {$store->name} ({$storeId})");

        // Lấy danh sách sản phẩm hiện tại để tránh trùng lặp tên
        $existingNames = DB::table('products')
            ->where('store_id', $storeId)
            ->pluck('name')
            ->toArray();

        // 2. Định nghĩa các danh mục sản phẩm và sản phẩm gốc
        $categories = [
            'Trà Sữa' => [
                'slug' => 'tra-sua-dt',
                'items' => [
                    'Trà sữa Trân châu hoàng kim',
                    'Trà sữa Trân châu đường đen',
                    'Trà sữa khoai môn',
                    'Trà sữa Matcha Nhật Bản',
                    'Trà sữa Sô-cô-la Bỉ',
                    'Trà sữa Thái xanh',
                    'Trà sữa Thái đỏ',
                    'Trà sữa Oolong',
                    'Trà sữa lài',
                    'Trà sữa Hạt sen',
                    'Trà sữa trứng nướng',
                    'Trà sữa nướng caramel'
                ]
            ],
            'Trà Trái Cây' => [
                'slug' => 'tra-trai-cay-dt',
                'items' => [
                    'Trà dâu tây macchiato',
                    'Trà đào cam sả',
                    'Trà vải thạch điệp',
                    'Trà xoài kem cheese',
                    'Trà việt quất bạc hà',
                    'Trà dưa hấu lục trà',
                    'Trà bưởi hồng mật ong',
                    'Trà chanh leo kim quất',
                    'Trà táo xanh nha đam',
                    'Trà ổi hồng muối ớt'
                ]
            ],
            'Cà Phê' => [
                'slug' => 'ca-phe-dt',
                'items' => [
                    'Cà phê đen đá',
                    'Cà phê sữa đá',
                    'Bạc xỉu Sài Gòn',
                    'Cà phê cốt dừa',
                    'Cà phê muối Huế',
                    'Cà phê trứng Hà Nội',
                    'Cappuccino',
                    'Latte Macchiato',
                    'Americano',
                    'Espresso tinh tế'
                ]
            ],
            'Đá Xay' => [
                'slug' => 'da-xay-dt',
                'items' => [
                    'Đá xay Matcha kem tươi',
                    'Đá xay Sô-cô-la bánh quy',
                    'Đá xay Việt quất sữa chua',
                    'Đá xay Chanh dây tuyết',
                    'Đá xay Xoài nhiệt đới',
                    'Đá xay Cà phê caramel'
                ]
            ],
            'Ăn Vặt' => [
                'slug' => 'an-vat-dt',
                'items' => [
                    'Bánh tráng trộn siêu to',
                    'Bánh tráng nướng Đà Lạt',
                    'Khoai tây chiên lắc phô mai',
                    'Cá viên chiên nước mắm',
                    'Nem chua rán Hà Nội',
                    'Bánh mì nướng muối ớt',
                    'Phô mai que kéo sợi',
                    'Khô bò lá chanh',
                    'Khô gà lá chanh'
                ]
            ],
        ];

        // Dùng bộ modifiers mới để tên sản phẩm phong phú và khác biệt
        $modifiers = [
            'Thượng Hạng', 'Đặc Sản', 'Lớp Kem Sữa', 'Trân Châu Trắng', 'Thạch Nha Đam',
            'Sốt Sô-cô-la', 'Mát Lạnh', 'Không Đường', 'Ít Đá', 'Thêm Thạch',
            'Hạt Chia', 'Matcha Latte', 'Đậm Vị Trà', 'Béo Ngậy', 'Nhà Làm Đặc Biệt',
            'Combo Sáng', 'Combo Trưa', 'Tươi Mát', 'Gấp Đôi Trân Châu', 'Hương Vị Tết'
        ];

        // Chuẩn bị danh mục trong product_categories
        $catIds = [];
        foreach ($categories as $catName => $catData) {
            $existingCat = DB::table('product_categories')
                ->where('store_id', $storeId)
                ->where('name', $catName)
                ->first();

            if ($existingCat) {
                $catIds[$catName] = $existingCat->id;
            } else {
                $catIds[$catName] = DB::table('product_categories')->insertGetId([
                    'name' => $catName,
                    'slug' => $catData['slug'] . '-' . Str::random(3),
                    'store_id' => $storeId,
                    'is_active' => 1,
                    'sort_order' => 1,
                    'level' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $targetCount = 100; // Thêm đúng 100 sản phẩm mới
        $productCount = 0;
        $createdProducts = [];

        DB::beginTransaction();

        try {
            while ($productCount < $targetCount) {
                foreach ($categories as $catName => $catData) {
                    if ($productCount >= $targetCount) {
                        break;
                    }

                    $categoryId = $catIds[$catName];

                    foreach ($catData['items'] as $baseItem) {
                        if ($productCount >= $targetCount) {
                            break;
                        }

                        $modifier = $modifiers[$productCount % count($modifiers)];
                        $productName = $baseItem . ' (' . $modifier . ')';

                        // Kiểm tra trùng lặp với danh sách cũ hoặc mới tạo
                        if (in_array($productName, $existingNames, true) || in_array($productName, $createdProducts, true)) {
                            $productName = $baseItem . ' (' . $modifier . ' - Premium ' . ($productCount + 1) . ')';
                        }

                        $createdProducts[] = $productName;

                        // Tạo giá ngẫu nhiên hợp lý
                        $basePrice = 28000 + (rand(1, 15) * 5000); // 33k - 103k
                        $salePrice = $basePrice - (rand(0, 2) * 5000);

                        $slug = Str::slug($productName) . '-' . Str::random(5);
                        $sku = 'DTN-ADD-' . strtoupper(Str::random(3)) . '-' . str_pad((string)($productCount + 1), 4, '0', STR_PAD_LEFT);

                        // Thêm vào bảng products
                        $productId = DB::table('products')->insertGetId([
                            'name' => $productName,
                            'summary' => "{$productName} thơm ngon béo ngậy, chế biến từ nguyên liệu sạch đảm bảo chất lượng vệ sinh an toàn thực phẩm.",
                            'content' => "{$productName} là phiên bản nâng cấp của cửa hàng Đồng Tâm Ngu. Thích hợp sử dụng mỗi ngày.",
                            'thumbnail' => null,
                            'images' => json_encode([]),
                            'price' => $basePrice,
                            'sale_price' => $salePrice,
                            'slug' => $slug,
                            'sku' => $sku,
                            'category_id' => $categoryId,
                            'store_id' => $storeId,
                            'status' => 'published', // Đã duyệt
                            'published_at' => now(),
                            'approved_by' => 836,
                            'approved_at' => now(),
                            'stock' => rand(100, 500),
                            'view_count' => rand(5, 120),
                            'commission_rate' => 10.00,
                            'commission_rate_mode' => 'percent',
                            'ticket_reward_quantity' => 1,
                            'ticket_reward_enabled' => 1,
                            'ticket_type_code' => 'TICKET_B',
                            'base_ranking_score' => 0.00,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Thêm vào bảng store_menu
                        $itemCode = 'PRD-' . strtoupper(substr($storeId, 0, 6)) . '-' . $productId;

                        DB::table('store_menu')->insert([
                            'id' => (string) Str::uuid(),
                            'store_id' => $storeId,
                            'product_id' => $productId,
                            'variant_id' => null,
                            'item_code' => $itemCode,
                            'item_name' => $productName,
                            'description' => "{$productName} thơm ngon chuẩn vị, phiên bản giới hạn.",
                            'price' => $salePrice,
                            'original_price' => $basePrice,
                            'is_lucky_item' => false,
                            'category' => $catName,
                            'sub_category' => null,
                            'image_url' => null,
                            'is_available' => true,
                            'is_featured' => false,
                            'sort_order' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $productCount++;
                    }
                }
            }

            DB::commit();
            $this->command?->info("Đã seed thêm thành công {$productCount} sản phẩm mới cho Đồng Tâm Ngu.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command?->error("Có lỗi xảy ra khi seed sản phẩm: " . $e->getMessage());
        }
    }
}
