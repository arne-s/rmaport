<?php

namespace App\Enums;

enum PriceType: string
{
    case CompanyPurchasePrice = 'company_purchase_price';
    case CompanySalesPrice = 'company_sales_price';
    case CompanyMargin = 'company_margin';
    case CompanyMarkup = 'company_markup';

    public function getLabel(): string
    {
        return match ($this) {
            self::CompanyPurchasePrice => 'Inkoop',
            self::CompanySalesPrice => 'Verkoop',
            self::CompanyMargin => 'Marge',
            self::CompanyMarkup => 'Opslag',
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
