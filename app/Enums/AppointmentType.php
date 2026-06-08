<?php

namespace App\Enums;

enum AppointmentType: string
{
    case Fitting = 'fitting';
    case Delivery = 'delivery';
    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fitting => 'Passing',
            self::Delivery => 'Aflevering',
            self::Service => 'Onderhoud',
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
