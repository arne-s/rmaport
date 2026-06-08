<?php

namespace App\Support\Planning;

use Illuminate\Support\Carbon;

/**
 * Week grid layout for planning calendar pages (read-only), matching {@see \App\Http\Livewire\AppointmentCalendarPicker}.
 */
final class PlanningCalendarWeekGrid
{
    private const PX_PER_HOUR = 45;

    private const GRID_START = 0;

    private const GRID_END = 23;

    private const WEEK_DAYS = 7;

    private const ALL_DAY_ROW_PX = 18;

    /**
     * @param  list<PlanningCalendarEvent>  $events
     * @param  list<string>  $visibleCategoryKeys
     * @return array{timed: array<string, list<array<string, mixed>>>, allDayPlaced: list<array<string, mixed>>}
     */
    public static function build(Carbon $weekStart, array $events, array $visibleCategoryKeys): array
    {
        $weekStart = $weekStart->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $filtered = self::filterEvents($events, $visibleCategoryKeys);

        $timedRaw = [];
        $allDaySpans = [];

        foreach ($filtered as $event) {
            if ($event->isAllDay) {
                $span = self::buildAllDaySpanForWeek($event, $weekStart);

                if ($span !== null) {
                    $allDaySpans[] = $span;
                }

                continue;
            }

            $date = $event->startsAt->toDateString();
            $gridTotalPx = (self::GRID_END - self::GRID_START + 1) * self::PX_PER_HOUR;
            $topPx = self::minutesToPx(($event->startsAt->hour - self::GRID_START) * 60 + $event->startsAt->minute);
            $minutesUntilMidnight = (24 - $event->startsAt->hour) * 60 - $event->startsAt->minute;
            $durationMinutes = min((int) $event->startsAt->diffInMinutes($event->endsAt), $minutesUntilMidnight);
            $heightPx = max(15, self::minutesToPx($durationMinutes));

            if ($topPx >= $gridTotalPx) {
                continue;
            }

            $heightPx = min($heightPx, $gridTotalPx - $topPx);
            $time = $event->startsAt->format('H:i');
            $title = trim($event->title);
            $categoryKey = $event->categoryKey ?? '';

            $timedRaw[$date][] = [
                'eventId' => $event->eventId,
                'time' => $time,
                'title' => $title,
                'description' => $event->description,
                'label' => trim($time.' '.$title),
                'topPx' => $topPx,
                'heightPx' => $heightPx,
                'color' => $event->color,
                'categoryKey' => $categoryKey,
                'stackKey' => self::appointmentStackKey($categoryKey, $title),
            ];
        }

        $timed = [];

        foreach ($timedRaw as $date => $items) {
            $timed[$date] = self::layoutOverlappingDayAppointments(self::dedupeDayAppointmentItems($items));
        }

        return [
            'timed' => $timed,
            'allDayPlaced' => self::layoutAllDaySpans($allDaySpans),
        ];
    }

    public static function allDaySectionHeightPx(array $allDayPlaced): int
    {
        if ($allDayPlaced === []) {
            return 0;
        }

        $maxRow = max(array_column($allDayPlaced, 'rowIndex'));

        return ($maxRow + 1) * self::ALL_DAY_ROW_PX;
    }

    public static function gridTotalPx(): int
    {
        return (self::GRID_END - self::GRID_START + 1) * self::PX_PER_HOUR;
    }

    public static function gridHours(): array
    {
        return range(self::GRID_START, self::GRID_END);
    }

    public static function pxPerHour(): int
    {
        return self::PX_PER_HOUR;
    }

    public static function workStartPx(): int
    {
        return 8 * self::PX_PER_HOUR;
    }

    public static function workEndPx(): int
    {
        return 17 * self::PX_PER_HOUR;
    }

    public static function scrollInitialPx(): int
    {
        return (int) round((5.5 * 60) * self::PX_PER_HOUR / 60);
    }

    public static function gridStartMinutes(): int
    {
        return self::GRID_START * 60;
    }

    /**
     * @param  list<PlanningCalendarEvent>  $events
     * @param  list<string>  $visibleCategoryKeys
     * @return list<PlanningCalendarEvent>
     */
    private static function filterEvents(array $events, array $visibleCategoryKeys): array
    {
        if ($visibleCategoryKeys === ['*']) {
            return $events;
        }

        if ($visibleCategoryKeys === []) {
            return [];
        }

        return array_values(array_filter(
            $events,
            static function (PlanningCalendarEvent $event) use ($visibleCategoryKeys): bool {
                if ($event->isAllDay) {
                    $key = $event->categoryKey;

                    return $key === null || in_array($key, $visibleCategoryKeys, true);
                }

                if ($event->categoryKey !== null) {
                    return in_array($event->categoryKey, $visibleCategoryKeys, true);
                }

                foreach ($event->displayCategoryItems() as $item) {
                    $itemKey = mb_strtolower(trim($item['name']));

                    if ($itemKey !== '' && in_array($itemKey, $visibleCategoryKeys, true)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @return array{eventId: ?string, title: string, color: string, startCol: int, endCol: int, colSpan: int}|null
     */
    private static function buildAllDaySpanForWeek(PlanningCalendarEvent $event, Carbon $weekStart): ?array
    {
        $weekFirst = $weekStart->copy()->startOfDay();
        $weekLast = $weekFirst->copy()->addDays(self::WEEK_DAYS - 1)->startOfDay();
        $rangeStart = $event->startsAt->copy()->startOfDay();
        $rangeEnd = $event->endsAt->copy()->startOfDay();

        if ($rangeEnd->lt($weekFirst) || $rangeStart->gt($weekLast)) {
            return null;
        }

        $clipStart = $rangeStart->greaterThan($weekFirst) ? $rangeStart : $weekFirst;
        $clipEnd = $rangeEnd->lessThan($weekLast) ? $rangeEnd : $weekLast;
        $startCol = (int) $weekFirst->diffInDays($clipStart);
        $colSpan = (int) $clipStart->diffInDays($clipEnd) + 1;

        return [
            'eventId' => $event->eventId,
            'title' => $event->title !== '' ? $event->title : 'Hele dag',
            'color' => $event->color,
            'startCol' => $startCol,
            'endCol' => $startCol + $colSpan - 1,
            'colSpan' => $colSpan,
        ];
    }

    /**
     * @param  list<array{eventId?: ?string, title: string, color: string, startCol: int, endCol: int, colSpan: int}>  $spans
     * @return list<array{title: string, color: string, startCol: int, colSpan: int, rowIndex: int}>
     */
    private static function layoutAllDaySpans(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $seen = [];
        $unique = [];

        foreach ($spans as $span) {
            $eventId = $span['eventId'] ?? null;

            if (is_string($eventId) && $eventId !== '') {
                if (isset($seen[$eventId])) {
                    continue;
                }

                $seen[$eventId] = true;
            }

            $unique[] = $span;
        }

        usort(
            $unique,
            fn (array $a, array $b): int => [$a['startCol'], -$a['colSpan']] <=> [$b['startCol'], -$b['colSpan']],
        );

        /** @var list<list<array{startCol: int, endCol: int}>> $rows */
        $rows = [];
        $placed = [];

        foreach ($unique as $span) {
            $startCol = (int) $span['startCol'];
            $endCol = (int) $span['endCol'];
            $rowIndex = null;

            foreach ($rows as $idx => $row) {
                $conflicts = false;

                foreach ($row as $existing) {
                    if (! ($endCol < $existing['startCol'] || $startCol > $existing['endCol'])) {
                        $conflicts = true;
                        break;
                    }
                }

                if (! $conflicts) {
                    $rowIndex = $idx;
                    $rows[$idx][] = ['startCol' => $startCol, 'endCol' => $endCol];
                    break;
                }
            }

            if ($rowIndex === null) {
                $rowIndex = count($rows);
                $rows[] = [['startCol' => $startCol, 'endCol' => $endCol]];
            }

            $placed[] = [
                'title' => (string) ($span['title'] ?? ''),
                'color' => (string) ($span['color'] ?? '#e8e8e8'),
                'startCol' => $startCol,
                'colSpan' => (int) $span['colSpan'],
                'rowIndex' => $rowIndex,
            ];
        }

        return $placed;
    }

    /**
     * @param  list<array{eventId?: ?string, label: string, topPx: int, heightPx: int, color: string}>  $items
     * @return list<array{label: string, topPx: int, heightPx: int, color: string}>
     */
    private static function dedupeDayAppointmentItems(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as $item) {
            $eventId = $item['eventId'] ?? null;

            if (is_string($eventId) && $eventId !== '') {
                $key = 'id:'.$eventId;
            } else {
                $key = 'slot:'.($item['topPx'] ?? 0).':'.($item['heightPx'] ?? 0).':'.($item['title'] ?? $item['label'] ?? '');
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            unset($item['eventId']);
            $unique[] = $item;
        }

        return $unique;
    }

    private static function appointmentStackKey(string $categoryKey, string $subject): string
    {
        $subject = trim($subject);

        if ($subject === '') {
            return '';
        }

        if (preg_match('/^reistijd\s*-\s*(.+)$/iu', $subject, $matches)) {
            $subject = trim($matches[1]);
        }

        return mb_strtolower(trim($categoryKey)).'|'.mb_strtolower($subject);
    }

    /**
     * @param  list<array{label: string, topPx: int, heightPx: int, color: string, stackKey?: string}>  $items
     * @return list<array<string, mixed>>
     */
    private static function layoutOverlappingDayAppointments(array $items): array
    {
        if ($items === []) {
            return [];
        }

        usort($items, fn (array $a, array $b): int => $a['topPx'] <=> $b['topPx']);

        /** @var list<array{top: int, bottom: int, indices: list<int>}> $units */
        $units = [];
        /** @var array<int, int> $indexToUnit */
        $indexToUnit = [];

        foreach ($items as $idx => $item) {
            if (isset($indexToUnit[$idx])) {
                continue;
            }

            $stackKey = (string) ($item['stackKey'] ?? '');
            $indices = [$idx];

            if ($stackKey !== '') {
                foreach ($items as $otherIdx => $other) {
                    if ($otherIdx === $idx || isset($indexToUnit[$otherIdx])) {
                        continue;
                    }

                    if ((string) ($other['stackKey'] ?? '') === $stackKey) {
                        $indices[] = $otherIdx;
                    }
                }
            }

            $unitId = count($units);
            $top = PHP_INT_MAX;
            $bottom = 0;

            foreach ($indices as $itemIdx) {
                $indexToUnit[$itemIdx] = $unitId;
                $top = min($top, $items[$itemIdx]['topPx']);
                $bottom = max($bottom, $items[$itemIdx]['topPx'] + $items[$itemIdx]['heightPx']);
            }

            $units[] = [
                'top' => $top,
                'bottom' => $bottom,
                'indices' => $indices,
            ];
        }

        usort($units, fn (array $a, array $b): int => $a['top'] <=> $b['top']);

        $activeEnds = [];
        $touchTolerancePx = 1;

        foreach ($units as $unitIdx => $unit) {
            $top = $unit['top'];
            $bottom = $unit['bottom'];

            foreach ($activeEnds as $col => $end) {
                if ($end <= $top + $touchTolerancePx) {
                    unset($activeEnds[$col]);
                }
            }

            $col = 0;

            while (isset($activeEnds[$col]) && $activeEnds[$col] > $top + $touchTolerancePx) {
                $col++;
            }

            $activeEnds[$col] = $bottom;
            $units[$unitIdx]['overlapCol'] = $col;
        }

        $maxCols = (int) max(array_column($units, 'overlapCol')) + 1;
        $span = $maxCols <= 1 ? 100.0 : 100.0 / $maxCols;

        return array_map(function (array $item, int $idx) use ($indexToUnit, $units, $span, $maxCols): array {
            $col = (int) ($units[$indexToUnit[$idx]]['overlapCol'] ?? 0);

            unset($item['stackKey'], $item['categoryKey']);

            if ($maxCols <= 1) {
                return $item + ['leftPct' => 0.0, 'widthPct' => 100.0];
            }

            return $item + [
                'leftPct' => $col * $span,
                'widthPct' => $span,
            ];
        }, $items, array_keys($items));
    }

    private static function minutesToPx(int $minutes): int
    {
        return max(0, (int) round($minutes * self::PX_PER_HOUR / 60));
    }
}
