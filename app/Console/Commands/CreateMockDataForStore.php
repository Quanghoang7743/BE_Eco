<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Topping;
use App\Models\ProductCategory;
use Illuminate\Support\Str;

class CreateMockDataForStore extends Command
{
    protected $signature = 'store:mock-data {--store=ABCD}';
    protected $description = 'Create 10 products, 3 variants each, and 5 toppings for a specific store';

    public function handle()
    {
        $storeName = $this->option('store');
        $store = Store::where('name', 'like', "%{$storeName}%")->first();

        if (!$store) {
            $this->error("Store matching '{$storeName}' not found.");
            return;
        }

        $this->info("Creating mock data for store: {$store->name} (ID: {$store->id})");

        // Try to find a category
        $category = ProductCategory::first();
        $categoryId = $category ? $category->id : null;

        // Create 5 toppings for the store
        $toppings = collect();
        for ($i = 1; $i <= 5; $i++) {
            $topping = Topping::create([
                'store_id' => $store->id,
                'name' => "Topping {$i} for {$store->name}",
                'price' => rand(5000, 20000),
                'stock' => 100,
                'sku' => "TOP-ABCD-" . Str::random(5),
                'enabled' => true,
            ]);
            $toppings->push($topping);
        }
        $this->info("Created 5 toppings.");

        // Create 10 products
        for ($p = 1; $p <= 10; $p++) {
            $product = Product::create([
                'name' => "Product {$p} " . Str::random(4),
                'summary' => "Summary for product {$p}",
                'content' => "Detail content for product {$p}",
                'price' => rand(30000, 100000),
                'slug' => Str::slug("product-{$p}-" . Str::random(6)),
                'sku' => "PROD-ABCD-{$p}-" . Str::random(4),
                'store_id' => $store->id,
                'category_id' => $categoryId,
                'stock' => 100,
                'status' => 'published',
                'published_at' => now(),
            ]);

            // Create 3 variants for this product
            for ($v = 1; $v <= 3; $v++) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'stock' => 50,
                    'enabled' => true,
                    'price' => $product->price + ($v * 5000),
                ]);
            }

            // Attach random toppings (some products have toppings, some don't, or random subset)
            // "có loại dùng có loại không" => attach 0 to 3 random toppings
            $numToppings = rand(0, 3);
            if ($numToppings > 0) {
                $randomToppings = $toppings->random($numToppings)->pluck('id');
                $product->toppings()->attach($randomToppings);
            }
        }

        $this->info("Created 10 products with 3 variants each, attached random toppings.");
    }
}
