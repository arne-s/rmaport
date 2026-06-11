<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Support\SalesAuthorization;
use App\Services\RmaOverviewQueries;
use Filament\Widgets\ChartWidget;

class RmasPerDayWidget extends ChartWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'RMA\'s per dag';

    protected ?string $maxHeight = null;

    /**
     * @var view-string
     */
    protected string $view = 'filament.widgets.dashboard.rmas-per-day-widget';

    public static function canView(): bool
    {
        return SalesAuthorization::canManage();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getOptions(): ?array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'ticks' => [
                        'autoSkip' => false,
                        'maxRotation' => 90,
                        'minRotation' => 45,
                    ],
                ],
                'y' => [
                    'ticks' => [
                        'beginAtZero' => true,
                        'stepSize' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $days = RmaOverviewQueries::purchasedAtDayCounts();

        return [
            'datasets' => [
                [
                    'label' => 'RMA\'s',
                    'data' => $days->pluck('value')->all(),
                ],
            ],
            'labels' => $days->pluck('label')->all(),
        ];
    }
}
