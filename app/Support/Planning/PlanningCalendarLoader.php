<?php

namespace App\Support\Planning;

use App\Enums\AppointmentType;
use App\Http\Livewire\MicrosoftCategoryMappings;
use App\Models\Appointment;
use App\Models\MicrosoftCategoryMapping;
use App\Models\MicrosoftToken;
use App\Services\MicrosoftCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * @phpstan-type PlanningCategoryFilterGroup array{
 *     token_id: int,
 *     label: string,
 *     categories: list<array{key: string, name: string, color: string}>
 * }
 */

final class PlanningCalendarLoader
{
    private const FALLBACK_COLOR = '#e8e8e8';

    public const PER_PAGE = 50;

    private const LISTING_MONTHS_AHEAD = 6;

    public function defaultWeekStart(): Carbon
    {
        $now = Carbon::now();
        $monday = $now->copy()->startOfWeek(Carbon::MONDAY);

        if ($now->dayOfWeek === Carbon::SATURDAY || $now->dayOfWeek === Carbon::SUNDAY) {
            $monday->addWeek();
        }

        return $monday->startOfDay();
    }

    public function listingRangeStart(): Carbon
    {
        return $this->defaultWeekStart();
    }

    public function listingRangeEnd(): Carbon
    {
        return $this->listingRangeStart()->copy()->addMonths(self::LISTING_MONTHS_AHEAD)->endOfDay();
    }

    /**
     * @return list<PlanningCalendarEvent>
     */
    public function eventsForWeek(PlanningCalendarMode $mode, Carbon $weekStart): array
    {
        $weekStart = $weekStart->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $rangeEnd = $weekStart->copy()->addDays(6)->endOfDay();

        return $this->collectEventsForRange($mode, $weekStart, $rangeEnd);
    }

    public function hasLinkedTokens(PlanningCalendarMode $mode): bool
    {
        return $this->tokensForMode($mode)->isNotEmpty();
    }

    /**
     * @return list<string>
     */
    public function defaultVisibleCategoryKeys(PlanningCalendarMode $mode): array
    {
        return $this->visibleCategoryKeys($mode, $this->tokensForMode($mode));
    }

    /**
     * @return list<PlanningCategoryFilterGroup>
     */
    public function categoryGroupsForMode(PlanningCalendarMode $mode): array
    {
        $groups = [];

        foreach ($this->tokensForMode($mode) as $token) {
            $categories = $this->categoryFilterEntriesForToken($token);

            if ($categories === []) {
                continue;
            }

            $groups[] = [
                'token_id' => (int) $token->id,
                'label' => $token->getCalendarDisplayLabel(),
                'categories' => $categories,
            ];
        }

        return $groups;
    }

    public function loadPage(PlanningCalendarMode $mode, int $page): PlanningCalendarListPage
    {
        $page = max(1, $page);
        $rangeStart = $this->listingRangeStart();
        $rangeEnd = $this->listingRangeEnd();
        $tokens = $this->tokensForMode($mode);
        $hasLinkedTokens = $tokens->isNotEmpty();
        $events = $this->collectEventsForRange($mode, $rangeStart, $rangeEnd, $tokens);
        $occurrences = $this->expandToOccurrences($events);
        $total = count($occurrences);
        $offset = ($page - 1) * self::PER_PAGE;
        $pageOccurrences = array_slice($occurrences, $offset, self::PER_PAGE);
        $days = $this->buildDaysFromOccurrences($pageOccurrences);

        $from = $total === 0 ? 0 : $offset + 1;
        $to = $total === 0 ? 0 : min($offset + count($pageOccurrences), $total);

        return new PlanningCalendarListPage(
            hasLinkedTokens: $hasLinkedTokens,
            isEmpty: $total === 0,
            hasPreviousPage: $page > 1,
            hasNextPage: $offset + count($pageOccurrences) < $total,
            page: $page,
            pageLabel: $total === 0 ? '' : "{$from}–{$to} van {$total}",
            days: $days,
        );
    }

    /**
     * @param  Collection<int, MicrosoftToken>|null  $tokens
     * @return list<PlanningCalendarEvent>
     */
    private function collectEventsForRange(
        PlanningCalendarMode $mode,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        ?Collection $tokens = null,
    ): array {
        $rangeStart = $rangeStart->copy()->startOfDay();
        $rangeEnd = $rangeEnd->copy()->endOfDay();
        $tokens ??= $this->tokensForMode($mode);

        $outlookEvents = $tokens->isNotEmpty()
            ? $this->loadOutlookEvents($mode, $tokens, $rangeStart, $rangeEnd)
            : [];

        $events = [
            ...$outlookEvents,
            ...$this->loadLocalEvents($mode, $rangeStart, $rangeEnd),
        ];

        $events = $this->dedupeEvents($events);
        usort($events, fn (PlanningCalendarEvent $a, PlanningCalendarEvent $b): int => $a->startsAt <=> $b->startsAt);

        return $events;
    }

    /**
     * @param  list<PlanningCalendarEvent>  $events
     * @return list<array{date: Carbon, event: PlanningCalendarEvent}>
     */
    private function expandToOccurrences(array $events): array
    {
        $occurrences = [];

        foreach ($events as $event) {
            $day = $event->startsAt->copy()->startOfDay();
            $lastDay = $event->endsAt->copy()->startOfDay();

            while ($day->lte($lastDay)) {
                $occurrences[] = [
                    'date' => $day->copy(),
                    'event' => $event,
                ];
                $day->addDay();
            }
        }

        usort(
            $occurrences,
            function (array $a, array $b): int {
                $dateCompare = $a['date'] <=> $b['date'];

                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return $a['event']->startsAt <=> $b['event']->startsAt;
            },
        );

        return $occurrences;
    }

    /**
     * @param  list<array{date: Carbon, event: PlanningCalendarEvent}>  $occurrences
     * @return list<PlanningCalendarDay>
     */
    private function buildDaysFromOccurrences(array $occurrences): array
    {
        if ($occurrences === []) {
            return [];
        }

        /** @var array<string, array{date: Carbon, events: list<PlanningCalendarEvent>}> $grouped */
        $grouped = [];

        foreach ($occurrences as $occurrence) {
            $dateKey = $occurrence['date']->toDateString();

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $occurrence['date'],
                    'events' => [],
                ];
            }

            $grouped[$dateKey]['events'][] = $occurrence['event'];
        }

        ksort($grouped);

        $today = Carbon::today()->toDateString();
        $days = [];

        foreach ($grouped as $group) {
            $date = $group['date'];
            $dayEvents = $group['events'];
            usort($dayEvents, fn (PlanningCalendarEvent $a, PlanningCalendarEvent $b): int => $a->startsAt <=> $b->startsAt);

            $days[] = new PlanningCalendarDay(
                date: $date,
                label: $date->translatedFormat('l j F'),
                isToday: $date->toDateString() === $today,
                events: $dayEvents,
            );
        }

        return $days;
    }

    /**
     * @return Collection<int, MicrosoftToken>
     */
    private function tokensForMode(PlanningCalendarMode $mode): Collection
    {
        return match ($mode) {
            PlanningCalendarMode::My => collect([
                MicrosoftToken::resolveForRoleName('advisor'),
                MicrosoftToken::resolveForRoleName('mechanic'),
            ])->filter()->keyBy('id')->values(),
            PlanningCalendarMode::General => collect([MicrosoftToken::resolveForRoleName('advisor')])->filter()->values(),
            PlanningCalendarMode::Mechanic => collect([MicrosoftToken::resolveForRoleName('mechanic')])->filter()->values(),
        };
    }

    /**
     * @param  Collection<int, MicrosoftToken>  $tokens
     * @return list<string>
     */
    private function visibleCategoryKeys(PlanningCalendarMode $mode, Collection $tokens): array
    {
        if ($mode === PlanningCalendarMode::My) {
            return $this->myCalendarCategoryKeys($tokens);
        }

        $keys = [];

        foreach ($tokens as $token) {
            $keys = [...$keys, ...array_keys($this->categoryPaletteForToken($token))];
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  Collection<int, MicrosoftToken>  $tokens
     * @return list<string>
     */
    private function myCalendarCategoryKeys(Collection $tokens): array
    {
        $userId = Auth::id();

        if ($userId === null) {
            return [];
        }

        $keys = [];

        foreach ($tokens as $token) {
            $mappingKey = $this->mappingKeyForUser($token, (int) $userId);

            if ($mappingKey !== null) {
                $keys[] = $mappingKey;
            }

            $generalKey = mb_strtolower(trim((string) ($token->general_category_name ?? '')));

            if ($generalKey !== '') {
                $keys[] = $generalKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  Collection<int, MicrosoftToken>  $tokens
     * @return list<PlanningCalendarEvent>
     */
    private function loadOutlookEvents(
        PlanningCalendarMode $mode,
        Collection $tokens,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): array {
        if ($mode === PlanningCalendarMode::My) {
            return $this->loadOutlookEventsForMyAgenda($tokens, $rangeStart, $rangeEnd);
        }

        $visibleKeys = $this->visibleCategoryKeys($mode, $tokens);

        if ($visibleKeys === []) {
            return [];
        }

        $service = app(MicrosoftCalendarService::class);
        $events = [];

        foreach ($tokens as $token) {
            $palette = $this->categoryPaletteForToken($token);
            $displayNames = $this->categoryDisplayNamesForToken($token);
            $allowedKeys = array_values(array_intersect($visibleKeys, array_keys($palette)));

            if ($allowedKeys === []) {
                continue;
            }

            foreach ($service->getWeekEvents($token->id, $rangeStart, $rangeEnd) as $event) {
                $parsed = $this->mapOutlookEvent($event, $token, $palette, $displayNames, $allowedKeys, $rangeStart, $rangeEnd);

                if ($parsed !== null) {
                    $events[] = $parsed;
                }
            }
        }

        return $events;
    }

    /**
     * @param  Collection<int, MicrosoftToken>  $tokens
     * @return list<PlanningCalendarEvent>
     */
    private function loadOutlookEventsForMyAgenda(Collection $tokens, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $service = app(MicrosoftCalendarService::class);
        $events = [];

        foreach ($tokens as $token) {
            $palette = $this->categoryPaletteForToken($token);
            $displayNames = $this->categoryDisplayNamesForToken($token);
            $allKeys = array_keys($palette);

            if ($allKeys === []) {
                continue;
            }

            /** @var array<string, list<PlanningCalendarEvent>> $slotGroups */
            $slotGroups = [];

            foreach ($service->getWeekEvents($token->id, $rangeStart, $rangeEnd) as $event) {
                if ($this->isOutlookAllDayEvent($event)) {
                    $parsed = $this->mapOutlookEvent($event, $token, $palette, $displayNames, $allKeys, $rangeStart, $rangeEnd);

                    if ($parsed !== null) {
                        $events[] = $parsed;
                    }

                    continue;
                }

                $parsed = $this->mapOutlookEvent($event, $token, $palette, $displayNames, $allKeys, $rangeStart, $rangeEnd);

                if ($parsed === null) {
                    continue;
                }

                $slotGroups[$this->eventOccurrenceSlotKey($parsed)][] = $parsed;
            }

            foreach ($slotGroups as $slotEvents) {
                foreach ($slotEvents as $slotEvent) {
                    $events[] = $slotEvent;
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $palette
     * @param  array<string, string>  $displayNames
     * @param  list<string>  $allowedKeys
     */
    private function mapOutlookEvent(
        array $event,
        MicrosoftToken $token,
        array $palette,
        array $displayNames,
        array $allowedKeys,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): ?PlanningCalendarEvent {
        $eventCategories = is_array($event['categories'] ?? null) ? $event['categories'] : [];
        $categoryKey = $this->resolveOutlookEventCategoryKey($eventCategories, $palette);
        $isAllDay = $this->isOutlookAllDayEvent($event);

        if ($isAllDay) {
            if ($categoryKey === null || ! in_array($categoryKey, $allowedKeys, true)) {
                $generalKey = mb_strtolower(trim((string) ($token->general_category_name ?? '')));

                if ($generalKey === '' || ! in_array($generalKey, $allowedKeys, true)) {
                    return null;
                }

                $categoryKey = $generalKey;
            }

            $range = $this->outlookAllDayInclusiveDateRange($event);

            if ($range === null) {
                return null;
            }

            $listingFirst = $rangeStart->copy()->startOfDay();
            $listingLast = $rangeEnd->copy()->startOfDay();

            if ($range['end']->lt($listingFirst) || $range['start']->gt($listingLast)) {
                return null;
            }

            $clipStart = $range['start']->greaterThan($listingFirst) ? $range['start'] : $listingFirst;
            $clipEnd = $range['end']->lessThan($listingLast) ? $range['end'] : $listingLast;
            $subject = is_string($event['subject'] ?? null) ? trim($event['subject']) : '';

            return new PlanningCalendarEvent(
                startsAt: $clipStart->copy()->startOfDay(),
                endsAt: $clipEnd->copy()->endOfDay(),
                title: $subject !== '' ? $subject : 'Hele dag',
                description: $this->outlookEventDescription($subject, $this->outlookEventBodyPreview($event)),
                categoryName: $displayNames[$categoryKey] ?? $categoryKey,
                color: $palette[$categoryKey] ?? self::FALLBACK_COLOR,
                isAllDay: true,
                eventId: is_string($event['id'] ?? null) ? $event['id'] : null,
                categoryKey: $categoryKey,
            );
        }

        if ($categoryKey === null || ! in_array($categoryKey, $allowedKeys, true)) {
            return null;
        }

        $startDt = $this->parseGraphDateTimeToAppTimezone(is_array($event['start'] ?? null) ? $event['start'] : []);
        $endDt = $this->parseGraphDateTimeToAppTimezone(is_array($event['end'] ?? null) ? $event['end'] : []);

        if ($startDt->gt($rangeEnd) || $endDt->lt($rangeStart)) {
            return null;
        }

        $subject = is_string($event['subject'] ?? null) ? trim($event['subject']) : '';

        return new PlanningCalendarEvent(
            startsAt: $startDt,
            endsAt: $endDt->greaterThan($startDt) ? $endDt : $startDt->copy()->addHour(),
            title: $subject !== '' ? $subject : 'Afspraak',
            description: $this->outlookEventDescription($subject, $this->outlookEventBodyPreview($event)),
            categoryName: $displayNames[$categoryKey] ?? $categoryKey,
            color: $palette[$categoryKey] ?? self::FALLBACK_COLOR,
            isAllDay: false,
            eventId: is_string($event['id'] ?? null) ? $event['id'] : null,
            categoryKey: $categoryKey,
        );
    }

    /**
     * @return list<PlanningCalendarEvent>
     */
    private function loadLocalEvents(PlanningCalendarMode $mode, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $query = Appointment::query()
            ->with(['order', 'advisors', 'mechanics'])
            ->whereBetween('datetime', [$rangeStart, $rangeEnd]);

        match ($mode) {
            PlanningCalendarMode::My => $query->whereIn('type', [
                AppointmentType::Fitting->value,
                AppointmentType::Delivery->value,
                AppointmentType::Service->value,
            ]),
            PlanningCalendarMode::General => $query->whereIn('type', [
                AppointmentType::Fitting->value,
                AppointmentType::Delivery->value,
            ]),
            PlanningCalendarMode::Mechanic => $query->where('type', AppointmentType::Service->value),
        };

        $events = [];

        foreach ($query->orderBy('datetime')->get() as $appointment) {
            $dt = $appointment->datetime;
            $title = trim((string) ($appointment->title ?? ''));

            if ($title === '') {
                $title = ucfirst((string) $appointment->type?->value);
            }

            $categoryItems = $mode === PlanningCalendarMode::My
                ? $this->categoryItemsForAppointmentAssignees($appointment)
                : [];

            $primaryCategory = $categoryItems[0] ?? null;

            $events[] = new PlanningCalendarEvent(
                startsAt: $dt->copy(),
                endsAt: $dt->copy()->addHour(),
                title: $title,
                description: trim(strip_tags((string) ($appointment->description ?? ''))),
                categoryName: $primaryCategory['name'] ?? ucfirst((string) $appointment->type?->value),
                color: $primaryCategory['color'] ?? self::FALLBACK_COLOR,
                isAllDay: false,
                eventId: 'local-' . $appointment->getKey(),
                categoryItems: $categoryItems,
                colors: array_values(array_unique(array_column($categoryItems, 'color'))),
            );
        }

        return $events;
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    private function categoryItemsForAppointmentAssignees(Appointment $appointment): array
    {
        $assigneeIds = $appointment->advisors
            ->pluck('id')
            ->merge($appointment->mechanics->pluck('id'))
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($assigneeIds === []) {
            return [];
        }

        $tokens = collect([
            MicrosoftToken::resolveForRoleName('advisor'),
            MicrosoftToken::resolveForRoleName('mechanic'),
        ])->filter();

        /** @var array<string, array{name: string, color: string}> $items */
        $items = [];

        foreach ($tokens as $token) {
            $palette = $this->categoryPaletteForToken($token);
            $displayNames = $this->categoryDisplayNamesForToken($token);

            foreach ($assigneeIds as $userId) {
                $mappingKey = $this->mappingKeyForUser($token, $userId);

                if ($mappingKey === null) {
                    continue;
                }

                $name = $displayNames[$mappingKey] ?? $mappingKey;
                $items[mb_strtolower($name)] = [
                    'name' => $name,
                    'color' => $palette[$mappingKey] ?? self::FALLBACK_COLOR,
                ];
            }
        }

        return array_values($items);
    }

    /**
     * @param  list<PlanningCalendarEvent>  $events
     * @return list<PlanningCalendarEvent>
     */
    private function dedupeEvents(array $events): array
    {
        /** @var array<string, array{event: PlanningCalendarEvent, colors: list<string>, categories: array<string, array{name: string, color: string}>}> $groups */
        $groups = [];

        foreach ($events as $event) {
            $slotKey = $this->eventOccurrenceSlotKey($event);

            if (! isset($groups[$slotKey])) {
                $groups[$slotKey] = [
                    'event' => $event,
                    'colors' => $event->displayColors(),
                    'categories' => $this->categoryItemsKeyedByName($event),
                ];

                continue;
            }

            foreach ($event->displayColors() as $color) {
                if (! in_array($color, $groups[$slotKey]['colors'], true)) {
                    $groups[$slotKey]['colors'][] = $color;
                }
            }

            foreach ($this->categoryItemsKeyedByName($event) as $categoryKey => $categoryItem) {
                $groups[$slotKey]['categories'][$categoryKey] = $categoryItem;
            }
        }

        $unique = [];

        foreach ($groups as $group) {
            $event = $group['event'];
            $colors = $group['colors'];
            $categoryItems = array_values($group['categories']);

            $unique[] = new PlanningCalendarEvent(
                startsAt: $event->startsAt,
                endsAt: $event->endsAt,
                title: $event->title,
                description: $event->description,
                categoryName: $categoryItems[0]['name'] ?? '',
                color: $colors[0] ?? $event->color,
                isAllDay: $event->isAllDay,
                eventId: $event->eventId,
                categoryKey: $event->categoryKey,
                colors: $colors,
                categoryItems: $categoryItems,
            );
        }

        return $unique;
    }

    private function eventOccurrenceSlotKey(PlanningCalendarEvent $event): string
    {
        return 'slot:'
            . $event->startsAt->toIso8601String() . ':'
            . $event->endsAt->toIso8601String() . ':'
            . ($event->isAllDay ? '1' : '0') . ':'
            . mb_strtolower(trim($event->title));
    }

    /**
     * @return array<string, array{name: string, color: string}>
     */
    private function categoryItemsKeyedByName(PlanningCalendarEvent $event): array
    {
        $keyed = [];

        foreach ($event->displayCategoryItems() as $item) {
            $name = trim($item['name']);

            if ($name === '') {
                continue;
            }

            $keyed[mb_strtolower($name)] = [
                'name' => $name,
                'color' => $item['color'],
            ];
        }

        return $keyed;
    }

    private function mappingKeyForUser(MicrosoftToken $token, int $userId): ?string
    {
        $mapping = MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->first();

        if ($mapping === null) {
            return null;
        }

        $key = mb_strtolower(trim($mapping->category_name));

        return $key !== '' ? $key : null;
    }

    /**
     * @return array<string, string>
     */
    private function categoryPaletteForToken(MicrosoftToken $token): array
    {
        $palette = [];

        foreach (MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->get() as $mapping) {
            $key = mb_strtolower(trim($mapping->category_name));

            if ($key === '') {
                continue;
            }

            $palette[$key] = filled($mapping->hex_color)
                ? (string) $mapping->hex_color
                : (MicrosoftCategoryMappings::PRESET_COLORS[$mapping->category_color ?? 'none'] ?? self::FALLBACK_COLOR);
        }

        foreach (app(MicrosoftCalendarService::class)->getCategories($token->id) as $apiCategory) {
            $name = trim((string) ($apiCategory['displayName'] ?? ''));
            $key = mb_strtolower($name);

            if ($key === '' || isset($palette[$key])) {
                continue;
            }

            $palette[$key] = MicrosoftCategoryMappings::PRESET_COLORS[$apiCategory['color'] ?? 'none']
                ?? self::FALLBACK_COLOR;
        }

        return $palette;
    }

    /**
     * @return list<array{key: string, name: string, color: string}>
     */
    private function categoryFilterEntriesForToken(MicrosoftToken $token): array
    {
        $palette = $this->categoryPaletteForToken($token);
        $displayNames = $this->categoryDisplayNamesForToken($token);
        $entries = [];

        foreach ($palette as $key => $color) {
            $entries[] = [
                'key' => $key,
                'name' => $displayNames[$key] ?? $key,
                'color' => $color,
            ];
        }

        usort($entries, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function categoryDisplayNamesForToken(MicrosoftToken $token): array
    {
        $displayNames = [];

        foreach (MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->orderBy('category_name')
            ->get() as $mapping) {
            $key = mb_strtolower(trim($mapping->category_name));

            if ($key === '') {
                continue;
            }

            $displayNames[$key] = trim($mapping->category_name);
        }

        foreach (app(MicrosoftCalendarService::class)->getCategories($token->id) as $apiCategory) {
            $name = trim((string) ($apiCategory['displayName'] ?? ''));
            $key = mb_strtolower($name);

            if ($key === '') {
                continue;
            }

            $displayNames[$key] ??= $name;
        }

        return $displayNames;
    }

    /**
     * @param  list<mixed>  $eventCategories
     * @param  array<string, string>  $categoryColors
     */
    private function resolveOutlookEventCategoryKey(array $eventCategories, array $categoryColors): ?string
    {
        foreach ($eventCategories as $cat) {
            $key = mb_strtolower(trim((string) $cat));

            if ($key !== '' && isset($categoryColors[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isOutlookAllDayEvent(array $event): bool
    {
        if (($event['isAllDay'] ?? false) === true) {
            return true;
        }

        $startRaw = is_array($event['start'] ?? null)
            ? ($event['start']['dateTime'] ?? '')
            : '';

        return is_string($startRaw) && strlen($startRaw) === 10 && ! str_contains($startRaw, 'T');
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{start: Carbon, end: Carbon}|null
     */
    private function outlookAllDayInclusiveDateRange(array $event): ?array
    {
        if (! $this->isOutlookAllDayEvent($event)) {
            return null;
        }

        $start = $this->parseGraphDateTimeToAppTimezone(is_array($event['start'] ?? null) ? $event['start'] : [])->startOfDay();
        $endExclusive = $this->parseGraphDateTimeToAppTimezone(is_array($event['end'] ?? null) ? $event['end'] : [])->startOfDay();

        if ($endExclusive->greaterThan($start)) {
            $endInclusive = $endExclusive->copy()->subDay();
        } else {
            $endInclusive = $start->copy();
        }

        return ['start' => $start, 'end' => $endInclusive];
    }

    /**
     * @param  array<string, mixed>  $fragment
     */
    private function parseGraphDateTimeToAppTimezone(array $fragment): Carbon
    {
        $raw = $fragment['dateTime'] ?? '';

        if (! is_string($raw) || $raw === '') {
            return Carbon::now();
        }

        $graphTz = MicrosoftCalendarService::toIanaTimezone($fragment['timeZone'] ?? 'UTC');
        $appTz = config('app.timezone');

        if (! is_string($appTz) || $appTz === '') {
            $appTz = 'Europe/Amsterdam';
        }

        if (strlen($raw) === 10 && ! str_contains($raw, 'T')) {
            return Carbon::parse($raw . ' 00:00:00', $graphTz)->startOfDay()->timezone($appTz);
        }

        if (preg_match('/Z$/i', trim($raw)) || preg_match('/[+\-]\d{2}:\d{2}$/', trim($raw))) {
            return Carbon::parse($raw)->timezone($appTz);
        }

        return Carbon::parse($raw, $graphTz)->timezone($appTz);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function outlookEventBodyPreview(array $event): string
    {
        $preview = $event['bodyPreview'] ?? '';

        if (! is_string($preview) || trim($preview) === '') {
            return '';
        }

        $text = trim(html_entity_decode(strip_tags($preview), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_replace('/\s+/u', ' ', $text) ?? '';
    }

    private function outlookEventDescription(string $subject, string $bodyPreview): string
    {
        if ($bodyPreview === '') {
            return '';
        }

        $subjectNormalized = mb_strtolower(trim($subject));
        $previewNormalized = mb_strtolower($bodyPreview);

        if ($subjectNormalized !== '' && $previewNormalized === $subjectNormalized) {
            return '';
        }

        return $bodyPreview;
    }
}
