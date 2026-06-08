<?php

namespace App\Filament\Pages\Planning;

use App\Support\Planning\PlanningCalendarMode;

class MechanicPlanningPage extends PlanningCalendarPage
{
    protected static ?string $slug = 'calendar/mechanic';

    protected static ?string $title = 'Werkplaats agenda';

    public static function calendarMode(): PlanningCalendarMode
    {
        return PlanningCalendarMode::Mechanic;
    }
}
