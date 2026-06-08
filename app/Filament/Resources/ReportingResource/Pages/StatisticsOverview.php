<?php

namespace App\Filament\Resources\ReportingResource\Pages;

use App\Filament\Resources\ReportingResource;
use App\Filament\Widgets\Reporting\TotalDeliveredUnitsPerMonthChartWidget;
use App\Filament\Widgets\Reporting\UnitsPerChairTypeStackedChartWidget;
use App\Filament\Widgets\Reporting\WolturnusDeliveredByChairTypeChartWidget;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\View\View;

class StatisticsOverview extends Page
{
    protected static string $resource = ReportingResource::class;

    protected static ?string $title = 'Statistieken';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.resources.reporting-resource.pages.statistics-overview';

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.reporting.statistics') => 'Statistieken',
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            TotalDeliveredUnitsPerMonthChartWidget::class,
            UnitsPerChairTypeStackedChartWidget::class,
            WolturnusDeliveredByChairTypeChartWidget::class,
        ];
    }

    protected function getColumns(): int|string|array
    {
        return [
            'default' => 1,
            'xl' => 2,
        ];
    }

    public function getHeader(): ?View
    {
        return view('filament.components.back-to-overview-with-topbar-breadcrumbs', [
            'title' => 'Dashboard',
            'url' => route('filament.app.pages.dashboard'),
            'class' => 'quote-overview-back mt-[-16px] mb-3',
            'breadcrumbs' => Filament::hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }

}
