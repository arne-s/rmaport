<?php

namespace App\Livewire;

use App\Filament\Actions\PlanningCalendarModalAction;
use App\Support\Planning\PlanningCalendarMode;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\On;
use Livewire\Component;

class GlobalPlanningCalendar extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function open_planning_myAction(): Action
    {
        return PlanningCalendarModalAction::make('open_planning_my', PlanningCalendarMode::My);
    }

    public function open_planning_generalAction(): Action
    {
        return PlanningCalendarModalAction::make('open_planning_general', PlanningCalendarMode::General);
    }

    public function open_planning_mechanicAction(): Action
    {
        return PlanningCalendarModalAction::make('open_planning_mechanic', PlanningCalendarMode::Mechanic);
    }

    #[On('open-dashboard-planning-my')]
    public function openDashboardPlanningMy(): void
    {
        $this->mountAction('open_planning_my');
    }

    #[On('open-dashboard-planning-general')]
    public function openDashboardPlanningGeneral(): void
    {
        $this->mountAction('open_planning_general');
    }

    #[On('open-dashboard-planning-mechanic')]
    public function openDashboardPlanningMechanic(): void
    {
        $this->mountAction('open_planning_mechanic');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.global-planning-calendar');
    }
}
