<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnitOrdersResource\Pages\ListUnitOrders;
use App\Models\Order\Order;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class UnitOrdersResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Unit orders';

    protected static ?string $pluralLabel = 'Unit orders';

    protected static ?string $slug = 'unit-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static bool $shouldRegisterNavigation = false;

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

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnitOrders::route('/'),
        ];
    }
}
