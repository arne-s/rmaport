<?php

namespace App\Support\Pricing;

/**
 * Gross margin as a percentage of sales price (same definition as Exact Online):
 * (sales − purchase) / sales × 100. Inverse: sales = purchase / (1 − margin/100).
 */
class ProductPricingCalculator
{
    /**
     * Gross margin based pricing:
     * sales = purchase / (1 - margin%).
     */
    public static function recalculateSalesFromPurchaseAndMargin(float $purchase, float $margin): float
    {
        $denominator = 1 - ($margin / 100);
        if ($denominator <= 1e-8) {
            return round($purchase, 2);
        }

        return round($purchase / $denominator, 2);
    }

    public static function recalculateMarginFromPurchaseAndSales(float $purchase, float $sales): float
    {
        if (abs($sales) < 0.0001) {
            return 0.0;
        }

        if (abs($purchase) < 0.0001) {
            return 0.0;
        }

        return round((($sales - $purchase) / $sales) * 100, 2);
    }

    /**
     * Markup based pricing:
     * sales = purchase * (1 + markup%).
     */
    public static function recalculateSalesFromPurchaseAndMarkup(float $purchase, float $markup): float
    {
        return round($purchase * (1 + ($markup / 100)), 2);
    }

    public static function recalculateMarkupFromPurchaseAndSales(float $purchase, float $sales): float
    {
        if (abs($purchase) < 0.0001) {
            return 0.0;
        }

        return round((($sales / $purchase) - 1) * 100, 2);
    }
}
