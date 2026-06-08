<?php

namespace App\Http\Livewire;

use App\Enums\AppointmentType;
use App\Http\Livewire\MicrosoftCategoryMappings;
use App\Models\MicrosoftCategoryMapping;
use App\Models\MicrosoftToken;
use App\Services\MicrosoftCalendarService;
use App\Support\Planning\AppointmentCalendarVisibleWeek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class AppointmentCalendarPicker extends Component
{
    public ?int $advisorId = null;

    public string $appointmentTypeValue = AppointmentType::Fitting->value;

    /**
     * Legacy: explicit workshop category selection from the appointment form.
     * The calendar ignores this and colors workshop employees by person mapping.
     */
    public ?int $outlookCategoryMappingId = null;

    /** @var list<string> Normalised Outlook category keys (lowercase) shown on the grid. */
    public array $visibleCategoryKeys = [];

    /** @var array<int> Selected mechanic user IDs (service appointments). */
    public array $mechanicUserIds = [];

    /** @var array<int> Selected advisor user IDs (passing / delivery appointments). */
    public array $advisorUserIds = [];

    /**
     * Monday (Y-m-d) of the visible week. Nullable so Livewire hydration never assigns null to a non-nullable string (PHP 8.4).
     */
    public ?string $weekStart = null;

    public ?string $selectedDate = null;

    public string $timeFrom = '09:00';

    public string $timeTo = '10:00';

    /** Show the blue selection on the grid only after the user drags (not from form prefill). */
    public bool $showGridSelection = false;

    public bool $readOnly = false;

    public bool $showWeekend = false;

    /** @var list<array{token_id: int, label: string, categories: list<array{key: string, name: string, color: string}>}> */
    public array $categoryGroupsCache = [];

    public string $categoryGroupsCacheFingerprint = '';

    /** Tracks advisor/mechanic selection so category visibility resets when assignees change. */
    public string $lastAssigneeFilterFingerprint = '';

    /**
     * @var array{timed: array<string, list<array<string, mixed>>>, allDayPlaced: list<array<string, mixed>>}|null
     */
    private ?array $appointmentsCache = null;

    private string $appointmentsCacheKey = '';

    private const FALLBACK_COLOR = '#e8e8e8';

    /** @var array<int, array<string, string>> */
    private array $categoryPaletteMemo = [];

    /** Grid: 45 px per hour, full day 00:00–24:00 (hour labels 0–23) */
    private const PX_PER_HOUR = 45;

    private const GRID_START = 0;

    private const GRID_END = 23;

    private const WORK_START_HOUR = 8;

    private const WORK_END_HOUR = 17;

    /** Initial scroll position: 07:30. */
    private const SCROLL_INITIAL_MINUTES = 450;

    private const WEEK_DAYS = 7;

    private const ALL_DAY_ROW_PX = 18;

    private const ALL_DAY_EVENT_PX = 16;

    public function mount(?int $advisorId = null, ?string $weekStart = null): void
    {
        if ($advisorId !== null) {
            $this->advisorId = $advisorId;
        }

        if (filled($weekStart)) {
            $this->weekStart = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY)->toDateString();
        } else {
            $this->weekStart = $this->computeDefaultWeekStartString();
        }

        $this->syncVisibleCategoryKeys();
    }

    public function hydrate(): void
    {
        if (! filled($this->weekStart)) {
            $this->weekStart = $this->computeDefaultWeekStartString();
        }
    }

    private function computeDefaultWeekStartString(): string
    {
        $now = Carbon::now();
        $monday = $now->copy()->startOfWeek(Carbon::MONDAY);

        if ($now->dayOfWeek === Carbon::SATURDAY || $now->dayOfWeek === Carbon::SUNDAY) {
            $monday->addWeek();
        }

        return $monday->toDateString();
    }

    private function resolvedWeekStart(): string
    {
        if (filled($this->weekStart)) {
            return $this->weekStart;
        }

        return $this->computeDefaultWeekStartString();
    }

    private function isService(): bool
    {
        return AppointmentType::from($this->appointmentTypeValue) === AppointmentType::Service;
    }

    private function calendarCanLoad(): bool
    {
        return true;
    }

    private function hasSelectedAssignees(): bool
    {
        return $this->advisorUserIds !== [] || $this->mechanicUserIds !== [];
    }

    private function assigneeFilterFingerprint(): string
    {
        return implode(',', $this->advisorUserIds) . '|' . implode(',', $this->mechanicUserIds);
    }

    /**
     * Category keys available in the filter UI and used as default visibility.
     *
     * @return list<string>
     */
    private function filterCategoryKeysForPicker(): array
    {
        $allKeys = $this->allCategoryKeys();

        if (! $this->hasSelectedAssignees()) {
            return $allKeys;
        }

        return array_values(array_intersect($this->selectedFormCategoryKeys(), $allKeys));
    }

    private function syncVisibleCategoryKeys(): void
    {
        if ($this->pickerTokenIds() === []) {
            $this->visibleCategoryKeys = [];
            $this->lastAssigneeFilterFingerprint = '';

            return;
        }

        $pickerKeys = $this->filterCategoryKeysForPicker();
        $fingerprint = $this->assigneeFilterFingerprint();

        if (
            $fingerprint !== $this->lastAssigneeFilterFingerprint
            || $this->visibleCategoryKeys === []
        ) {
            $this->lastAssigneeFilterFingerprint = $fingerprint;
            $this->visibleCategoryKeys = $pickerKeys;

            return;
        }

        $visible = array_values(array_intersect($this->visibleCategoryKeys, $pickerKeys));

        $this->visibleCategoryKeys = $visible !== [] ? $visible : $pickerKeys;
    }

    /**
     * Category keys from selected advisors/workshop plus configured general categories.
     *
     * @return list<string>
     */
    private function selectedFormCategoryKeys(): array
    {
        $keys = [];

        $advisorToken = MicrosoftToken::resolveForRoleName('advisor');

        if ($advisorToken !== null) {
            foreach ($this->advisorUserIds as $userId) {
                $key = $this->mappingKeyForUser($advisorToken, (int) $userId);

                if ($key !== null && ! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        $mechanicToken = MicrosoftToken::resolveForRoleName('mechanic');

        if ($mechanicToken !== null) {
            foreach ($this->mechanicUserIds as $userId) {
                $key = $this->mappingKeyForUser($mechanicToken, (int) $userId);

                if ($key !== null && ! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        foreach ($this->generalCategoryKeysFromTokens() as $key) {
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
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
     * @return list<string>
     */
    private function generalCategoryKeysFromTokens(): array
    {
        $keys = [];

        foreach ([MicrosoftToken::resolveForRoleName('advisor'), MicrosoftToken::resolveForRoleName('mechanic')] as $token) {
            if (! $token instanceof MicrosoftToken) {
                continue;
            }

            $key = mb_strtolower(trim((string) ($token->general_category_name ?? '')));

            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function effectiveVisibleCategoryKeys(): array
    {
        if ($this->visibleCategoryKeys === []) {
            return $this->filterCategoryKeysForPicker();
        }

        return $this->visibleCategoryKeys;
    }

    /**
     * @return list<string>
     */
    private function allCategoryKeys(): array
    {
        $keys = [];

        foreach ($this->getTokensForPicker() as $token) {
            $keys = [...$keys, ...array_keys($this->categoryPaletteForToken($token))];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Outlook master categories (API) merged with ERP mappings — events are hidden when
     * their category is missing from this palette.
     *
     * @return array<string, string> normalised key (lower) => hex color
     */
    private function categoryPaletteForToken(MicrosoftToken $token): array
    {
        $tokenId = (int) $token->id;

        if (isset($this->categoryPaletteMemo[$tokenId])) {
            return $this->categoryPaletteMemo[$tokenId];
        }

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

        $this->categoryPaletteMemo[$tokenId] = $palette;

        return $palette;
    }

    private function categoryGroupsFingerprint(): string
    {
        return implode('|', [
            implode(',', $this->advisorUserIds),
            implode(',', $this->mechanicUserIds),
            implode(',', $this->pickerTokenIds()),
        ]);
    }

    private function resetCategoryGroupsCache(): void
    {
        $this->categoryGroupsCache = [];
        $this->categoryGroupsCacheFingerprint = '';
        $this->categoryPaletteMemo = [];
        $this->invalidateAppointmentsCache();
    }

    private function invalidateAppointmentsCache(): void
    {
        $this->appointmentsCache = null;
        $this->appointmentsCacheKey = '';
    }

    private function appointmentsCacheKey(Carbon $weekStart): string
    {
        return implode('|', [
            $weekStart->toDateString(),
            implode(',', $this->effectiveVisibleCategoryKeys()),
            implode(',', $this->advisorUserIds),
            implode(',', $this->mechanicUserIds),
            implode(',', $this->pickerTokenIds()),
        ]);
    }

    /**
     * @return array{timed: array<string, list<array<string, mixed>>>, allDayPlaced: list<array<string, mixed>>}
     */
    private function resolvedCalendarAppointments(Carbon $weekStart): array
    {
        $key = $this->appointmentsCacheKey($weekStart);

        if ($this->appointmentsCache !== null && $this->appointmentsCacheKey === $key) {
            return $this->appointmentsCache;
        }

        $this->appointmentsCacheKey = $key;
        $this->appointmentsCache = $this->loadAppointmentsByDay($weekStart);

        return $this->appointmentsCache;
    }

    /**
     * @return list<array{token_id: int, label: string, categories: list<array{key: string, name: string, color: string}>}>
     */
    private function resolveCategoryGroupsForRender(): array
    {
        $fingerprint = $this->categoryGroupsFingerprint();

        if ($this->categoryGroupsCache !== [] && $this->categoryGroupsCacheFingerprint === $fingerprint) {
            return $this->categoryGroupsCache;
        }

        $this->categoryGroupsCacheFingerprint = $fingerprint;
        $this->categoryGroupsCache = $this->loadCategoriesForFilter();

        return $this->categoryGroupsCache;
    }

    /**
     * @return list<array{key: string, name: string, color: string}>
     */
    private function categoryFilterEntriesForToken(MicrosoftToken $token): array
    {
        $palette = $this->categoryPaletteForToken($token);
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

        $entries = [];

        foreach ($palette as $key => $color) {
            $entries[] = [
                'key' => $key,
                'name' => $displayNames[$key] ?? $key,
                'color' => $color,
            ];
        }

        usort($entries, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        if (! $this->hasSelectedAssignees()) {
            return $entries;
        }

        $allowedKeys = $this->filterCategoryKeysForPicker();

        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => in_array($entry['key'], $allowedKeys, true),
        ));
    }

    /**
     * @return list<array{token_id: int, label: string, categories: list<array{key: string, name: string, color: string}>}>
     */
    private function loadCategoriesForFilter(): array
    {
        $tokens = $this->getTokensForPicker();

        if ($tokens->isEmpty()) {
            return [];
        }

        $groups = [];

        foreach ($tokens as $token) {
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

    /**
     * @param  list<array{token_id: int, label: string, categories: list<array{key: string, name: string, color: string}>}>  $categoryGroups
     */
    private function countCategoryFilterItems(array $categoryGroups): int
    {
        $count = 0;

        foreach ($categoryGroups as $group) {
            $count += count($group['categories'] ?? []);
        }

        return $count;
    }

    /**
     * @param  list<array{token_id: int, label: string, categories: list<array{key: string, name: string, color: string}>}>  $categoryGroups
     * @return list<string>
     */
    private function categoryFilterKeys(array $categoryGroups): array
    {
        $keys = [];

        foreach ($categoryGroups as $group) {
            foreach ($group['categories'] ?? [] as $category) {
                $keys[] = $category['key'];
            }
        }

        return $keys;
    }

    /**
     * @return array<int>
     */
    private function effectiveVisibleAdvisorIds(): array
    {
        $selectedUserIds = array_values(array_unique(array_merge($this->advisorUserIds, $this->mechanicUserIds)));

        if ($selectedUserIds !== []) {
            return $selectedUserIds;
        }

        $tokenIds = $this->pickerTokenIds();

        if ($tokenIds === []) {
            return [];
        }

        return MicrosoftCategoryMapping::query()
            ->whereIn('microsoft_token_id', $tokenIds)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function pickerTokenIds(): array
    {
        return $this->getTokensForPicker()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return Collection<int, MicrosoftToken>
     */
    private function getTokensForPicker(): Collection
    {
        $tokens = collect();

        if ($this->advisorUserIds !== []) {
            $advisorToken = MicrosoftToken::resolveForRoleName('advisor');
            if ($advisorToken !== null) {
                $tokens->put($advisorToken->id, $advisorToken);
            }
        }

        if ($this->mechanicUserIds !== []) {
            $mechanicToken = MicrosoftToken::resolveForRoleName('mechanic');
            if ($mechanicToken !== null) {
                $tokens->put($mechanicToken->id, $mechanicToken);
            }
        }

        if ($tokens->isNotEmpty()) {
            return $tokens->values();
        }

        foreach (['advisor', 'mechanic'] as $roleName) {
            $token = MicrosoftToken::resolveForRoleName($roleName);

            if ($token !== null) {
                $tokens->put($token->id, $token);
            }
        }

        return $tokens->values();
    }

    #[On('advisors-changed')]
    public function onAdvisorsChanged(array $advisorUserIds, mixed $outlookCategoryMappingId = null): void
    {
        $this->advisorUserIds = array_values(array_map('intval', $advisorUserIds));
        $this->advisorId = $this->advisorUserIds[0] ?? null;
        $this->outlookCategoryMappingId = $this->normalizeOutlookCategoryMappingId($outlookCategoryMappingId);
        $this->resetCategoryGroupsCache();
        $this->syncVisibleCategoryKeys();

        if ($this->selectedDate !== null && $this->selectedDate !== '' && $this->timeFrom !== null && $this->timeFrom !== '') {
            $this->dispatchDatetime();

            return;
        }

        $this->clearSelection();
    }

    #[On('advisor-changed')]
    public function onAdvisorChanged(?int $advisorId, mixed $outlookCategoryMappingId = null): void
    {
        $this->advisorId = $advisorId;
        $this->outlookCategoryMappingId = $this->normalizeOutlookCategoryMappingId($outlookCategoryMappingId);

        $this->resetCategoryGroupsCache();

        if ($this->isService()) {
            $this->advisorUserIds = $advisorId !== null ? [(int) $advisorId] : [];
            $this->syncVisibleCategoryKeys();
        } else {
            $this->advisorUserIds = $advisorId !== null ? [(int) $advisorId] : [];
            $this->syncVisibleCategoryKeys();
        }

        if ($this->selectedDate !== null && $this->selectedDate !== '' && $this->timeFrom !== null && $this->timeFrom !== '') {
            $this->dispatchDatetime();

            return;
        }

        $this->clearSelection();
    }

    #[On('mechanics-changed')]
    public function onMechanicsChanged(array $mechanicUserIds, mixed $outlookCategoryMappingId = null): void
    {
        $this->mechanicUserIds = array_values(array_map('intval', $mechanicUserIds));
        $this->outlookCategoryMappingId = $this->normalizeOutlookCategoryMappingId($outlookCategoryMappingId);
        $this->resetCategoryGroupsCache();
        $this->syncVisibleCategoryKeys();

        if ($this->selectedDate !== null && $this->selectedDate !== '' && $this->timeFrom !== null && $this->timeFrom !== '') {
            $this->dispatchDatetime();

            return;
        }

        $this->clearSelection();
    }

    #[On('outlook-category-mapping-changed')]
    public function onOutlookCategoryMappingChanged(mixed $mappingId): void
    {
        $this->outlookCategoryMappingId = $this->normalizeOutlookCategoryMappingId($mappingId);
        $this->resetCategoryGroupsCache();
        $this->syncVisibleCategoryKeys();
    }

    private function normalizeOutlookCategoryMappingId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'by_user') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    #[On('appointment-picker-reset')]
    public function onAppointmentPickerReset(): void
    {
        $this->clearSelection();
        $this->resetCategoryGroupsCache();
        $this->lastAssigneeFilterFingerprint = '';
        $this->visibleCategoryKeys = [];

        // Keep first paint fast (cached), then trigger a follow-up request
        // to invalidate week-event cache and re-render with fresh data.
        $this->dispatch('appointment-picker-refresh-cache');
    }

    #[On('appointment-picker-refresh-cache')]
    public function refreshCalendarCache(): void
    {
        /** @var MicrosoftCalendarService $service */
        $service = app(MicrosoftCalendarService::class);

        foreach ($this->getTokensForPicker() as $token) {
            if (! $token instanceof MicrosoftToken) {
                continue;
            }

            $service->flushWeekEventsCache((int) $token->id);
        }

        $this->invalidateAppointmentsCache();
    }

    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->resolvedWeekStart())->subWeek()->toDateString();
        $this->invalidateAppointmentsCache();

        if (! $this->readOnly) {
            $this->syncSelectionAfterWeekChange();
        }
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->resolvedWeekStart())->addWeek()->toDateString();
        $this->invalidateAppointmentsCache();

        if (! $this->readOnly) {
            $this->syncSelectionAfterWeekChange();
        }
    }

    private function syncSelectionAfterWeekChange(): void
    {
        if (! filled($this->selectedDate) || ! filled($this->timeFrom)) {
            return;
        }

        $this->dispatchDatetime();
    }

    private function clearSelection(): void
    {
        $this->selectedDate = null;
        $this->timeFrom = '09:00';
        $this->timeTo = '10:00';
        $this->showGridSelection = false;
        $this->dispatch('appointment-picker-cleared');
    }

    public function selectSlot(string $date, string $time): ?array
    {
        if ($this->readOnly) {
            return null;
        }

        $this->selectedDate = $date;
        $this->timeFrom = $time;
        $this->timeTo = Carbon::parse('2000-01-01 ' . $time)->addHour()->format('H:i');
        $this->showGridSelection = true;
        $this->dispatchDatetime();

        return $this->datetimePayloadForForm();
    }

    public function selectRange(string $date, string $timeFrom, string $timeTo): ?array
    {
        if ($this->readOnly) {
            return null;
        }

        $this->showGridSelection = true;
        $this->selectedDate = $date;

        $from = Carbon::parse('2000-01-01 ' . $timeFrom);
        $to = Carbon::parse('2000-01-01 ' . $timeTo);

        if ($from->gte($to)) {
            $to = $from->copy()->addMinutes(15);
        } elseif ($from->diffInMinutes($to) < 15) {
            $to = $from->copy()->addMinutes(15);
        }

        $this->timeFrom = $from->format('H:i');
        $this->timeTo = $to->format('H:i');
        $this->dispatchDatetime();

        return $this->datetimePayloadForForm();
    }

    /**
     * @return array{datetime: string, durationMinutes: int, selectedDate: string, timeFrom: string, timeTo: string}
     */
    private function datetimePayloadForForm(): array
    {
        return [
            'datetime' => $this->selectedDate . ' ' . $this->timeFrom,
            'durationMinutes' => $this->getDurationMinutes(),
            'selectedDate' => $this->selectedDate,
            'timeFrom' => $this->timeFrom,
            'timeTo' => $this->timeTo,
        ];
    }

    public function shouldShowGridSelectionForDay(string $dayDate): bool
    {
        return $this->showGridSelection
            && filled($this->selectedDate)
            && $this->selectedDate === $dayDate
            && filled($this->timeFrom)
            && filled($this->timeTo);
    }

    public function updatedTimeFrom(): void
    {
        $this->dispatchDatetime();
    }

    public function updatedTimeTo(): void
    {
        $this->dispatchDatetime();
    }

    public function updatedSelectedDate(): void
    {
        $this->dispatchDatetime();
    }

    public function getDuration(): string
    {
        try {
            $from = Carbon::parse('2000-01-01 ' . $this->timeFrom);
            $to = Carbon::parse('2000-01-01 ' . $this->timeTo);

            if ($to->lte($from)) {
                return '—';
            }

            $diff = (int) $from->diffInMinutes($to);

            return intdiv($diff, 60) . ':' . str_pad((string) ($diff % 60), 2, '0', STR_PAD_LEFT);
        } catch (\Exception) {
            return '—';
        }
    }

    private function getDurationMinutes(): int
    {
        try {
            $from = Carbon::parse('2000-01-01 ' . $this->timeFrom);
            $to   = Carbon::parse('2000-01-01 ' . $this->timeTo);
            return (int) max(0, $from->diffInMinutes($to));
        } catch (\Exception) {
            return 0;
        }
    }

    private function dispatchDatetime(): void
    {
        if (! $this->selectedDate || ! $this->timeFrom) {
            $this->showGridSelection = false;
            $this->dispatch('appointment-picker-cleared');

            return;
        }

        $payload = $this->datetimePayloadForForm();

        $this->dispatch('appointment-picker-datetime-updated', ...$payload);

        $this->js(
            'if (typeof window.__rdmSyncAppointmentPickerDatetime === "function") { window.__rdmSyncAppointmentPickerDatetime(' . json_encode($payload) . '); }',
        );
    }

    #[On('appointment-picker-time-override')]
    public function onTimeOverride(?string $selectedDate, string $timeFrom, string $timeTo): void
    {
        $unchanged = ($selectedDate === null || $selectedDate === $this->selectedDate)
            && $timeFrom === $this->timeFrom
            && $timeTo === $this->timeTo;

        if ($unchanged) {
            return;
        }

        if ($selectedDate !== null) {
            $this->selectedDate = $selectedDate;
        }

        $this->timeFrom = $timeFrom;
        $this->timeTo   = $timeTo;
        $this->dispatchDatetime();
    }

    #[On('appointment-picker-category-visibility-changed')]
    public function onCategoryVisibilityChanged(array $visibleCategoryKeys): void
    {
        $keys = array_values(array_filter(array_map(
            fn (mixed $key): string => mb_strtolower(trim((string) $key)),
            $visibleCategoryKeys,
        ), fn (string $key): bool => $key !== ''));

        $pickerKeys = $this->filterCategoryKeysForPicker();

        $this->visibleCategoryKeys = $keys === []
            ? $pickerKeys
            : array_values(array_intersect($keys, $pickerKeys));
    }

    /**
     * Load appointments from Outlook via the category-user mapping.
     * Falls back to local DB if no Microsoft token or mapping is found.
     *
     * @return array{timed: array<string, list<array<string, mixed>>>, allDayPlaced: list<array<string, mixed>>}
     */
    private function loadAppointmentsByDay(Carbon $weekStart): array
    {
        $tokens = $this->getTokensForPicker();

        if ($tokens->isEmpty()) {
            $timed = $this->effectiveVisibleCategoryKeys() === []
                ? []
                : $this->loadLocalAppointments($weekStart);

            return [
                'timed' => $this->layoutTimedAppointmentsByDay($timed),
                'allDayPlaced' => [],
            ];
        }

        $merged = [];
        $allDaySpans = [];

        foreach ($tokens as $token) {
            $loaded = $this->loadOutlookAppointments(
                $token,
                $weekStart,
                loadTimed: $this->effectiveVisibleCategoryKeys() !== [],
            );

            foreach ($loaded['timed'] as $date => $items) {
                if (! isset($merged[$date])) {
                    $merged[$date] = [];
                }

                $merged[$date] = [...$merged[$date], ...$items];
            }

            $allDaySpans = [...$allDaySpans, ...$loaded['allDaySpans']];
        }

        return [
            'timed' => $this->layoutTimedAppointmentsByDay($merged),
            'allDayPlaced' => $this->layoutAllDaySpans($allDaySpans),
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $byDay
     * @return array<string, list<array<string, mixed>>>
     */
    private function layoutTimedAppointmentsByDay(array $byDay): array
    {
        foreach ($byDay as $date => $list) {
            $byDay[$date] = $this->layoutOverlappingDayAppointments(
                $this->dedupeDayAppointmentItems($list),
            );
        }

        return $byDay;
    }

    /**
     * @return array{timed: array<string, list<array<string, mixed>>, allDaySpans: list<array<string, mixed>>}
     */
    private function loadOutlookAppointments(MicrosoftToken $token, Carbon $weekStart, bool $loadTimed = true): array
    {
        /** @var array<string, string> $categoryColors normalised name (lower) → hex */
        $categoryColors = $this->categoryPaletteForToken($token);

        $timedVisibleKeys = [];

        if ($loadTimed) {
            $visibleCategoryKeys = $this->effectiveVisibleCategoryKeys();
            $timedVisibleKeys = $visibleCategoryKeys === []
                ? []
                : array_values(array_intersect($visibleCategoryKeys, array_keys($categoryColors)));
        }

        $service = app(MicrosoftCalendarService::class);
        $start = $weekStart->copy()->startOfDay();
        $end = $weekStart->copy()->addDays(self::WEEK_DAYS - 1)->endOfDay();

        $events = $service->getWeekEvents($token->id, $start, $end);

        $byDay = [];
        $allDaySpans = [];

        foreach ($events as $event) {
            $eventCategories = is_array($event['categories'] ?? null) ? $event['categories'] : [];

            if ($this->isOutlookAllDayEvent($event)) {
                $subject = is_string($event['subject'] ?? null) ? trim($event['subject']) : '';
                $eventId = is_string($event['id'] ?? null) ? $event['id'] : null;
                $colorHex = $this->resolveOutlookAllDayColor($eventCategories, $categoryColors, $token);
                $span = $this->buildAllDaySpanForWeek($event, $weekStart, $subject, $colorHex, $eventId);

                if ($span !== null) {
                    $allDaySpans[] = $span;
                }

                continue;
            }

            if ($timedVisibleKeys === []) {
                continue;
            }

            $matchedCategoryKey = $this->resolveOutlookEventCategoryKey($eventCategories, $categoryColors);

            if ($matchedCategoryKey === null || ! in_array($matchedCategoryKey, $timedVisibleKeys, true)) {
                continue;
            }

            $colorHex = $categoryColors[$matchedCategoryKey] ?? self::FALLBACK_COLOR;

            $startDt = $this->parseGraphDateTimeToAppTimezone(is_array($event['start'] ?? null) ? $event['start'] : []);
            $endDt = $this->parseGraphDateTimeToAppTimezone(is_array($event['end'] ?? null) ? $event['end'] : []);
            $date = $startDt->toDateString();

            $gridTotalPx = (self::GRID_END - self::GRID_START + 1) * self::PX_PER_HOUR;
            $topPx = $this->minutesToPx(($startDt->hour - self::GRID_START) * 60 + $startDt->minute);

            // Cap duration at the day boundary so midnight-spanning events
            // don't overflow the column height.
            $minutesUntilMidnight = (24 - $startDt->hour) * 60 - $startDt->minute;
            $durationMinutes = min((int) $startDt->diffInMinutes($endDt), $minutesUntilMidnight);
            $heightPx = max(15, $this->minutesToPx($durationMinutes));

            // Ensure block stays within grid.
            if ($topPx >= $gridTotalPx) {
                continue;
            }
            $heightPx = min($heightPx, $gridTotalPx - $topPx);

            $subject = is_string($event['subject'] ?? null) ? trim($event['subject']) : '';
            $time = $startDt->format('H:i');
            $description = $this->outlookEventDescription($subject, $this->outlookEventBodyPreview($event));

            $byDay[$date][] = [
                'eventId'     => is_string($event['id'] ?? null) ? $event['id'] : null,
                'time'        => $time,
                'title'       => $subject,
                'description' => $description,
                'label'       => trim($time . ' ' . $subject),
                'topPx'       => $topPx,
                'heightPx'    => $heightPx,
                'color'       => $colorHex,
                'categoryKey' => $matchedCategoryKey,
                'stackKey'    => $this->appointmentStackKey($matchedCategoryKey, $subject),
            ];
        }

        return ['timed' => $byDay, 'allDaySpans' => $allDaySpans];
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
     * All-day events ignore the category filter; color comes from event category, general category, or fallback.
     *
     * @param  list<mixed>  $eventCategories
     * @param  array<string, string>  $categoryColors
     */
    private function resolveOutlookAllDayColor(
        array $eventCategories,
        array $categoryColors,
        MicrosoftToken $token,
    ): string {
        $matchedKey = $this->resolveOutlookEventCategoryKey($eventCategories, $categoryColors);

        if ($matchedKey !== null) {
            return $categoryColors[$matchedKey];
        }

        $generalKey = mb_strtolower(trim((string) ($token->general_category_name ?? '')));

        if ($generalKey !== '' && isset($categoryColors[$generalKey])) {
            return $categoryColors[$generalKey];
        }

        return self::FALLBACK_COLOR;
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
     * @return array{eventId: ?string, title: string, color: string, startCol: int, endCol: int, colSpan: int}|null
     */
    private function buildAllDaySpanForWeek(
        array $event,
        Carbon $weekStart,
        string $title,
        string $color,
        ?string $eventId,
    ): ?array {
        $range = $this->outlookAllDayInclusiveDateRange($event);

        if ($range === null) {
            return null;
        }

        $weekFirst = $weekStart->copy()->startOfDay();
        $weekLast = $weekFirst->copy()->addDays(self::WEEK_DAYS - 1)->startOfDay();

        if ($range['end']->lt($weekFirst) || $range['start']->gt($weekLast)) {
            return null;
        }

        $clipStart = $range['start']->greaterThan($weekFirst) ? $range['start'] : $weekFirst;
        $clipEnd = $range['end']->lessThan($weekLast) ? $range['end'] : $weekLast;

        $startCol = (int) $weekFirst->diffInDays($clipStart);
        $colSpan = (int) $clipStart->diffInDays($clipEnd) + 1;

        return [
            'eventId'  => $eventId,
            'title'    => $title,
            'color'    => $color,
            'startCol' => $startCol,
            'endCol'   => $startCol + $colSpan - 1,
            'colSpan'  => $colSpan,
        ];
    }

    /**
     * @param  list<array{eventId?: ?string, title: string, color: string, startCol: int, endCol: int, colSpan: int}>  $spans
     * @return list<array{title: string, color: string, startCol: int, colSpan: int, rowIndex: int}>
     */
    private function layoutAllDaySpans(array $spans): array
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
                'title'    => (string) ($span['title'] ?? ''),
                'color'    => (string) ($span['color'] ?? self::FALLBACK_COLOR),
                'startCol' => $startCol,
                'colSpan'  => (int) $span['colSpan'],
                'rowIndex' => $rowIndex,
            ];
        }

        return $placed;
    }

    /**
     * @param  list<array{rowIndex: int}>  $allDayPlaced
     */
    private function allDaySectionHeightPx(array $allDayPlaced): int
    {
        if ($allDayPlaced === []) {
            return 0;
        }

        $maxRow = max(array_column($allDayPlaced, 'rowIndex'));

        return ($maxRow + 1) * self::ALL_DAY_ROW_PX;
    }

    /**
     * @param  list<array{eventId?: ?string, label: string, topPx: int, heightPx: int, color: string}>  $items
     * @return list<array{label: string, topPx: int, heightPx: int, color: string}>
     */
    private function dedupeDayAppointmentItems(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as $item) {
            $eventId = $item['eventId'] ?? null;

            if (is_string($eventId) && $eventId !== '') {
                $key = 'id:' . $eventId;
            } else {
                $key = 'slot:' . ($item['topPx'] ?? 0) . ':' . ($item['heightPx'] ?? 0) . ':' . ($item['title'] ?? $item['label'] ?? '');
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

    /**
     * @param  array<string, mixed>  $fragment  Graph dateTimeTimeZone object
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

    private function appointmentSubjectStackKey(string $subject): string
    {
        $subject = trim($subject);

        if ($subject === '') {
            return '';
        }

        if (preg_match('/^reistijd\s*-\s*(.+)$/iu', $subject, $matches)) {
            return mb_strtolower(trim($matches[1]));
        }

        return mb_strtolower($subject);
    }

    private function appointmentStackKey(string $categoryKey, string $subject): string
    {
        $subjectKey = $this->appointmentSubjectStackKey($subject);

        if ($subjectKey === '') {
            return '';
        }

        return mb_strtolower(trim($categoryKey)) . '|' . $subjectKey;
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

        if ($subjectNormalized !== '' && str_starts_with($previewNormalized, $subjectNormalized)) {
            $remainder = trim(mb_substr($bodyPreview, mb_strlen($subject)));
            $remainder = ltrim($remainder, " .\n\r\t-–—");

            return $remainder;
        }

        return $bodyPreview;
    }

    /**
     * @param  list<array{label: string, topPx: int, heightPx: int, color: string, stackKey?: string}>  $items
     * @return list<array{label: string, topPx: int, heightPx: int, color: string, leftPct: float, widthPct: float}>
     */
    private function layoutOverlappingDayAppointments(array $items): array
    {
        if ($items === []) {
            return [];
        }

        usort($items, fn (array $a, array $b): int => $a['topPx'] <=> $b['topPx']);

        foreach ($items as &$item) {
            $categoryKey = (string) ($item['categoryKey'] ?? '');
            $title = trim((string) ($item['title'] ?? ''));
            $item['stackKey'] = $item['stackKey'] ?? $this->appointmentStackKey(
                $categoryKey,
                $title !== '' ? $title : (string) preg_replace('/^\d{1,2}:\d{2}\s+/', '', (string) ($item['label'] ?? '')),
            );
        }
        unset($item);

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
                'top'     => $top,
                'bottom'  => $bottom,
                'indices' => $indices,
            ];
        }

        usort($units, fn (array $a, array $b): int => $a['top'] <=> $b['top']);

        /** @var array<int, int> $activeEnds column => bottom edge px */
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
                'leftPct'  => $col * $span,
                'widthPct' => $span,
            ];
        }, $items, array_keys($items));
    }

    /**
     * Fallback: load from local appointments table.
     *
     * @return array<string, list<array{label: string, topPx: int, heightPx: int, color: string}>>
     */
    private function loadLocalAppointments(Carbon $weekStart): array
    {
        $start = $weekStart->copy()->startOfDay();
        $end = $weekStart->copy()->addDays(self::WEEK_DAYS - 1)->endOfDay();

        $byDay = [];

        $visibleIds = $this->effectiveVisibleAdvisorIds();

        \App\Models\Appointment::query()
            ->when($visibleIds !== [], fn ($query) => $query->whereHas(
                'order',
                fn ($q) => $q->whereIn('advisor_id', $visibleIds),
            ))
            ->whereBetween('datetime', [$start, $end])
            ->get()
            ->each(function (\App\Models\Appointment $appt) use (&$byDay): void {
                $dt = $appt->datetime;
                $date = $dt->toDateString();
                $time = $dt->format('H:i');
                $title = trim((string) ($appt->title ?? ''));
                if ($title === '') {
                    $title = ucfirst($appt->type->value);
                }
                $description = trim(strip_tags((string) ($appt->description ?? '')));

                $byDay[$date][] = [
                    'time'        => $time,
                    'title'       => $title,
                    'description' => $description,
                    'label'       => trim($time . ' ' . $title),
                    'topPx'       => $this->minutesToPx(($dt->hour - self::GRID_START) * 60 + $dt->minute),
                    'heightPx'    => max(30, self::PX_PER_HOUR),
                    'color'       => self::FALLBACK_COLOR,
                ];
            });

        return $byDay;
    }

    private function minutesToPx(int $minutes): int
    {
        return max(0, (int) round($minutes * self::PX_PER_HOUR / 60));
    }

    /**
     * @param  list<array{date: string, label: string, isToday: bool, isPast: bool}>  $days
     * @return array{date: string, colIndex: int, startPx: int, heightPx: int}|null
     */
    private function resolveCommittedSelectionForGrid(array $days): ?array
    {
        if (! $this->showGridSelection || ! filled($this->selectedDate) || ! filled($this->timeFrom) || ! filled($this->timeTo)) {
            return null;
        }

        $dayIndex = null;

        foreach ($days as $index => $day) {
            if ($day['date'] === $this->selectedDate) {
                $dayIndex = $index;
                break;
            }
        }

        if ($dayIndex === null) {
            return null;
        }

        [$sh, $sm] = array_map('intval', explode(':', $this->timeFrom));
        [$eh, $em] = array_map('intval', explode(':', $this->timeTo));
        $selTop = max(0, ($sh - self::GRID_START) * self::PX_PER_HOUR + (int) round($sm * self::PX_PER_HOUR / 60));
        $selHeight = max(30, (int) round((($eh * 60 + $em) - ($sh * 60 + $sm)) * self::PX_PER_HOUR / 60));

        return [
            'date' => $this->selectedDate,
            'colIndex' => $dayIndex,
            'startPx' => $selTop,
            'heightPx' => $selHeight,
            'weekStart' => $this->resolvedWeekStart(),
        ];
    }

    public function render(): \Illuminate\View\View
    {
        $weekStartCarbon = Carbon::parse($this->resolvedWeekStart());

        $appTz = config('app.timezone', 'Europe/Amsterdam');
        $now = Carbon::now($appTz);
        $todayDate = $now->toDateString();
        $nowPx = $this->minutesToPx(($now->hour - self::GRID_START) * 60 + $now->minute);

        $days = AppointmentCalendarVisibleWeek::buildDays($weekStartCarbon, $this->showWeekend, $todayDate);

        $categoryGroups = $this->resolveCategoryGroupsForRender();
        $committedSelection = $this->resolveCommittedSelectionForGrid($days);
        $calendarAppointments = $this->resolvedCalendarAppointments($weekStartCarbon);
        $allDayPlaced = AppointmentCalendarVisibleWeek::remapAllDayPlaced(
            $calendarAppointments['allDayPlaced'],
            $this->showWeekend,
        );

        return view('livewire.appointment-calendar-picker', [
            'days'                  => $days,
            'committedSelection'    => $committedSelection,
            'showGridSelection'     => $this->showGridSelection,
            'appointmentsByDay'     => $calendarAppointments['timed'],
            'allDayPlaced'          => $allDayPlaced,
            'allDayHeightPx'        => $this->allDaySectionHeightPx($allDayPlaced),
            'dayCount'              => count($days),
            'weekLabel'             => AppointmentCalendarVisibleWeek::weekLabel($weekStartCarbon, $this->showWeekend),
            'duration'              => $this->getDuration(),
            'gridTotalPx'           => (self::GRID_END - self::GRID_START + 1) * self::PX_PER_HOUR,
            'gridHours'             => range(self::GRID_START, self::GRID_END),
            'gridStartMinutes'      => self::GRID_START * 60,
            'workStartPx'           => self::WORK_START_HOUR * self::PX_PER_HOUR,
            'workEndPx'             => self::WORK_END_HOUR * self::PX_PER_HOUR,
            'scrollInitialPx'       => (int) round(self::SCROLL_INITIAL_MINUTES * self::PX_PER_HOUR / 60),
            'pxPerHour'             => self::PX_PER_HOUR,
            'todayDate'             => $todayDate,
            'nowPx'                 => $nowPx,
            'categoryGroups'        => $categoryGroups,
            'categoryFilterCount'   => $this->countCategoryFilterItems($categoryGroups),
            'categoryFilterKeys'    => $this->categoryFilterKeys($categoryGroups),
            'calendarCanLoad'       => $this->calendarCanLoad(),
            'weekStart'             => $this->resolvedWeekStart(),
        ]);
    }
}
