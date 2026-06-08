<?php

namespace App\Filament\Resources\ReleaseOrders;

use App\Filament\Resources\ReleaseOrders\Pages\EditReleaseOrder;
use App\Filament\Resources\ReleaseOrders\Pages\ListReleaseOrders;
use App\Filament\Resources\ReleaseOrders\Pages\ViewReleaseOrder;
use App\Filament\Resources\ReleaseOrders\Schemas\ReleaseOrderForm;
use App\Filament\Resources\ReleaseOrders\Schemas\ReleaseOrderInfolist;
use App\Filament\Resources\ReleaseOrders\Tables\ReleaseOrdersTable;
use App\Filament\Resources\Resource;
use App\Filament\Support\PurchaseAuthorization;
use App\Models\ReleaseOrder;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReleaseOrderResource extends Resource
{
    protected static ?string $model = ReleaseOrder::class;

    protected static ?string $breadcrumb = 'Afroepen';
    protected static ?string $modelLabel = 'Afroep';
    protected static ?string $slug = 'release-orders';

    public static function canViewAny(): bool
    {
        return PurchaseAuthorization::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return ReleaseOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReleaseOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReleaseOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReleaseOrders::route('/'),
            'view' => ViewReleaseOrder::route('/{record}'),
            'edit' => EditReleaseOrder::route('/{record}/edit'),
        ];
    }
}
