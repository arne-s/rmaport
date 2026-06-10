<?php
namespace App\Enums;

enum PurchaseOrderType: string
{
    case Order = 'order';
    case Release = 'release';
    case Stock = 'stock';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Order => 'Inkoop: Ordergebonden',
            self::Release => 'Afroep',
            self::Stock => 'Voorraad',
            default => null,
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
