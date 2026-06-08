<?php

namespace App\Filament\Resources\ProductionResource\Pages;

use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use Filament\Tables\Enums\FiltersLayout;
use App\Enums\OrderStatus;
use App\Filament\Resources\ProductionResource;
use App\Filament\Resources\Resource;
use App\Filament\Tables\Columns\PaidColumn;
use App\Filament\Tables\Columns\ReportingOrderNumberColumn;
use App\Models\Order\Order;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Layout;
use Illuminate\Database\Eloquent\Builder;

class ListProductionPurchased extends ListProduction
{
    protected static string $resource = ProductionResource::class;

    public ?string $status = 'purchased';

    public function getBreadcrumbs(): array
    {
        return array_merge(parent::getBreadcrumbs(), ['Inkoop']);
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return $this->getTableQueryPurchased();
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions(ProductionResource::productionTableHeaderActions())
            ->defaultSort('sent_at', 'desc')
            ->columns([
                ProductionResource::aanvraagNummerColumn(),

                TextColumn::make('subtype')
                    ->label('Type')
                    ->sortable(['subtype'])
                    ->searchable()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof OrderSubtype
                        ? ($state->getLabel() ?? $state->value)
                        : '-'),


                TextColumn::make('order_status')
                    ->label('Aanvraag-status')
                    ->formatStateUsing(fn($state): string => $state instanceof OrderStatus ? ($state->getLabel() ?? $state->value) : (OrderStatus::tryFrom($state)?->getLabel() ?? (string)$state))
                    ->sortable(),
                TextColumn::make('customer_id')
                    ->label('Klant')
                    ->formatStateUsing(fn (Main $record): string => $record->getCustomerAddressDisplayName() ?? '')
                    ->sortable(['customer_id'])
                    ->url(fn (Main $record): ?string => $record->customer_id === null
                        ? null
                        : route('filament.app.resources.customers.edit', ['record' => $record->customer_id]))
                    ->searchable(),

                TextColumn::make('product_summary')
                    ->label('Artikelen: O / I / A / G')
                    ->default('0 / 0 / 0 / 0'),


                ReportingOrderNumberColumn::make('deposit_invoice.uid')
                    ->label('Aanbetalingsfactuur')
                    ->searchable(['uid', 'rev'])
                    ->sortable(['uid', 'rev']),



                PaidColumn::make('deposit_invoice.payment')
                    ->label('Betaald')
                    ->sortable(
                        ['deposit_invoice.paid_at', 'deposit_invoice.payment_method'],
                        fn (Builder $query) => $query->with('depositInvoice')
                    ),

                ReportingOrderNumberColumn::make('invoice.uid')
                    ->label('Slotfactuur')
                    ->searchable(['uid', 'rev'])
                    ->sortable(['uid', 'rev']),



                PaidColumn::make('invoice.payment')
                    ->label('Betaald')
                    ->sortable(
                        ['invoice.paid_at', 'invoice.payment_method'],
                        fn (Builder $query) => $query->with('depositInvoice')
                    ),

                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->sortable()
                    ->extraAttributes(['class' => 'flex flex-col'])
                    ->view('components.client-with-endclient', ['name' => 'first_name'])
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q->where('name', 'like', "%{$search}%"))),



            ])
            ->toolbarActions([])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                Resource::getDealerFilter('orders'),
                Resource::getOrderStatusFilterForSubStatuses(OrderStatus::Purchased),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
            ]);
    }
}
