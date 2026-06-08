<?php

namespace App\Filament\Actions;

use App\Support\Planning\PlanningCalendarMode;
use Filament\Actions\Action;

class OpenMyCalendarAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'open_my_calendar';
    }

    protected function setUp(): void
    {
        parent::setUp();

        PlanningCalendarModalAction::configure($this, PlanningCalendarMode::My, 'dashboard-my-calendar');

        $this->icon('heroicon-s-calendar');
    }
}
