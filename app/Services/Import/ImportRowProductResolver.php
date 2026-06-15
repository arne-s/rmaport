<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;
use Illuminate\Database\Eloquent\Builder;

final class ImportRowProductResolver
{
    use MapsRmaImportRows;

    public function findByEan(?string $ean): ?Product
    {
        $normalizedEan = $this->normalizeEan($ean);

        if ($normalizedEan === null) {
            return null;
        }

        return $this->productsByEan()[$normalizedEan] ?? null;
    }

    /**
     * @return array<string, Product>
     */
    private function productsByEan(): array
    {
        return once(function (): array {
            $productsByEan = [];

            Product::query()
                ->select(['id', 'name', 'ean_1', 'ean_2'])
                ->where(function (Builder $query): void {
                    $query->whereNotNull('ean_1')
                        ->orWhereNotNull('ean_2');
                })
                ->each(function (Product $product) use (&$productsByEan): void {
                    foreach ([$product->ean_1, $product->ean_2] as $ean) {
                        $normalizedEan = $this->normalizeEan($ean);

                        if ($normalizedEan !== null) {
                            $productsByEan[$normalizedEan] = $product;
                        }
                    }
                });

            return $productsByEan;
        });
    }
}
