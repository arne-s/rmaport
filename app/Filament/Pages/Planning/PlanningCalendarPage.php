<?php

namespace App\Filament\Pages\Planning;

use App\Filament\Support\SalesAuthorization;
use App\Support\Planning\PlanningCalendarMode;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

abstract class PlanningCalendarPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.planning-calendar';

    protected Width|string|null $maxContentWidth = Width::Full;

    abstract public static function calendarMode(): PlanningCalendarMode;

    public static function canAccess(): bool
    {
        return SalesAuthorization::canManage();
    }

    public function getTitle(): string|Htmlable
    {
        return static::calendarMode()->pageTitle();
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            url()->current() => static::calendarMode()->pageTitle(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'mode' => static::calendarMode(),
        ];
    }
}
