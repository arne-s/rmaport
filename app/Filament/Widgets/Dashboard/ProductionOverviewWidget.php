<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\ProductionOverviewQueries;
use App\Filament\Support\SalesAuthorization;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductionOverviewWidget extends StatsOverviewWidget
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.widgets.dashboard.production-overview-widget';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return SalesAuthorization::canManage();
    }

    /**
     * @return int|array<string, int|null>|null
     */
    protected function getColumns(): int|array|null
    {
        return [
            'default' => 2,
            'md' => 3,
            'xl' => 6,
        ];
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make('1. Passing', ProductionOverviewQueries::fitting()->count())
                ->url(route('filament.app.resources.production.fitting')),
            Stat::make('2. Offerte', ProductionOverviewQueries::quote()->count())
                ->url(route('filament.app.resources.production.quote')),
            Stat::make('3. Order', ProductionOverviewQueries::ordered()->count())
                ->url(route('filament.app.resources.production.ordered')),
            Stat::make('4. Inkoop', ProductionOverviewQueries::purchased()->count())
                ->url(route('filament.app.resources.production.purchased')),
            Stat::make('5. Montage', ProductionOverviewQueries::assembled()->count())
                ->url(route('filament.app.resources.production.assembled')),
            Stat::make('6. Levering', ProductionOverviewQueries::delivered()->count())
                ->url(route('filament.app.resources.production.delivered')),
        ];
    }
}
