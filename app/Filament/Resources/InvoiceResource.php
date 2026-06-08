<?php

namespace App\Filament\Resources;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Filament\Tables\Columns\InvoiceNumberColumn;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Actions\Action;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Tables\Columns\PaidColumn;
use App\Models\Order\BaseOrder;
use App\Support\NavigationLink;
use Exception;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Tables\Columns\ReportingDateNumberColumn;


class InvoiceResource extends Resource
{
    protected static ?string $model = BaseOrder::class;
    protected static ?string $breadcrumb = 'Verkoop';
    protected static ?string $modelLabel = 'facturen';
    protected static ?string $pluralLabel = 'facturen';
    protected static ?string $slug = 'invoices';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage financials') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function isStandaloneInvoice(?BaseOrder $record): bool
    {
        if (! $record instanceof BaseOrder) {
            return false;
        }

        return $record->getType() === OrderType::Invoice
            && $record->main_id === null
            && $record->order_id === null;
    }

    public static function canEdit(Model $record): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        if (! $record instanceof BaseOrder) {
            return false;
        }

        return static::isStandaloneInvoice($record)
            && $record->getStatus() === OrderGeneralStatus::Initial;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('main')
            ->whereIn('type', ['deposit_invoice', 'invoice'])
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('order.modal')->columnSpan(2)
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->headerActions([
                Action::make('invoice')
                    ->label('Factuur aanmaken')
                    ->url(route('filament.app.resources.invoices.create'))
                    ->icon('heroicon-s-plus-circle')
                    ->extraAttributes(['class' => 'invoice-create-custom']),
            ])
            ->columns([
                ReportingDateNumberColumn::make('id')
                    ->label('Factuurdatum')
                    ->searchable(['sent_at', 'rev'])
                    ->sortable(['sent_at', 'rev'])
                    ->disabledClick(),

                InvoiceNumberColumn::make('uid')
                    ->label('Factuurnummer')
                    ->searchable(['uid', 'rev'])
                    ->sortable(['uid', 'rev'])
                    ->disabledClick(),

                TextColumn::make('payment_amount')
                    ->label('Factuurwaarde')
                    ->sortable(['payment_amount'])
                    ->money('eur')
                    ->disabledClick(),

                TextColumn::make('main.reference_internal')
                    ->label('Referentie (intern)')
                    ->state(fn (BaseOrder $record): string => trim((string) ($record->main?->getReferenceInternal() ?? '')) ?: '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'main',
                        fn (Builder $q): Builder => $q->where('reference_internal', 'like', "%{$search}%"),
                    ))
                    ->sortable()
                    ->disabledClick(),

                TextColumn::make('main.uid')
                    ->label('Aanvraagnummer')
                    ->formatStateUsing(fn (BaseOrder $record): \Illuminate\Contracts\Support\Htmlable|string => NavigationLink::main(
                        $record->main_id,
                        $record->main?->getUidFormatted(),
                    ))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'main',
                        fn (Builder $q): Builder => $q->where('uid', 'like', "%{$search}%"),
                    ))
                    ->sortable()
                    ->disabledClick(),

                PaidColumn::make('payment')
                    ->label('Betaald'),

                TextColumn::make('billingCustomer.name')
                    ->label('Klant')
                    ->state(fn (BaseOrder $record): string => trim((string) (
                        $record->billingCustomer?->getName()
                        ?? $record->customer?->getName()
                        ?? ''
                    )))
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->whereHas('billingCustomer', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"));
                    })),
            ])
            ->deferFilters(false)
            ->filters(
                [
                    Resource::getDateFilter(),
                    Resource::getDealerFilter('invoices'),
                ],
                layout: FiltersLayout::AboveContent
            )
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('credit')
                    ->label('Crediteren')
                    ->visible(fn (BaseOrder $record): bool => auth()->user()?->can('manage financials')
                        && $record->getStatus() !== OrderGeneralStatus::Initial)
                    ->action(function (BaseOrder $record) {
                        redirect(route('filament.app.resources.credit-invoices.edit', [
                            'record' => $record->createCreditInvoice()->id
                        ]));
                    })
                    ->extraAttributes(['class' => '']),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'edit' => EditInvoice::route('/{record}/edit'),
            'edit-from-main' => \App\Filament\Resources\InvoiceResource\Pages\EditInvoiceFromMain::route('/{record}/edit-from-main'),
        ];
    }
}
