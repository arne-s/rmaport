<x-filament-panels::page class="planning-calendar-page">
    @livewire(\App\Http\Livewire\PlanningCalendarWeek::class, ['mode' => $mode->value], key('planning-calendar-' . $mode->value))
</x-filament-panels::page>
