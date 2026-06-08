<?php

namespace App\Traits;

use App\Models\Order\BaseOrder;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

const STEEL_MATERIALS_CATEGORY_ID = 82;
const OUTDOORS_CATEGORY_ID = 83;
const VERANDA_CATEGORY_ID = 84;

const MB_VERANDA_SUPPLIER_ID = 6;
const MB_SUN_SUPPLIER_ID = 7;

trait CartTrait
{
    public function canAddToCart(BaseOrder $order, Product $productToAdd): array
    {
        // Compute category presence flags by iterating once over order products.
        $hasSteelMaterials = false;
        $hasNonSteelMaterials = false;
        $hasVeranda = false;
        $hasNonOutdoors = false;

        foreach ($order->orderProducts()->get() as $orderProduct) {
            $product = $orderProduct->product;
            if (!$product) {
                continue;
            }

            $isSteel = (bool) $product->categories->contains(STEEL_MATERIALS_CATEGORY_ID);
            $isVeranda = (bool) $product->categories->contains(VERANDA_CATEGORY_ID);
            $isOutdoors = (bool) $product->categories->contains(OUTDOORS_CATEGORY_ID);

            if ($isSteel) {
                $hasSteelMaterials = true;
            } else {
                $hasNonSteelMaterials = true;
            }

            if ($isVeranda) {
                $hasVeranda = true;
            }

            if (!$isOutdoors) {
                $hasNonOutdoors = true;
            }

            // early exit once all flags that matter are true
            if ($hasSteelMaterials && $hasNonSteelMaterials && $hasVeranda && $hasNonOutdoors) {
                break;
            }
        }

        // Apply business rules in the same order as before
        // Rule: can't add steel materials if cart already has other product types
        if ($productToAdd->categories->contains(STEEL_MATERIALS_CATEGORY_ID) && $hasNonSteelMaterials) {
            return ['success' => false, 'type' => 'steel_materials'];
        }

        // Rule: can't add non-steel product if cart already has steel materials
        if (!$productToAdd->categories->contains(STEEL_MATERIALS_CATEGORY_ID) && $hasSteelMaterials) {
            return ['success' => false, 'type' => 'steel_materials'];
        }

        // Rule: can't add veranda product if cart already has other product types
        if ($productToAdd->categories->contains(VERANDA_CATEGORY_ID) && $hasNonOutdoors) {
            return ['success' => false, 'type' => 'outdoors'];
        }

        // Rule: can't add non-outdoor product if cart already has veranda products
        if (!$productToAdd->categories->contains(OUTDOORS_CATEGORY_ID) && $hasVeranda) {
            return ['success' => false, 'type' => 'outdoors'];
        }

        return ['success' => true];
    }

    public function cartHasSteelMaterials(BaseOrder $order): bool
    {
        return $order->orderProducts()
            ->whereHas('product', function (Builder $q) {
                $q->whereHas('productCategories', function (Builder $q) {
                    $q->where('category_id', STEEL_MATERIALS_CATEGORY_ID);
                });
            })
            ->exists();
    }
}
