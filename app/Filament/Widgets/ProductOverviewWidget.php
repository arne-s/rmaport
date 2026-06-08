<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class ProductOverviewWidget extends BaseWidget
{
    protected string|int|array $columnSpan = 'none';
    protected function getCards(): array
    {
        return [
            Stat::make('Totaal aantal artikelen', Product::query()->count()),
        ];
    }
}
