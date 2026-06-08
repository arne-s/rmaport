<?php

namespace App\Http\Livewire;

use App\Support\Planning\AppointmentCalendarVisibleWeek;
use App\Support\Planning\PlanningCalendarLoader;
use App\Support\Planning\PlanningCalendarMode;
use App\Support\Planning\PlanningCalendarWeekGrid;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

class PlanningCalendarWeek extends Component
{
    public string $mode;

    public ?string $weekStart = null;

    /** @var list<string> */
    public array $visibleCategoryKeys = [];

    public bool $calendarReady = false;

    public bool $showWeekend = false;

    public function mount(PlanningCalendarMode $mode, bool $calendarReady = false): void
    {
        $this->mode = $mode->value;
        $this->calendarReady = $calendarReady;
        $loader = app(PlanningCalendarLoader::class);
        $this->weekStart = $loader->defaultWeekStart()->toDateString();
        $this->visibleCategoryKeys = $loader->defaultVisibleCategoryKeys($mode);

        if ($this->visibleCategoryKeys === [] && $loader->categoryGroupsForMode($mode) === []) {
            $this->visibleCategoryKeys = ['*'];
        }

    }

    public function hydrate(): void
    {
        if (! filled($this->weekStart)) {
            $this->weekStart = app(PlanningCalendarLoader::class)->defaultWeekStart()->toDateString();
        }
    }

    public function bootCalendar(): void
    {
        $this->calendarReady = true;
    }

    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->resolvedWeekStart())->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->resolvedWeekStart())->addWeek()->toDateString();
    }

    #[On('planning-calendar-category-visibility-changed')]
    public function onCategoryVisibilityChanged(array $visibleCategoryKeys): void
    {
        $keys = array_values(array_filter(array_map(
            fn (mixed $key): string => mb_strtolower(trim((string) $key)),
            $visibleCategoryKeys,
        ), fn (string $key): bool => $key !== ''));

        $this->visibleCategoryKeys = $keys === [] ? ['*'] : $keys;
    }

    private function resolvedWeekStart(): string
    {
        if (filled($this->weekStart)) {
            return $this->weekStart;
        }

        return app(PlanningCalendarLoader::class)->defaultWeekStart()->toDateString();
    }

    public function render(): \Illuminate\View\View
    {
        if (! $this->calendarReady) {
            return view('livewire.planning-calendar-week-loading');
        }

        $planningMode = PlanningCalendarMode::from($this->mode);
        $loader = app(PlanningCalendarLoader::class);
        $weekStartCarbon = Carbon::parse($this->resolvedWeekStart())->startOfWeek(Carbon::MONDAY);

        $appTz = config('app.timezone', 'Europe/Amsterdam');
        $now = Carbon::now($appTz);
        $todayDate = $now->toDateString();
        $nowPx = max(0, (int) round(($now->hour * 60 + $now->minute) * PlanningCalendarWeekGrid::pxPerHour() / 60));

        $days = AppointmentCalendarVisibleWeek::buildDays($weekStartCarbon, $this->showWeekend, $todayDate);

        $events = $loader->eventsForWeek($planningMode, $weekStartCarbon);
        $calendarAppointments = PlanningCalendarWeekGrid::build(
            $weekStartCarbon,
            $events,
            $this->visibleCategoryKeys,
        );
        $allDayPlaced = AppointmentCalendarVisibleWeek::remapAllDayPlaced(
            $calendarAppointments['allDayPlaced'],
            $this->showWeekend,
        );
        $categoryGroups = $loader->categoryGroupsForMode($planningMode);

        $categoryFilterKeys = [];

        foreach ($categoryGroups as $group) {
            foreach ($group['categories'] ?? [] as $category) {
                $categoryFilterKeys[] = $category['key'];
            }
        }

        return view('livewire.appointment-calendar-picker', [
            'days' => $days,
            'committedSelection' => null,
            'showGridSelection' => false,
            'appointmentsByDay' => $calendarAppointments['timed'],
            'allDayPlaced' => $allDayPlaced,
            'allDayHeightPx' => PlanningCalendarWeekGrid::allDaySectionHeightPx($allDayPlaced),
            'dayCount' => count($days),
            'weekLabel' => AppointmentCalendarVisibleWeek::weekLabel($weekStartCarbon, $this->showWeekend),
            'duration' => '—',
            'gridTotalPx' => PlanningCalendarWeekGrid::gridTotalPx(),
            'gridHours' => PlanningCalendarWeekGrid::gridHours(),
            'gridStartMinutes' => PlanningCalendarWeekGrid::gridStartMinutes(),
            'workStartPx' => PlanningCalendarWeekGrid::workStartPx(),
            'workEndPx' => PlanningCalendarWeekGrid::workEndPx(),
            'scrollInitialPx' => PlanningCalendarWeekGrid::scrollInitialPx(),
            'pxPerHour' => PlanningCalendarWeekGrid::pxPerHour(),
            'todayDate' => $todayDate,
            'nowPx' => $nowPx,
            'categoryGroups' => $categoryGroups,
            'categoryFilterCount' => count($categoryFilterKeys),
            'categoryFilterKeys' => $categoryFilterKeys,
            'calendarCanLoad' => true,
            'readOnly' => true,
            'weekStart' => $this->resolvedWeekStart(),
            'categoryVisibilityEvent' => 'planning-calendar-category-visibility-changed',
            'showOutlookNotice' => ! $loader->hasLinkedTokens($planningMode),
            'outlookSettingsUrl' => route('filament.app.resources.customers.settings').'?area=outlook',
        ]);
    }

}
