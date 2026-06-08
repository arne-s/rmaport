<?php

namespace App\Support\Planning;

use Illuminate\Support\Carbon;

/**
 * Builds visible day columns for appointment / planning week grids (Mon–Sun, optionally hiding weekends).
 */
final class AppointmentCalendarVisibleWeek
{
    private const int WEEK_DAYS = 7;

    /**
     * @return list<array{date: string, label: string, isToday: bool, isPast: bool, isWeekend: bool}>
     */
    public static function buildDays(Carbon $weekStart, bool $showWeekend, string $todayDate): array
    {
        $weekStartCarbon = $weekStart->copy()->startOfWeek(Carbon::MONDAY);
        $days = [];

        for ($i = 0; $i < self::WEEK_DAYS; $i++) {
            $day = $weekStartCarbon->copy()->addDays($i);
            $isWeekend = $day->dayOfWeek === Carbon::SATURDAY || $day->dayOfWeek === Carbon::SUNDAY;

            if (! $showWeekend && $isWeekend) {
                continue;
            }

            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->translatedFormat('D d-m'),
                'isToday' => $day->isToday(),
                'isPast' => $day->toDateString() < $todayDate,
                'isWeekend' => $isWeekend,
            ];
        }

        return $days;
    }

    public static function weekLabel(Carbon $weekStart, bool $showWeekend): string
    {
        $days = self::buildDays($weekStart, $showWeekend, Carbon::now(config('app.timezone', 'Europe/Amsterdam'))->toDateString());

        if ($days === []) {
            return $weekStart->copy()->startOfWeek(Carbon::MONDAY)->translatedFormat('d M Y');
        }

        $first = Carbon::parse($days[0]['date']);
        $last = Carbon::parse($days[array_key_last($days)]['date']);

        return $first->translatedFormat('d M').' – '.$last->translatedFormat('d M Y');
    }

    /**
     * Maps a full-week column index (Mon=0 … Sun=6) to the visible grid column, or null when hidden.
     */
    public static function visibleColumnIndex(int $fullWeekColumnIndex, bool $showWeekend): ?int
    {
        if ($showWeekend) {
            return $fullWeekColumnIndex;
        }

        if ($fullWeekColumnIndex < 0 || $fullWeekColumnIndex > 4) {
            return null;
        }

        return $fullWeekColumnIndex;
    }

    /**
     * @param  list<array{title: string, color: string, startCol: int, colSpan: int, rowIndex: int, endCol?: int}>  $allDayPlaced
     * @return list<array{title: string, color: string, startCol: int, colSpan: int, rowIndex: int}>
     */
    public static function remapAllDayPlaced(array $allDayPlaced, bool $showWeekend): array
    {
        if ($showWeekend) {
            return $allDayPlaced;
        }

        $remapped = [];

        foreach ($allDayPlaced as $item) {
            $startCol = (int) ($item['startCol'] ?? 0);
            $endCol = (int) ($item['endCol'] ?? ($startCol + (int) ($item['colSpan'] ?? 1) - 1));

            $visibleStart = null;
            $visibleEnd = null;

            for ($col = $startCol; $col <= $endCol; $col++) {
                $mapped = self::visibleColumnIndex($col, false);

                if ($mapped === null) {
                    continue;
                }

                $visibleStart = $visibleStart === null ? $mapped : min($visibleStart, $mapped);
                $visibleEnd = $visibleEnd === null ? $mapped : max($visibleEnd, $mapped);
            }

            if ($visibleStart === null || $visibleEnd === null) {
                continue;
            }

            $remapped[] = [
                'title' => $item['title'],
                'color' => $item['color'],
                'startCol' => $visibleStart,
                'colSpan' => $visibleEnd - $visibleStart + 1,
                'rowIndex' => $item['rowIndex'],
            ];
        }

        return $remapped;
    }
}
