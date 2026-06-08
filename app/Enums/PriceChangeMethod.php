<?php

namespace App\Enums;

enum PriceChangeMethod: string
{
    case Manual = 'manual';
    case Bulk = 'bulk';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Handmatig',
            self::Bulk => 'Bulk',
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
