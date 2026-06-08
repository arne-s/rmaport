<?php

namespace App\Enums;

enum RecurringInvoiceFrequency: string
{
    case Month = 'month';
    case Quarter = 'quarter';
    case SixMonth = 'six_month';
    case Year = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => 'Maandelijks',
            self::Quarter => 'Kwartaal',
            self::SixMonth => 'Half jaar',
            self::Year => 'Jaarlijks',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->getLabel();
        }

        return $out;
    }
}
