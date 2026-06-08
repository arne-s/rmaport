<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Initial = 'initial';
    case Active = 'active';
    case Inactive = 'inactive';
    case Test = 'test';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Initial => 'Concept',
            self::Active => 'Actief',
            self::Inactive => 'Inactief',
            self::Test => 'Test-modus',
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    /**
     * Status options for edit forms; Concept is created via create flow only.
     *
     * @return array<string, string>
     */
    public static function labelsForEditForm(): array
    {
        return array_filter(
            self::labels(),
            fn (string $key): bool => $key !== self::Initial->value,
            ARRAY_FILTER_USE_KEY
        );
    }
}
