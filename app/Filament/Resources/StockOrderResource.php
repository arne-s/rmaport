<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockOrderResource\Pages\CreateStockOrder;
use App\Filament\Resources\StockOrderResource\Pages\CreateStockOrderFromProduct;
use App\Filament\Resources\StockOrderResource\Pages\EditStockOrder;
use App\Filament\Resources\StockOrderResource\Pages\ListStockOrders;
use App\Filament\Resources\StockOrderResource\Pages\ViewStockOrder;
use App\Filament\Support\PurchaseAuthorization;
use Filament\Tables\Table;
use App\Models\Order\StockOrder;
use Illuminate\Database\Eloquent\Model;

class StockOrderResource extends Resource
{
    protected static ?string $model = StockOrder::class;

    protected static ?string $breadcrumb = 'Inkoop';
    protected static ?string $modelLabel = 'inkooporders';
    protected static ?string $slug = 'stock-orders';

    public static function canViewAny(): bool
    {
        return PurchaseAuthorization::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOrders::route('/'),
            'create' => CreateStockOrder::route('/create'),
            'create-from-product' => CreateStockOrderFromProduct::route('/create-from-product/{product}'),
            'view' => ViewStockOrder::route('/{record}'),
            'edit' => EditStockOrder::route('/{record}/edit'),
        ];
    }
}
