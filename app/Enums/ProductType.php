<?php

namespace App\Enums;

enum ProductType: string
{
    case Frame = 'frame';
    case Service = 'service';
    case Part = 'part';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Frame => 'Frame',
            self::Part => 'Onderdeel',
            self::Service => 'Service',
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
