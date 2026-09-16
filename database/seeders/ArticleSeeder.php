<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\article;
use App\Models\Category;

class ArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categoryIds = Category::query()->pluck('id');

        if ($categoryIds->isEmpty()) {
            return;
        }

        article::factory()
            ->count(500)
            ->state(function () use ($categoryIds) {
                return [
                    'category_id' => $categoryIds->random(),
                ];
            })
            ->create();
    }
}
