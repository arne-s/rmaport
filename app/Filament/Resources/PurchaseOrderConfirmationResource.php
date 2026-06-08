<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderConfirmationResource\Pages\ListPurchaseOrderConfirmations;
use App\Filament\Support\PurchaseAuthorization;
use App\Models\PurchaseOrderConfirmation;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderConfirmationResource extends Resource
{
    protected static ?string $model = PurchaseOrderConfirmation::class;

    protected static ?string $breadcrumb = 'Inkoop';

    protected static ?string $modelLabel = 'inkoopbevestiging';

    protected static ?string $pluralLabel = 'inkoopbevestigingen';

    protected static ?string $slug = 'purchase-order-confirmations';

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return PurchaseAuthorization::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('pdf_path')
            ->where('pdf_path', '!=', '')
            ->with(['purchaseOrder.supplier', 'purchaseOrder.order', 'purchaseOrder.main']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrderConfirmations::route('/'),
        ];
    }
}
