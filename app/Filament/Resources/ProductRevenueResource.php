<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductRevenueResource\Pages\ListProductRevenue;
use App\Models\Product;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ProductRevenueResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Omzet';

    protected static ?string $pluralLabel = 'Omzet';

    protected static ?string $navigationLabel = 'Omzet';

    protected static ?string $slug = 'product-revenue';

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
            'index' => ListProductRevenue::route('/'),
        ];
    }
}
