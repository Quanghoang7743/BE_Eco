<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $parents = Category::factory()->count(50)->create();

        Category::factory()
            ->count(150)
            ->state(function () use ($parents) {
                return [
                    'parent_category_id' => $parents->random()->id,
                ];
            })
            ->create();
    }
}
