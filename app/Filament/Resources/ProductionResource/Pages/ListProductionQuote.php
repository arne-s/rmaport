<?php

namespace App\Filament\Resources\ProductionResource\Pages;

use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use Filament\Tables\Enums\FiltersLayout;
use App\Enums\OrderStatus;
use App\Filament\Resources\ProductionResource;
use App\Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Layout;
use Illuminate\Database\Eloquent\Builder;

class ListProductionQuote extends ListProduction
{
    protected static string $resource = ProductionResource::class;

    public ?string $status = 'quote';

    public function getBreadcrumbs(): array
    {
        return array_merge(parent::getBreadcrumbs(), ['Offerte']);
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return $this->getTableQueryQuote();
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions(ProductionResource::productionTableHeaderActions())
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw(
                    'CASE
                        WHEN order_status = ? THEN 1
                        WHEN order_status = ? THEN 2
                        WHEN order_status = ? THEN 3
                        ELSE 4
                    END ASC',
                    [
                        OrderStatus::QuoteDraft->value,
                        OrderStatus::QuoteConcept->value,
                        OrderStatus::QuoteSent->value,
                    ]
                )
                ->orderByDesc('sent_at')
            )
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
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';

                        return $query
                            ->orderByRaw(
                                "CASE
                                    WHEN order_status = ? THEN 1
                                    WHEN order_status = ? THEN 2
                                    WHEN order_status = ? THEN 3
                                    ELSE 4
                                END {$dir}",
                                [
                                    OrderStatus::QuoteDraft->value,
                                    OrderStatus::QuoteConcept->value,
                                    OrderStatus::QuoteSent->value,
                                ]
                            )
                            ->orderBy('order_status', $dir);
                    }),

                TextColumn::make('customer_id')
                    ->label('Klant')
                    ->formatStateUsing(fn (Main $record): string => $record->getCustomerAddressDisplayName() ?? '')
                    ->sortable(['customer_id'])
                    ->url(fn (Main $record): ?string => $record->customer_id === null
                        ? null
                        : route('filament.app.resources.customers.edit', ['record' => $record->customer_id]))
                    ->searchable(),


                TextColumn::make('quote_uid')
                    ->label('Offerte-nummer')
                    ->extraAttributes(['class' => 'flex flex-col'])
                    ->view('components.number-without-date', [
                        'quoteFromMain' => true,
                        'emptyWhenMissing' => true,
                    ]),


                TextColumn::make('quote.expires_at')
                    ->label('Verloopdatum')
                    ->date('d-m-Y')
                    ->sortable(),




                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn(Main $record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q->where('name', 'like', "%{$search}%"))),




            ])
            ->toolbarActions([])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                Resource::getOrderStatusFilterForSubStatuses(OrderStatus::Quote),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
            ]);
    }

}
