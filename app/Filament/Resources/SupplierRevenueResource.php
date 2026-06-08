<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierRevenueResource\Pages\ListSupplierRevenue;
use App\Models\Supplier;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class SupplierRevenueResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Omzet per leverancier';

    protected static ?string $pluralLabel = 'Omzet per leverancier';

    protected static ?string $navigationLabel = 'Omzet per leverancier';

    protected static ?string $slug = 'supplier-revenue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

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
            'index' => ListSupplierRevenue::route('/'),
        ];
    }
}
