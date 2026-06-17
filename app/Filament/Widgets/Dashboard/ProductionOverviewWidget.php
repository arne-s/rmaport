<?php

namespace App\Filament\Widgets\Dashboard;

use App\Enums\RmaStatus;
use App\Filament\Support\SalesAuthorization;
use App\Services\RmaOverviewQueries;
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
            'md' => 4,
            'xl' => 4,
        ];
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return array_map(
            fn (RmaStatus $status): Stat => Stat::make($status->getLabel(), RmaOverviewQueries::forStatus($status)->count())
                ->url(RmaOverviewQueries::urlForStatus($status)),
            RmaStatus::overviewStatuses(),
        );
    }
}
