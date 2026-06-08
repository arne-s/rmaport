<?php

namespace App\Filament\Widgets\Reporting;

use App\Enums\ProductType;
use App\Filament\Widgets\Reporting\Concerns\HasReportingStatisticsChartHeight;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class TotalDeliveredUnitsPerMonthChartWidget extends ChartWidget
{
    use HasReportingStatisticsChartHeight;

    protected string $view = 'filament.widgets.reporting.total-delivered-units-chart-widget';

    protected int|string|array $columnSpan = 1;

    public function mount(): void
    {
        if ($this->filter === null || $this->filter === '') {
            $this->filter = (string) $this->deliveredFrameYearBounds()['max'];
        }

        parent::mount();
    }

    public function getHeading(): string
    {
        $year = (string) ($this->filter ?? (string) now()->year);

        return 'Totaal aantal Units ' . $year;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, string>|null
     */
    protected function getFilters(): ?array
    {
        return $this->yearSelectOptions();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCachedData(): array
    {
        return $this->getData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $bounds = $this->deliveredFrameYearBounds();
        $year = (int) ($this->filter ?? $bounds['max']);
        if ($year < $bounds['min'] || $year > $bounds['max']) {
            $year = $bounds['max'];
        }

        $prevYear = $year - 1;

        $labels = collect(range(1, 12))->map(fn (int $m): string => (string) $m)->all();

        $current = $this->monthlyDeliveredFrameQuantities($year);
        $previous = $this->monthlyDeliveredFrameQuantities($prevYear);

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => (string) $prevYear,
                    'data' => $previous,
                    'backgroundColor' => 'rgba(148, 163, 184, 0.85)',
                    'borderColor' => 'rgb(100, 116, 139)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => (string) $year,
                    'data' => $current,
                    'backgroundColor' => 'rgba(1, 152, 199, 0.85)',
                    'borderColor' => 'rgb(1, 120, 158)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Maand',
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Aantal geleverde units',
                    ],
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, float> length 12, index 0 = January
     */
    private function monthlyDeliveredFrameQuantities(int $year): array
    {
        $rows = DB::table('order_products')
            ->selectRaw('MONTH(delivered_at) as m, SUM(qty) as q')
            ->where('type', ProductType::Frame->value)
            ->whereNotNull('delivered_at')
            ->whereYear('delivered_at', $year)
            ->groupByRaw('MONTH(delivered_at)')
            ->pluck('q', 'm');

        $out = [];
        foreach (range(1, 12) as $m) {
            $out[] = (int) round((float) ($rows[$m] ?? 0));
        }

        return $out;
    }

    /**
     * @return array{min: int, max: int}
     */
    private function deliveredFrameYearBounds(): array
    {
        $row = DB::table('order_products')
            ->where('type', ProductType::Frame->value)
            ->whereNotNull('delivered_at')
            ->selectRaw('MIN(YEAR(delivered_at)) as min_y, MAX(YEAR(delivered_at)) as max_y')
            ->first();

        $minY = (int) ($row->min_y ?? now()->year);
        $maxY = (int) ($row->max_y ?? now()->year);
        if ($minY > $maxY) {
            $y = (int) now()->year;

            return ['min' => $y, 'max' => $y];
        }

        return ['min' => $minY, 'max' => $maxY];
    }

    /**
     * @return array<string, string>
     */
    private function yearSelectOptions(): array
    {
        $bounds = $this->deliveredFrameYearBounds();

        return collect(range($bounds['max'], $bounds['min']))
            ->mapWithKeys(fn (int $year): array => [(string) $year => (string) $year])
            ->all();
    }
}
