<?php

namespace App\Support\Planning;

enum PlanningCalendarMode: string
{
    case My = 'my';
    case General = 'general';
    case Mechanic = 'mechanic';

    public function pageTitle(): string
    {
        return match ($this) {
            self::My => 'Mijn agenda',
            self::General => 'Passing/Aflever agenda',
            self::Mechanic => 'Werkplaats agenda',
        };
    }
}
