<?php

namespace App\Filament\Resources;

use App\Enums\OrderGeneralStatus;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Resources\CreditInvoiceResource\Pages\ListCreditInvoices;
use App\Filament\Resources\CreditInvoiceResource\Pages\EditCreditInvoice;
use App\Filament\Tables\Columns\ReportingOrderNumberColumn;
use App\Models\Order\BaseOrder;
use App\Support\NavigationLink;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CreditInvoiceResource extends Resource
{
    protected static ?string $model = BaseOrder::class;
    protected static ?string $breadcrumb = 'Verkoop';
    protected static ?string $modelLabel = 'Creditfacturen';
    protected static ?string $pluralModelLabel = 'creditfacturen';
    protected static ?string $slug = 'credit-invoices';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage financials') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('type', ['credit_invoice'])
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([])
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                ReportingOrderNumberColumn::make('credit_invoice.uid')
                    ->label('Factuurnummer')
                    ->viewData(['displayDate' => false, 'showCancelled' => false])
                    ->searchable(['uid', 'rev'])
                    ->sortable(['uid', 'rev'])
                    ->disabledClick(),

                TextColumn::make('sent_at')
                    ->label('Datum')
                    ->date('j M Y (H:i)')
                    ->searchable(['sent_at'])
                    ->sortable(['sent_at'])
                    ->disabledClick(),

                TextColumn::make('main.uid')
                    ->label('Aanvraagnummer')
                    ->formatStateUsing(fn (BaseOrder $record) => NavigationLink::main(
                        $record->main_id,
                        $record->main?->getUidFormatted(),
                    ))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Klant')
                    ->searchable()
                    ->sortable()
                    ->disabledClick(),

                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn($record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q->where('name', 'like', "%{$search}%")))
                    ->disabledClick(),

                TextColumn::make('payment_amount')
                    ->label('Factuurwaarde')
                    ->sortable(['payment_amount'])
                    ->money('eur')
                    ->disabledClick(),
            ])
            ->defaultSort(
                fn(Builder $query) => $query
                    ->orderBy('created_at', 'desc')
                    ->orderBy('sent_at', 'desc')
            )
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                Resource::getDealerFilter('invoices'),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditInvoices::route('/'),
            'edit' => EditCreditInvoice::route('/{record}/edit'),
            'edit-from-main' => \App\Filament\Resources\CreditInvoiceResource\Pages\EditCreditInvoiceFromMain::route('/{record}/edit-from-main'),
        ];
    }
}
