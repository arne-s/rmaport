<?php

namespace App\Enums;

enum ShippingAddressType: string
{
    case Customer = 'customer';
    case Company = 'company';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'Klant',
            self::Company => 'Leveradres',
            self::Custom => 'Zelf ingeven',
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel();

            return $carry;
        }, []);
    }
}
