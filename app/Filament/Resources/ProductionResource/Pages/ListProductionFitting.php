<?php

namespace App\Filament\Resources\ProductionResource\Pages;

use App\Models\Order\Main;
use Filament\Tables\Enums\FiltersLayout;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Filament\Resources\ProductionResource;
use App\Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class ListProductionFitting extends ListProduction
{
    protected static string $resource = ProductionResource::class;

    public ?string $status = 'fitting';

    public function getBreadcrumbs(): array
    {
        return array_merge(parent::getBreadcrumbs(), ['Passing']);
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return $this->getTableQueryFitting()
            ->with(['advisor', 'customer', 'billingCustomer']);
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
                        WHEN order_status = ? THEN 4
                        ELSE 5
                    END ASC',
                    [
                        OrderStatus::FittingDraft->value,
                        OrderStatus::FittingOnHold->value,
                        OrderStatus::FittingPlanned->value,
                        OrderStatus::FittingReady->value,
                    ]
                )
                ->orderByDesc('sent_at')
            )
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'advisor',
                'customer',
                'billingCustomer',
            ]))
            ->columns([

                ProductionResource::aanvraagNummerColumn()
                    ->width('5%'),

                TextColumn::make('subtype')
                    ->label('Type')
                    ->sortable(['subtype'])
                    ->searchable()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof OrderSubtype
                        ? ($state->getLabel() ?? $state->value)
                        : '-'),

                TextColumn::make('order_status')
                    ->label('Aanvraag-status')
                    ->formatStateUsing(fn ($state): string => $state instanceof OrderStatus ? ($state->getLabel() ?? $state->value) : (OrderStatus::tryFrom($state)?->getLabel() ?? (string) $state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';

                        return $query
                            ->orderByRaw(
                                "CASE
                                    WHEN order_status = ? THEN 1
                                    WHEN order_status = ? THEN 2
                                    WHEN order_status = ? THEN 3
                                    WHEN order_status = ? THEN 4
                                    ELSE 5
                                END {$dir}",
                                [
                                    OrderStatus::FittingDraft->value,
                                    OrderStatus::FittingOnHold->value,
                                    OrderStatus::FittingPlanned->value,
                                    OrderStatus::FittingReady->value,
                                ]
                            )
                            ->orderBy('order_status', $dir);
                    }),

                TextColumn::make('customer_id')
                    ->label('Klant')
                    ->formatStateUsing(fn (Main $record): string => $record->getCustomerAddressDisplayName() ?? '')
                    ->url(fn (Main $record): string => $record->customer_id
                        ? route('filament.app.resources.customers.edit', ['record' => $record->customer_id])
                        : '')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('customer', fn (Builder $q) => $q
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                    }),


                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn(Main $record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))),

                TextColumn::make('advisor_id')
                    ->label('Adviseur')
                    ->state(fn (Main $record): string => $record->advisor
                        ? trim($record->advisor->first_name . ' ' . $record->advisor->last_name)
                        : '')
                    ->sortable(['advisor_id'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('advisor', fn (Builder $a) => $a
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                    }),

                TextColumn::make('created_at')
                    ->label('Datum (aangemaakt)')
                    ->date('j M Y')
                    ->sortable(['created_at'])
                    ->searchable(['created_at']),
            ])
            ->toolbarActions([])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                Resource::getOrderStatusFilterForSubStatuses(OrderStatus::Fitting),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
            ])
            ->extraAttributes(['class' => 'searchAlignLeft']);
    }
}
