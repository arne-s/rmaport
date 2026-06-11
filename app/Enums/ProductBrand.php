<?php

namespace App\Enums;

enum ProductBrand: string
{
    case Jlab = 'JL';
    case HouseOfMarley = 'house_of_marley';
    case Homedics = 'homedics';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Jlab => 'JLAB',
            self::HouseOfMarley => 'House of Marley',
            self::Homedics => 'Homedics',
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

    public static function resolveImportValue(?string $state): ?self
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($state));

        foreach (self::cases() as $case) {
            if (mb_strtolower($case->getLabel() ?? '') === $normalized) {
                return $case;
            }

            if (mb_strtolower($case->value) === $normalized) {
                return $case;
            }
        }

        return match ($normalized) {
            'jlab', 'jl' => self::Jlab,
            'house of marley', 'homar' => self::HouseOfMarley,
            'homedics' => self::Homedics,
            default => null,
        };
    }

    public static function resolveFromProductDescription(?string $description): ?self
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        if (preg_match('/house of marley/i', $description)) {
            return self::HouseOfMarley;
        }

        if (preg_match('/homedics/i', $description)) {
            return self::Homedics;
        }

        if (preg_match('/jlab/i', $description)) {
            return self::Jlab;
        }

        return null;
    }
}
