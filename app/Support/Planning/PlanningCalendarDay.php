<?php

namespace App\Support\Planning;

use Illuminate\Support\Carbon;

final class PlanningCalendarDay
{
    /**
     * @param  list<PlanningCalendarEvent>  $events
     */
    public function __construct(
        public Carbon $date,
        public string $label,
        public bool $isToday,
        public array $events,
    ) {}
}
