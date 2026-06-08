<?php

namespace App\Filament\Widgets\Reporting;

use App\Enums\ProductType;
use App\Filament\Widgets\Reporting\Concerns\HasReportingStatisticsChartHeight;
use App\Services\Reporting\UnitOrdersDeliveredPivotReport;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UnitsPerChairTypeStackedChartWidget extends ChartWidget
{
    use HasReportingStatisticsChartHeight;

    /**
     * Chair type keys (trimmed product chair_type) hidden from the chart; toggled via the HTML legend.
     *
     * @var list<string>
     */
    public array $hiddenChairTypeKeys = [];

    /**
     * First year column (optional empty = hide that column).
     */
    public ?string $yearLeft = null;

    /**
     * Second year column (optional empty = hide that column).
     */
    public ?string $yearRight = null;

    /**
     * Selected chair type key for the monthly trend line overlay (empty = none).
     */
    public ?string $trendChairTypeKey = null;

    protected string $view = 'filament.widgets.reporting.units-per-chair-type-stacked-chart-widget';

    protected int|string|array $columnSpan = 1;

    /**
     * @var list<string>
     */
    private const CHART_PALETTE_BG = [
        'rgba(1, 152, 199, 0.92)',
        'rgba(16, 185, 129, 0.92)',
        'rgba(245, 158, 11, 0.92)',
        'rgba(139, 92, 246, 0.92)',
        'rgba(236, 72, 153, 0.92)',
        'rgba(14, 165, 233, 0.92)',
        'rgba(234, 179, 8, 0.92)',
        'rgba(99, 102, 241, 0.92)',
        'rgba(244, 63, 94, 0.92)',
        'rgba(20, 184, 166, 0.92)',
        'rgba(251, 146, 60, 0.92)',
        'rgba(168, 85, 247, 0.92)',
    ];

    /**
     * @var list<string>
     */
    private const CHART_PALETTE_BG_PREV = [
        'rgba(1, 152, 199, 0.5)',
        'rgba(16, 185, 129, 0.5)',
        'rgba(245, 158, 11, 0.5)',
        'rgba(139, 92, 246, 0.5)',
        'rgba(236, 72, 153, 0.5)',
        'rgba(14, 165, 233, 0.5)',
        'rgba(234, 179, 8, 0.5)',
        'rgba(99, 102, 241, 0.5)',
        'rgba(244, 63, 94, 0.5)',
        'rgba(20, 184, 166, 0.5)',
        'rgba(251, 146, 60, 0.5)',
        'rgba(168, 85, 247, 0.5)',
    ];

    /**
     * @var list<string>
     */
    private const CHART_PALETTE_BORDER = [
        'rgb(1, 120, 158)',
        'rgb(5, 122, 85)',
        'rgb(180, 83, 9)',
        'rgb(109, 40, 217)',
        'rgb(190, 24, 93)',
        'rgb(3, 105, 161)',
        'rgb(161, 98, 7)',
        'rgb(67, 56, 202)',
        'rgb(190, 18, 60)',
        'rgb(13, 148, 136)',
        'rgb(194, 65, 12)',
        'rgb(126, 34, 206)',
    ];

    public function mount(): void
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();
        if ($this->yearLeft === null || $this->yearLeft === '') {
            $this->yearLeft = (string) $bounds['max'];
        }
        if (($this->yearRight === null || $this->yearRight === '') && $bounds['max'] > $bounds['min']) {
            $this->yearRight = (string) ($bounds['max'] - 1);
        }

        parent::mount();
    }

    public function updatedYearLeft(?string $value): void
    {
        $this->hiddenChairTypeKeys = [];
        $this->ensureAtLeastOneYearSelected();
        $this->syncTrendChairTypeWithYears();
        $this->cachedData = null;
        $this->updateChartData();
    }

    public function updatedYearRight(?string $value): void
    {
        $this->hiddenChairTypeKeys = [];
        $this->ensureAtLeastOneYearSelected();
        $this->syncTrendChairTypeWithYears();
        $this->cachedData = null;
        $this->updateChartData();
    }

    public function updatedTrendChairTypeKey(?string $value): void
    {
        $this->cachedData = null;
        $this->updateChartData();
    }

    private function ensureAtLeastOneYearSelected(): void
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();
        $left = $this->parseYearInBounds($this->yearLeft, $bounds);
        $right = $this->parseYearInBounds($this->yearRight, $bounds);
        if ($left === null && $right === null) {
            $this->yearLeft = (string) $bounds['max'];
        }
    }

    public function toggleChairTypeLegend(string $chairKey): void
    {
        if (in_array($chairKey, $this->hiddenChairTypeKeys, true)) {
            $this->hiddenChairTypeKeys = array_values(array_filter(
                $this->hiddenChairTypeKeys,
                fn (string $k): bool => $k !== $chairKey,
            ));
        } else {
            $this->hiddenChairTypeKeys[] = $chairKey;
            if ($chairKey === $this->trendChairTypeKey) {
                $this->trendChairTypeKey = null;
            }
        }

        $this->cachedData = null;
        $this->updateChartData();
    }

    /**
     * @return list<array{key: string, label: string, fill: string, stroke: string, visible: bool}>
     */
    public function getChairTypeLegendItems(): array
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();
        ['first' => $firstYear, 'second' => $secondYear, 'years' => $activeYears] = $this->resolvedYearColumns($bounds);

        if ($activeYears === []) {
            return [];
        }

        $types = $this->distinctChairTypesForYearsList($activeYears);
        if ($types->isEmpty()) {
            return [];
        }

        $items = [];
        $i = 0;
        foreach ($types as $chairKey) {
            $items[] = [
                'key' => $chairKey,
                'label' => $chairKey,
                'fill' => self::CHART_PALETTE_BG[$i % count(self::CHART_PALETTE_BG)],
                'stroke' => self::CHART_PALETTE_BORDER[$i % count(self::CHART_PALETTE_BORDER)],
                'visible' => ! in_array($chairKey, $this->hiddenChairTypeKeys, true),
            ];
            ++$i;
        }

        return $items;
    }

    public function getHeading(): string
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();
        ['first' => $firstYear, 'second' => $secondYear, 'years' => $activeYears] = $this->resolvedYearColumns($bounds);

        if ($activeYears === []) {
            return 'Units per type';
        }

        if (count($activeYears) === 1) {
            return 'Units per type (' . (string) $activeYears[0] . ')';
        }

        return 'Units per type (' . (string) $firstYear . ' · ' . (string) $secondYear . ')';
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
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();
        ['first' => $firstYear, 'second' => $secondYear, 'years' => $activeYears] = $this->resolvedYearColumns($bounds);

        $labels = collect(range(1, 12))->map(fn (int $m): string => (string) $m)->all();

        if ($activeYears === []) {
            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Geen data',
                        'data' => array_fill(0, 12, 0),
                        'stack' => 'solo',
                        'backgroundColor' => 'rgba(148, 163, 184, 0.4)',
                        'borderColor' => 'rgb(148, 163, 184)',
                        'borderWidth' => 1,
                    ],
                ],
            ];
        }

        $types = $this->distinctChairTypesForYearsList($activeYears);
        if ($types->isEmpty()) {
            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Geen data',
                        'data' => array_fill(0, 12, 0),
                        'stack' => 'solo',
                        'backgroundColor' => 'rgba(148, 163, 184, 0.4)',
                        'borderColor' => 'rgb(148, 163, 184)',
                        'borderWidth' => 1,
                    ],
                ],
            ];
        }

        $orderedKeys = $types->values()->all();
        $hasVisible = false;
        foreach ($orderedKeys as $chairKey) {
            if (! in_array($chairKey, $this->hiddenChairTypeKeys, true)) {
                $hasVisible = true;

                break;
            }
        }

        if (! $hasVisible) {
            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Alles verborgen',
                        'data' => array_fill(0, 12, 0),
                        'stack' => 'solo',
                        'backgroundColor' => 'rgba(148, 163, 184, 0.25)',
                        'borderColor' => 'rgb(148, 163, 184)',
                        'borderWidth' => 1,
                    ],
                ],
            ];
        }

        $datasets = [];
        $twoYears = $firstYear !== null && $secondYear !== null;

        if ($twoYears) {
            foreach ($orderedKeys as $idx => $chairKey) {
                if (in_array($chairKey, $this->hiddenChairTypeKeys, true)) {
                    continue;
                }

                $bgPrev = self::CHART_PALETTE_BG_PREV[$idx % count(self::CHART_PALETTE_BG_PREV)];
                $border = self::CHART_PALETTE_BORDER[$idx % count(self::CHART_PALETTE_BORDER)];

                $datasets[] = [
                    'label' => $chairKey,
                    'data' => $this->monthlyQuantitiesForChairYear($firstYear, $chairKey),
                    'stack' => 'year_first',
                    'backgroundColor' => $bgPrev,
                    'borderColor' => $border,
                    'borderWidth' => 1,
                ];
            }

            foreach ($orderedKeys as $idx => $chairKey) {
                if (in_array($chairKey, $this->hiddenChairTypeKeys, true)) {
                    continue;
                }

                $bgCurr = self::CHART_PALETTE_BG[$idx % count(self::CHART_PALETTE_BG)];
                $border = self::CHART_PALETTE_BORDER[$idx % count(self::CHART_PALETTE_BORDER)];

                $datasets[] = [
                    'label' => $chairKey,
                    'data' => $this->monthlyQuantitiesForChairYear($secondYear, $chairKey),
                    'stack' => 'year_second',
                    'backgroundColor' => $bgCurr,
                    'borderColor' => $border,
                    'borderWidth' => 1,
                ];
            }
        } else {
            $onlyYear = $activeYears[0];
            foreach ($orderedKeys as $idx => $chairKey) {
                if (in_array($chairKey, $this->hiddenChairTypeKeys, true)) {
                    continue;
                }

                $bgCurr = self::CHART_PALETTE_BG[$idx % count(self::CHART_PALETTE_BG)];
                $border = self::CHART_PALETTE_BORDER[$idx % count(self::CHART_PALETTE_BORDER)];

                $datasets[] = [
                    'label' => $chairKey,
                    'data' => $this->monthlyQuantitiesForChairYear($onlyYear, $chairKey),
                    'stack' => 'solo',
                    'backgroundColor' => $bgCurr,
                    'borderColor' => $border,
                    'borderWidth' => 1,
                ];
            }
        }

        $this->maybeAppendTrendLineDataset($datasets, $bounds, $orderedKeys);

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $datasets
     * @param  array{min: int, max: int}  $bounds
     * @param  list<string>  $orderedKeys
     */
    private function maybeAppendTrendLineDataset(array &$datasets, array $bounds, array $orderedKeys): void
    {
        $key = $this->trendChairTypeKey;
        if ($key === null || $key === '') {
            return;
        }

        if (! in_array($key, $orderedKeys, true)) {
            return;
        }

        if (in_array($key, $this->hiddenChairTypeKeys, true)) {
            return;
        }

        $trendYear = $this->trendYearForLine($bounds);
        if ($trendYear === null) {
            return;
        }

        $datasets[] = [
            'type' => 'line',
            'label' => 'Trend: '.$key.' ('.(string) $trendYear.')',
            'data' => $this->monthlyQuantitiesForChairYear($trendYear, $key),
            'fill' => false,
            'borderColor' => 'rgb(1, 120, 158)',
            'backgroundColor' => 'transparent',
            'borderWidth' => 2,
            'tension' => 0.15,
            'pointRadius' => 3,
            'pointHoverRadius' => 4,
            'order' => 100,
        ];
    }

    /**
     * Year used for the trend line: right column when both years are selected, otherwise the active year.
     *
     * @param  array{min: int, max: int}  $bounds
     */
    private function trendYearForLine(array $bounds): ?int
    {
        ['first' => $firstYear, 'second' => $secondYear, 'years' => $activeYears] = $this->resolvedYearColumns($bounds);
        if ($activeYears === []) {
            return null;
        }

        return $secondYear ?? $firstYear;
    }

    private function syncTrendChairTypeWithYears(): void
    {
        if ($this->trendChairTypeKey === null || $this->trendChairTypeKey === '') {
            return;
        }

        $bounds = $this->deliveredFrameWithChairYearBounds();
        ['years' => $activeYears] = $this->resolvedYearColumns($bounds);
        if ($activeYears === []) {
            $this->trendChairTypeKey = null;

            return;
        }

        $types = $this->distinctChairTypesForYearsList($activeYears);
        if (! in_array($this->trendChairTypeKey, $types->all(), true)) {
            $this->trendChairTypeKey = null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getTrendChairTypeOptions(): array
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();
        ['years' => $activeYears] = $this->resolvedYearColumns($bounds);

        $options = ['' => 'Geen trendlijn'];

        if ($activeYears === []) {
            return $options;
        }

        foreach ($this->distinctChairTypesForYearsList($activeYears) as $chairKey) {
            $options[$chairKey] = $chairKey;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Maand',
                    ],
                ],
                'y' => [
                    'stacked' => true,
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
     * @param  list<int>  $years
     * @return Collection<int, string>
     */
    private function distinctChairTypesForYearsList(array $years): Collection
    {
        $years = array_values(array_unique(array_values($years)));
        if ($years === []) {
            return collect();
        }

        return DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereNotNull('products.chair_type')
            ->where('products.chair_type', '!=', '')
            ->where(function ($q) use ($years): void {
                foreach ($years as $i => $year) {
                    if ($i === 0) {
                        $q->whereYear('order_products.delivered_at', $year);
                    } else {
                        $q->orWhereYear('order_products.delivered_at', $year);
                    }
                }
            })
            ->selectRaw('TRIM(products.chair_type) as chair_key')
            ->groupBy(UnitOrdersDeliveredPivotReport::chairTypeGroupByColumn('products'))
            ->orderBy('chair_key')
            ->pluck('chair_key');
    }

    /**
     * @return array{min: int, max: int}
     */
    private function deliveredFrameWithChairYearBounds(): array
    {
        $row = DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereNotNull('products.chair_type')
            ->where('products.chair_type', '!=', '')
            ->selectRaw('MIN(YEAR(order_products.delivered_at)) as min_y, MAX(YEAR(order_products.delivered_at)) as max_y')
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
     * @param  array{min: int, max: int}  $bounds
     * @return array{first: ?int, second: ?int, years: list<int>}
     */
    private function resolvedYearColumns(array $bounds): array
    {
        $left = $this->parseYearInBounds($this->yearLeft, $bounds);
        $right = $this->parseYearInBounds($this->yearRight, $bounds);

        if ($left !== null && $right !== null && $left === $right) {
            $right = null;
        }

        $first = $left;
        $second = $right;
        if ($first === null && $second !== null) {
            $first = $second;
            $second = null;
        }

        $years = [];
        if ($first !== null) {
            $years[] = $first;
        }
        if ($second !== null) {
            $years[] = $second;
        }

        if ($years === []) {
            $fallback = $bounds['max'];
            $first = $fallback;

            return [
                'first' => $first,
                'second' => null,
                'years' => [$first],
            ];
        }

        return [
            'first' => $first,
            'second' => $second,
            'years' => $years,
        ];
    }

    /**
     * @param  array{min: int, max: int}  $bounds
     */
    private function parseYearInBounds(?string $value, array $bounds): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $y = (int) $value;
        if ($y < $bounds['min'] || $y > $bounds['max']) {
            return null;
        }

        return $y;
    }

    /**
     * @return array<int, int> length 12, index 0 = January
     */
    private function monthlyQuantitiesForChairYear(int $year, string $chairKey): array
    {
        $rows = DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereYear('order_products.delivered_at', $year)
            ->whereRaw('TRIM(products.chair_type) = ?', [$chairKey])
            ->selectRaw('MONTH(order_products.delivered_at) as m, SUM(order_products.qty) as q')
            ->groupByRaw('MONTH(order_products.delivered_at)')
            ->pluck('q', 'm');

        $out = [];
        foreach (range(1, 12) as $m) {
            $out[] = (int) round((float) ($rows[$m] ?? 0));
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function getYearSelectOptions(): array
    {
        return $this->yearSelectOptions();
    }

    /**
     * @return array<string, string>
     */
    private function yearSelectOptions(): array
    {
        $bounds = $this->deliveredFrameWithChairYearBounds();

        return collect(range($bounds['max'], $bounds['min']))
            ->mapWithKeys(fn (int $y): array => [(string) $y => (string) $y])
            ->all();
    }
}
