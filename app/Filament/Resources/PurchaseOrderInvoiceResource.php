<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderInvoiceResource\Pages\ListPurchaseOrderInvoices;
use App\Models\PurchaseOrderInvoice;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderInvoiceResource extends Resource
{
    protected static ?string $model = PurchaseOrderInvoice::class;

    protected static ?string $breadcrumb = 'Financieel';

    protected static ?string $modelLabel = 'inkoopfactuur';

    protected static ?string $pluralLabel = 'inkoopfacturen';

    protected static ?string $slug = 'purchase-order-invoices';

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage financials') ?? false;
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
            ->with([
                'orderable.supplier',
                'orderable.order',
                'orderable.main',
                'media',
            ]);
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
            'index' => ListPurchaseOrderInvoices::route('/'),
        ];
    }
}
