<?php

namespace App\Enums;

enum ValidityPeriod: int
{
    case DAYS_14 = 14;
    case DAYS_30 = 30;
    case DAYS_60 = 60;

    public function label(): string
    {
        return match ($this) {
            self::DAYS_14 => '14 dagen',
            self::DAYS_30 => '30 dagen',
            self::DAYS_60 => '60 dagen',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->value] = $case->label();
        }
        return $result;
    }
}
