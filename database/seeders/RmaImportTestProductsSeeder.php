<?php

namespace Database\Seeders;

use App\Enums\ProductBrand;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\RmaImport\RmaImportFixtureProductExtractor;
use Illuminate\Database\Seeder;

class RmaImportTestProductsSeeder extends Seeder
{
    public function run(): void
    {
        $extractor = new RmaImportFixtureProductExtractor;
        $products = $extractor->extract();
        $supplierIds = Supplier::query()->pluck('id')->all();

        foreach ($products as $productData) {
            $brand = ProductBrand::resolveImportValue($productData['brand'])
                ?? ProductBrand::resolveFromProductDescription($productData['name']);
            $name = $this->resolveProductName($productData['name'], $brand, $productData['ean']);
            $purchasePrice = fake()->randomFloat(2, 12, 120);
            $margin = fake()->randomElement([20, 25, 30, 35]);
            $salesPrice = round($purchasePrice / (1 - ($margin / 100)), 2);

            $product = Product::query()->firstOrNew(['ean_1' => $productData['ean']]);

            if (! $product->exists) {
                $product->uid = $this->resolveProductUid($productData);
            }

            $product->fill([
                'name' => $name,
                'description' => fake()->optional(0.4)->sentence(),
                'brand' => $brand,
                'unit' => ProductUnit::Pieces,
                'mediamarkt_nr_nl' => $productData['article_number'],
                'supplier_product_uid' => $productData['article_number'],
                'supplier_id' => $supplierIds === [] ? null : fake()->randomElement($supplierIds),
                'company_purchase_price' => $purchasePrice,
                'company_sales_price' => $salesPrice,
                'company_margin' => $margin,
                'stock_location' => fake()->optional(0.5)->bothify('A-##-?'),
                'is_purchase_item' => true,
                'is_sales_item' => true,
                'is_on_demand_item' => fake()->boolean(15),
                'is_fraction_allowed_item' => false,
                'is_stock_enabled' => fake()->boolean(70),
            ])->save();
        }
    }

    /**
     * @param  array{ean: string, name: string|null, brand: string|null, article_number: string|null}  $productData
     */
    private function resolveProductUid(array $productData): string
    {
        if ($productData['article_number'] !== null) {
            return 'IMP-' . $productData['article_number'];
        }

        return 'IMP-EAN-' . $productData['ean'];
    }

    private function resolveProductName(?string $name, ?ProductBrand $brand, string $ean): string
    {
        if ($name !== null) {
            return $name;
        }

        if ($brand !== null) {
            return $brand->getLabel() . ' testproduct';
        }

        return 'Import testproduct ' . $ean;
    }
}
