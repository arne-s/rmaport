<?php

namespace App\Enums;

enum ArticleGroupGlAccountType: string
{
    case Revenue = 'revenue';
    case CostOfGoods = 'cost_of_goods';
    case Stock = 'stock';
    case PriceDifference = 'price_difference';

    public function getLabel(): string
    {
        return match ($this) {
            self::Revenue => 'Omzet',
            self::CostOfGoods => 'Kostprijs verkopen',
            self::Stock => 'Voorraad / Kosten',
            self::PriceDifference => 'Prijsverschillen',
        };
    }

    /**
     * Maps each type to the corresponding Exact Online ItemGroups API field names.
     *
     * @return array{guid: string, code: string, description: string}
     */
    public function exactApiFields(): array
    {
        return match ($this) {
            self::Revenue => ['guid' => 'GLRevenue', 'code' => 'GLRevenueCode', 'description' => 'GLRevenueDescription'],
            self::CostOfGoods => ['guid' => 'GLCosts', 'code' => 'GLCostsCode', 'description' => 'GLCostsDescription'],
            self::Stock => ['guid' => 'GLStock', 'code' => 'GLStockCode', 'description' => 'GLStockDescription'],
            self::PriceDifference => ['guid' => 'GLPurchasePriceDifference', 'code' => 'GLPurchasePriceDifferenceCode', 'description' => 'GLPurchasePriceDifferenceDescr'],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }
}
