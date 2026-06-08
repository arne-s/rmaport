<?php

namespace App\Enums;

enum FulfillmentType: string
{
    case MakeToOrder = 'mto'; // Make-to-Order; product has to be manufactured at a supplier.
    case Release = 'release'; // Release order; product has been requested by the dealer
    case MakeToStock = 'mts'; // Make-to-Stock; product is fulfilled from stock.

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MakeToOrder => 'Inkoop',
            self::Release => 'Afroep',
            self::MakeToStock => 'Voorraad',
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }
}
