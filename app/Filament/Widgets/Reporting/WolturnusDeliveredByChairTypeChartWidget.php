<?php

namespace App\Filament\Widgets\Reporting;

use App\Enums\ProductType;
use App\Services\Reporting\UnitOrdersDeliveredPivotReport;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WolturnusDeliveredByChairTypeChartWidget extends ChartWidget
{
    private const SUPPLIER_NAME_LIKE = '%wolturnus%';

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

    protected string $view = 'filament.widgets.reporting.wolturnus-delivered-by-chair-type-chart-widget';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '480px';

    public function mount(): void
    {
        if ($this->filter === null || $this->filter === '') {
            $this->filter = (string) $this->wolturnusDeliveredYearBounds()['max'];
        }

        parent::mount();
    }

    /**
     * ChartWidget memoïseert {@see ChartWidget::getCachedData()} once; without clearing,
     * switching the year leaves stale chart payloads so Alpine/updateChartData stays out of sync
     * with the table and legend clicks appear to reset immediately.
     */
    public function updatedFilter(?string $value): void
    {
        $this->cachedData = null;
    }

    public function getHeading(): string
    {
        return 'Wolturnus';
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
    protected function getData(): array
    {
        $year = $this->resolvedWolturnusFilterYear();
        $built = $this->wolturnusLabelsAndSeries($year);

        $datasets = [];
        $paletteIndex = 0;
        foreach ($built['series'] as $row) {
            if ($row['label'] === 'Geen data') {
                $datasets[] = [
                    'label' => $row['label'],
                    'data' => $row['values'],
                    'stack' => 'wolturnus',
                    'backgroundColor' => 'rgba(148, 163, 184, 0.4)',
                    'borderColor' => 'rgb(148, 163, 184)',
                    'borderWidth' => 1,
                ];

                continue;
            }

            $bg = self::CHART_PALETTE_BG[$paletteIndex % count(self::CHART_PALETTE_BG)];
            $border = self::CHART_PALETTE_BORDER[$paletteIndex % count(self::CHART_PALETTE_BORDER)];

            $datasets[] = [
                'label' => $row['label'],
                'data' => $row['values'],
                'stack' => 'wolturnus',
                'backgroundColor' => $bg,
                'borderColor' => $border,
                'borderWidth' => 1,
            ];
            ++$paletteIndex;
        }

        return [
            'labels' => $built['labels'],
            'datasets' => $datasets,
        ];
    }

    /**
     * Month columns and per-series counts aligned with the chart (same year filter).
     *
     * @return array{year: int, labels: list<string>, series: list<array{label: string, values: list<int>}>}
     */
    public function getWolturnusTableData(): array
    {
        $year = $this->resolvedWolturnusFilterYear();

        return array_merge(
            ['year' => $year],
            $this->wolturnusLabelsAndSeries($year)
        );
    }

    private function resolvedWolturnusFilterYear(): int
    {
        $bounds = $this->wolturnusDeliveredYearBounds();
        $year = (int) ($this->filter ?? $bounds['max']);
        if ($year < $bounds['min'] || $year > $bounds['max']) {
            return $bounds['max'];
        }

        return $year;
    }

    /**
     * @return list<string>
     */
    private function monthLabelsForYear(int $year): array
    {
        return collect(range(1, 12))
            ->map(fn (int $m): string => $m . '-' . $year)
            ->all();
    }

    /**
     * @return array{labels: list<string>, series: list<array{label: string, values: list<int>}>}
     */
    private function wolturnusLabelsAndSeries(int $year): array
    {
        $labels = $this->monthLabelsForYear($year);
        $types = $this->distinctChairTypesWolturnusYear($year);
        if ($types->isEmpty()) {
            return [
                'labels' => $labels,
                'series' => [
                    [
                        'label' => 'Geen data',
                        'values' => array_fill(0, 12, 0),
                    ],
                ],
            ];
        }

        $series = [];
        foreach ($types as $chairKey) {
            $displayChair = $chairKey === '' ? '(geen type)' : $chairKey;
            $series[] = [
                'label' => 'Wolturnus ' . $displayChair,
                'values' => $this->monthlyQuantitiesWolturnusChairYear($year, $chairKey),
            ];
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        // Non-responsive chart + fixed canvas size avoids Chart.js getMaximumSize / ownerDocument
        // issues with the 13-column grid and scrollbar. Canvas is CSS-scaled in the blade.
        return [
            'responsive' => false,
            'animation' => false,
            'resizeDelay' => 0,
            'layout' => [
                'padding' => [
                    'bottom' => 0,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => true,
                    'ticks' => [
                        'display' => false,
                    ],
                    'title' => [
                        'display' => false,
                    ],
                ],
                'y' => [
                    'stacked' => true,
                    'position' => 'right',
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Aantal',
                    ],
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * Distinct chair keys (trimmed); empty string bucket for missing chair_type.
     *
     * @return Collection<int, string>
     */
    private function distinctChairTypesWolturnusYear(int $year): Collection
    {
        return DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->join('suppliers', 'order_products.supplier_id', '=', 'suppliers.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereYear('order_products.delivered_at', $year)
            ->whereRaw('LOWER(suppliers.name) LIKE ?', [self::SUPPLIER_NAME_LIKE])
            ->selectRaw(
                'COALESCE(NULLIF(TRIM(products.chair_type), \'\'), \'\') as chair_key'
            )
            ->groupBy(UnitOrdersDeliveredPivotReport::chairTypeGroupByColumn('products'))
            ->orderByRaw('chair_key = \'\' ASC')
            ->orderBy('chair_key')
            ->pluck('chair_key');
    }

    /**
     * @return array<int, int> length 12, index 0 = January
     */
    private function monthlyQuantitiesWolturnusChairYear(int $year, string $chairKey): array
    {
        $q = DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->join('suppliers', 'order_products.supplier_id', '=', 'suppliers.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereYear('order_products.delivered_at', $year)
            ->whereRaw('LOWER(suppliers.name) LIKE ?', [self::SUPPLIER_NAME_LIKE]);

        if ($chairKey === '') {
            $q->where(function ($w): void {
                $w->whereNull('products.chair_type')
                    ->orWhereRaw("TRIM(products.chair_type) = ''");
            });
        } else {
            $q->whereRaw('TRIM(products.chair_type) = ?', [$chairKey]);
        }

        $rows = $q
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
     * @return array{min: int, max: int}
     */
    private function wolturnusDeliveredYearBounds(): array
    {
        $row = DB::table('order_products')
            ->join('suppliers', 'order_products.supplier_id', '=', 'suppliers.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereRaw('LOWER(suppliers.name) LIKE ?', [self::SUPPLIER_NAME_LIKE])
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
     * @return array<string, string>
     */
    private function yearSelectOptions(): array
    {
        $bounds = $this->wolturnusDeliveredYearBounds();

        return collect(range($bounds['max'], $bounds['min']))
            ->mapWithKeys(fn (int $y): array => [(string) $y => (string) $y])
            ->all();
    }
}
