<?php

namespace App\Enums;

enum ProductBattery: string
{
    case ButtonCell = 'button_cell';
    case Aa = 'aa';
    case Aaa = 'aaa';
    case UpTo50g = 'up_to_50g';
    case From51To150g = '51_to_150g';
    case From151To250g = '151_to_250g';
    case From251To500g = '251_to_500g';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ButtonCell => 'Knoopcel',
            self::Aa => 'AA',
            self::Aaa => 'AAA',
            self::UpTo50g => 't/m 50gr',
            self::From51To150g => '51 t/m 150gr',
            self::From151To250g => '151 t/m 250gr',
            self::From251To500g => '251 t/m 500gr',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $label = $item->getLabel();
            if ($label !== null) {
                $carry[$item->value] = $label;
            }

            return $carry;
        }, []);
    }
}
