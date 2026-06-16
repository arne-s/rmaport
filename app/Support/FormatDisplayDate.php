<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class FormatDisplayDate
{
    public static function longDate(Carbon $date): string
    {
        $month = mb_strtolower(rtrim($date->translatedFormat('M'), '.').'.');

        return sprintf('%s %s %s', $date->translatedFormat('j'), $month, $date->translatedFormat('Y'));
    }

    public static function longDateTime(Carbon $date): string
    {
        return self::longDate($date).' '.$date->format('H:i');
    }
}
