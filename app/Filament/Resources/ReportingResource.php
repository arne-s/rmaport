<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportingResource\Pages\RevenueOverview;
use App\Filament\Resources\ReportingResource\Pages\StatisticsOverview;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;

class ReportingResource extends Resource
{
    protected static ?string $breadcrumb = 'Reporting';
    protected static ?string $modelLabel = 'Reporting';
    protected static ?string $slug = 'reporting';
    protected static ?string $model = Order::class;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage reporting') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            // 'index' => ListReportings::route('/'),
            'revenue' => RevenueOverview::route('/revenue'),
            'statistics' => StatisticsOverview::route('/statistics'),
        ];
    }
}
