<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarginOverviewResource\Pages\ListMarginOverview;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;

class MarginOverviewResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $breadcrumb = 'Reporting';
    protected static ?string $modelLabel = 'Margeoverzicht';
    protected static ?string $pluralLabel = 'orders';
    protected static ?string $slug = 'margin-overview';

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
            'index' => ListMarginOverview::route('/'),
        ];
    }
}
