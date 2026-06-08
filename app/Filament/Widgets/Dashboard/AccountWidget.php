<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class AccountWidget extends BaseWidget
{
    protected string|int|array $columnSpan = 'none';
    protected string $view = 'filament.widgets.dashboard.account-widget';
    protected function getCards(): array
    {
        return [
            Stat::make('jaja', '52')
                ->description('13,8%')
                ->descriptionIcon('heroicon-m-arrow-trending-down'),
        ];
    }
}
