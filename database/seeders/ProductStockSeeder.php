<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Database\Seeder;

class ProductStockSeeder extends Seeder
{
    /**
     * For every product, ensure a ProductStock row exists. About 20% have physical stock.
     */
    public function run(): void
    {
        $productIds = Product::query()->pluck('id')->all();
        if ($productIds === []) {
            return;
        }

        $count = count($productIds);
        $inStockCount = (int) max(1, round($count * 0.20));
        shuffle($productIds);

        $defaults = [
            'reserved_stock' => 0,
            'min_threshold' => 0,
            'allow_backorder' => true,
        ];

        foreach ($productIds as $index => $productId) {
            $inStock = $index < $inStockCount;
            ProductStock::query()->updateOrCreate(
                ['product_id' => $productId],
                array_merge($defaults, [
                    'physical_stock' => $inStock ? random_int(1, 50) : 0,
                ])
            );
        }
    }
}
