<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonthyOrdersGraphWidget extends ChartWidget
{
    protected string $view = 'filament.widgets.dashboard.monthly-orders-graph-widget';
    protected int | string | array $columnSpan = 3;

    public ?string $filter = '6';


    public function getHeading(): string
    {
        return 'Aantal orders per maand';
    }

    public function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return [
            '1' => 'Laatste maand',
            '3' => 'Laatste 3 maanden',
            '6' => 'Laatste 6 maanden',
            '12' => 'Laatste 12 maanden',
        ];
    }

    protected ?array $options = [
        'plugins' => [
            'legend' => [
                'display' => false,
            ],
        ],
        'scales' => [
            'y' => [
                'ticks' => [
                    'beginAtZero' => true,
                    'stepSize' => 1,
                ]
            ]
        ]
    ];


    protected function getData(): array
    {
        $numbers = $this->getNumberRange();

        return [
            'datasets' => [
                [
                    'label' => 'Aantal orders per maand',
                    'data' => $numbers->pluck('value'),
                ],
            ],
            'labels' => $numbers->pluck('label'),
        ];
    }

    public function getMaxHeight(): ?string
    {
        return '350px';
    }

    protected function getNumberRange(): Collection
    {
        return DB::table('orders')
            ->selectRaw('MONTH(sent_at) m, YEAR(sent_at) y, COUNT(*) c')
            ->where('type', 'order')
            ->where('created_at', '>=', now()->subMonths((int)$this->filter))
            ->where('created_at', '<=', now())
            ->groupByRaw('YEAR(sent_at), MONTH(sent_at)')
            ->orderByRaw('YEAR(sent_at), MONTH(sent_at)')
            ->get()
            ->map(fn($v) => [
                'label' => Carbon::createFromDate($v->y, $v->m)
                    ->translatedFormat('M'),
                'value' => $v->c,
            ]);
    }
}
