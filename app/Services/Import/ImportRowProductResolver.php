<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;
use Illuminate\Database\Eloquent\Builder;

final class ImportRowProductResolver
{
    use MapsRmaImportRows;

    public const FALLBACK_ARTICLE_NUMBER = '0000';

    public function findByEan(?string $ean): ?Product
    {
        $normalizedEan = $this->normalizeEan($ean);

        if ($normalizedEan === null) {
            return null;
        }

        return $this->productsByEan()[$normalizedEan] ?? $this->fallbackProduct();
    }

    public function usedFallback(?string $ean): bool
    {
        $normalizedEan = $this->normalizeEan($ean);

        if ($normalizedEan === null) {
            return false;
        }

        return ! array_key_exists($normalizedEan, $this->productsByEan());
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

    private function fallbackProduct(): ?Product
    {
        return Product::query()
            ->where('uid', self::FALLBACK_ARTICLE_NUMBER)
            ->first();
    }
}
