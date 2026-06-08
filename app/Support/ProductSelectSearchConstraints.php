<?php

namespace App\Support;

final class ProductSelectSearchConstraints
{
    /**
     * @param  array<int, int>|null  $restrictToProductIds
     */
    public function __construct(
        public ?int $supplierId = null,
        public bool $excludeServiceProducts = false,
        public ?array $restrictToProductIds = null,
        public bool $salesItemsOnly = true,
        public bool $purchaseItemsOnly = false,
        public ?int $anchorProductId = null,
    ) {}
}
