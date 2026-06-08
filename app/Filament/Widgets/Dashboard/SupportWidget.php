<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class SupportWidget extends BaseWidget
{
    protected string|int|array $columnSpan = 'none';
    protected string $view = 'filament.widgets.dashboard.support-widget';

}
