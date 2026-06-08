<?php

namespace App\Filament\Forms\Components;

use App\Models\Product;
use App\Support\ProductSelectSearchConstraints;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

class ProductSelect extends Select
{
    protected int | Closure | null $supplierId = null;

    protected bool | Closure $excludeServiceProducts = false;

    /** @var array<int, int>|Closure|null */
    protected array | Closure | null $restrictToProductIds = null;

    protected bool | Closure $salesItemsOnly = true;

    protected bool | Closure $purchaseItemsOnly = false;

    protected int | Closure | null $anchorProductId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Artikel');
        $this->searchable();
        $this->preload();

        $this->options(function (Get $get): array {
            return Product::optionsForSelectSearch('', $this->resolveConstraints($get));
        });

        $this->getSearchResultsUsing(function (string $search, Get $get): array {
            return Product::optionsForSelectSearch($search, $this->resolveConstraints($get));
        });

        $this->getOptionLabelUsing(fn ($value): string => Product::getSelectOptionLabelForId($value));
    }

    public function supplierId(int | Closure | null $supplierId): static
    {
        $this->supplierId = $supplierId;

        return $this;
    }

    public function excludeServiceProducts(bool | Closure $exclude = true): static
    {
        $this->excludeServiceProducts = $exclude;

        return $this;
    }

    /**
     * @param  array<int, int>|Closure|null  $ids
     */
    public function restrictToProductIds(array | Closure | null $ids): static
    {
        $this->restrictToProductIds = $ids;

        return $this;
    }

    public function salesItemsOnly(bool | Closure $salesItemsOnly = true): static
    {
        $this->salesItemsOnly = $salesItemsOnly;

        return $this;
    }

    public function purchaseItemsOnly(bool | Closure $purchaseItemsOnly = true): static
    {
        $this->purchaseItemsOnly = $purchaseItemsOnly;

        return $this;
    }

    /**
     * When preloading (empty search), list products with the same first 7 UID characters, after this product's UID.
     */
    public function anchorProductId(int | Closure | null $anchorProductId): static
    {
        $this->anchorProductId = $anchorProductId;

        return $this;
    }

    protected function resolveConstraints(?Get $get = null): ProductSelectSearchConstraints
    {
        $supplierId = $this->evaluate($this->supplierId);
        $excludeServiceProducts = (bool) $this->evaluate($this->excludeServiceProducts);
        $restrictToProductIds = $this->evaluate($this->restrictToProductIds);
        $salesItemsOnly = (bool) $this->evaluate($this->salesItemsOnly);
        $purchaseItemsOnly = (bool) $this->evaluate($this->purchaseItemsOnly);

        $normalizedSupplierId = null;
        if ($supplierId !== null && $supplierId !== '') {
            $normalizedSupplierId = (int) $supplierId;
        }

        $normalizedRestrictIds = null;
        if (is_array($restrictToProductIds)) {
            $normalizedRestrictIds = array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                $restrictToProductIds,
            ), static fn (int $id): bool => $id > 0));
        }

        $normalizedAnchorProductId = $this->resolveAnchorProductId($get);

        return new ProductSelectSearchConstraints(
            supplierId: $normalizedSupplierId,
            excludeServiceProducts: $excludeServiceProducts,
            restrictToProductIds: $normalizedRestrictIds,
            salesItemsOnly: $salesItemsOnly,
            purchaseItemsOnly: $purchaseItemsOnly,
            anchorProductId: $normalizedAnchorProductId,
        );
    }

    protected function resolveAnchorProductId(?Get $get): ?int
    {
        if ($this->anchorProductId !== null) {
            $anchorProductId = $this->evaluate($this->anchorProductId, ['get' => $get]);

            if (filled($anchorProductId)) {
                $normalized = (int) $anchorProductId;

                return $normalized > 0 ? $normalized : null;
            }
        }

        $state = $this->getState();

        if (filled($state)) {
            $normalized = (int) $state;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }
}
