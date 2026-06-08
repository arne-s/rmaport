<?php

namespace App\Filament\Resources\RecurringInvoices;

use App\Filament\Resources\RecurringInvoices\Pages\CreateRecurringInvoice;
use App\Filament\Resources\RecurringInvoices\Pages\EditRecurringInvoice;
use App\Filament\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Filament\Resources\RecurringInvoices\Tables\RecurringInvoicesTable;
use App\Models\RecurringInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RecurringInvoiceResource extends Resource
{
    protected static ?string $model = RecurringInvoice::class;

    protected static ?string $slug = 'recurring-invoices';

    protected static ?string $modelLabel = 'abonnement';

    protected static ?string $pluralLabel = 'abonnementen';

    protected static ?string $navigationLabel = 'Abonnementen';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage financials') ?? false;
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

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['billingCustomer']);
    }

    public static function table(Table $table): Table
    {
        return RecurringInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringInvoices::route('/'),
            'create' => CreateRecurringInvoice::route('/create'),
            'edit' => EditRecurringInvoice::route('/{record}/edit'),
        ];
    }
}
