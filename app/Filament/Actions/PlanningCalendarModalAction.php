<?php

namespace App\Filament\Actions;

use App\Http\Livewire\PlanningCalendarWeek;
use App\Support\Planning\PlanningCalendarMode;
use Filament\Actions\Action;
use Filament\Schemas\Components\Livewire as FilamentLivewire;

final class PlanningCalendarModalAction
{
    public static function make(string $name, PlanningCalendarMode $mode, ?string $livewireKey = null): Action
    {
        return static::configure(Action::make($name), $mode, $livewireKey);
    }

    public static function configure(Action $action, PlanningCalendarMode $mode, ?string $livewireKey = null): Action
    {
        $title = $mode->pageTitle();
        $livewireKey ??= 'planning-calendar-' . $mode->value;

        return $action
            ->label($title)
            ->modalHeading($title)
            ->modalWidth('full')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->extraModalWindowAttributes(['class' => 'dashboard-my-calendar-modal'])
            ->schema([
                FilamentLivewire::make(PlanningCalendarWeek::class, [
                    'mode' => $mode->value,
                    'calendarReady' => true,
                ])->key($livewireKey),
            ]);
    }
}
