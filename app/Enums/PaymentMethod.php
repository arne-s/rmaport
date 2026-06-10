<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Invoice = 'invoice';
    case Cash = 'cash';
    case Pin = 'pin';
    case Transfer = 'transfer';
    case Warranty = 'warranty';
    case FreeOfCharge = 'free_of_charge';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Invoice => 'Op rekening',
            self::Cash => 'Contant',
            self::Pin => 'Pin',
            self::Transfer => 'Overschrijving',
            self::Warranty => 'Garantie',
            self::FreeOfCharge => 'Kosteloos',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel() ?? $item->value;

            return $carry;
        }, []);
    }
}
