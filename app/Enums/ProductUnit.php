<?php

namespace App\Enums;

enum ProductUnit: string
{
    case Pieces = 'pieces';
    case Set = 'set';
    case Meter = 'meter';
    case Kilogram = 'kilogram';
    case Liter = 'liter';
    case SquareMeter = 'square_meter';
    case Hour = 'hour';
    case Pair = 'pair';
    case Box = 'box';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pieces => 'Stuks',
            self::Set => 'Set',
            self::Meter => 'Meter',
            self::Kilogram => 'Kilogram',
            self::Liter => 'Liter',
            self::SquareMeter => 'Vierkante meter',
            self::Hour => 'Uur',
            self::Pair => 'Paar',
            self::Box => 'Doos',
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

    /**
     * Map Exact Online Item unit fields to a ProductUnit case.
     */
    public static function tryFromExact(?string $unitDescription, ?string $unitCode): self
    {
        $candidates = [
            self::normalizeToken($unitDescription),
            self::normalizeToken($unitCode),
        ];

        foreach ($candidates as $token) {
            if ($token === '' || $token === '-' || $token === 'x') {
                return self::Pieces;
            }

            if (str_contains($token, 'stuk') || $token === 'pc' || $token === 'pce' || $token === 'piece' || $token === 'st') {
                return self::Pieces;
            }
            if ($token === 'set') {
                return self::Set;
            }
            if ($token === 'm' || str_starts_with($token, 'meter')) {
                return self::Meter;
            }
            if ($token === 'kg' || str_starts_with($token, 'kilo')) {
                return self::Kilogram;
            }
            if ($token === 'l' || str_starts_with($token, 'liter')) {
                return self::Liter;
            }
            if (str_contains($token, 'm2') || str_contains($token, 'm²') || str_contains($token, 'vierkant')) {
                return self::SquareMeter;
            }
            if (str_contains($token, 'uur') || $token === 'h') {
                return self::Hour;
            }
            if ($token === 'paar' || $token === 'pair') {
                return self::Pair;
            }
            if (str_contains($token, 'doos') || $token === 'box') {
                return self::Box;
            }
        }

        return self::Pieces;
    }

    private static function normalizeToken(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return strtolower(trim($value));
    }
}
