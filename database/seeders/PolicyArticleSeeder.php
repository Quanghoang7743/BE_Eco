<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\article;
use Illuminate\Database\Seeder;

class PolicyArticleSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::updateOrCreate(
            ['slug' => 'chinh-sach-dieu-khoan'],
            [
                'title' => 'Chính sách & Điều khoản',
                'description' => 'Danh mục dùng cho điều khoản sàn và chính sách cửa hàng.',
            ]
        );

        $now = now();

        $policies = [
            [
                'slug' => 'platform-terms',
                'title' => 'Điều khoản sử dụng sàn',
                'summary' => 'Điều khoản áp dụng cho khách hàng khi sử dụng nền tảng.',
                'content' => '<p>Nội dung mẫu cho điều khoản sử dụng sàn. Admin có thể chỉnh sửa trực tiếp bằng trình soạn thảo.</p>',
                'sort_order' => 1,
            ],
            [
                'slug' => 'store-policy',
                'title' => 'Chính sách cửa hàng',
                'summary' => 'Chính sách áp dụng cho đối tác cửa hàng và quy trình vận hành.',
                'content' => '<p>Nội dung mẫu cho chính sách cửa hàng. Đây là bản ghi dùng cho trang đăng ký và xác nhận của store.</p>',
                'sort_order' => 2,
            ],
        ];

        foreach ($policies as $policy) {
            article::updateOrCreate(
                ['slug' => $policy['slug']],
                [
                    'title' => $policy['title'],
                    'summary' => $policy['summary'],
                    'content' => $policy['content'],
                    'category_id' => $category->id,
                    'display_area' => 'policy',
                    'sort_order' => $policy['sort_order'],
                    'published_at' => $now,
                ]
            );
        }
    }
}