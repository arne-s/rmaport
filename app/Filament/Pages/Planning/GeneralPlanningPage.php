<?php

namespace App\Filament\Pages\Planning;

use App\Support\Planning\PlanningCalendarMode;

class GeneralPlanningPage extends PlanningCalendarPage
{
    protected static ?string $slug = 'calendar/general';

    protected static ?string $title = 'Passing/Aflever agenda';

    public static function calendarMode(): PlanningCalendarMode
    {
        return PlanningCalendarMode::General;
    }
}
