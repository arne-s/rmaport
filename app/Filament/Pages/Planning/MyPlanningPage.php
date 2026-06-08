<?php

namespace App\Filament\Pages\Planning;

use App\Support\Planning\PlanningCalendarMode;

class MyPlanningPage extends PlanningCalendarPage
{
    protected static ?string $slug = 'calendar/my';

    protected static ?string $title = 'Mijn agenda';

    public static function calendarMode(): PlanningCalendarMode
    {
        return PlanningCalendarMode::My;
    }
}
