<?php

namespace App\Support;

final class DurationTime
{
    public static function secondsToDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * Rounds up to whole hours for countdown labels (e.g. 3h 59m → "4 uur").
     */
    public static function secondsToRoundedHoursLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds === 0) {
            return '0 uur';
        }

        $hours = (int) ceil($seconds / 3600);

        return $hours === 1 ? '1 uur' : "{$hours} uur";
    }

    public static function durationToSeconds(string $duration): int
    {
        $duration = trim($duration);

        if ($duration === '') {
            return 0;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $duration, $matches)) {
            return 0;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($minutes > 59 || $hours > 99) {
            return 0;
        }

        return ($hours * 3600) + ($minutes * 60);
    }

    public static function isValidDuration(string $duration): bool
    {
        $duration = trim($duration);

        if ($duration === '') {
            return false;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $duration, $matches)) {
            return false;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        return $minutes <= 59 && $hours <= 99;
    }
}
