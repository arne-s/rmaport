<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Enums\OrderGeneralStatus;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Concerns\GeneralStatusFilter;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\Resource;
use App\Filament\Tables\Columns\OrderStatusColumn;
use App\Filament\Widgets\QuotesListBackLinkWidget;
use App\Filament\Widgets\QuotesOverviewWidget;
use App\Models\Order\Quote;
use App\Support\NavigationLink;
use Exception;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class ListQuotes extends ListRecords
{
    use GeneralStatusFilter;

    protected static string $resource = QuoteResource::class;
    protected static ?string $breadcrumb = 'Overzicht';
    protected static ?string $title = 'Offerteoverzicht';

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->with(['customer', 'billingCustomer', 'main'])
            ->whereNotNull('uid')
            ->whereNotNull('sent_at')
            ->where('status', '!=', OrderGeneralStatus::Draft)
            ->whereHas('main');
    }

    protected function generalStatusFilterQuery(): Builder
    {
        return static::getResource()::getEloquentQuery()
            ->whereNotNull('uid')
            ->whereNotNull('sent_at')
            ->where('status', '!=', OrderGeneralStatus::Draft)
            ->whereHas('main');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                SchemaView::make('order.modal')->columnSpan(2)
            ]);
    }

    /**
     * @throws Exception
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        $filters = array_merge(
            $this->getGeneralStatusTableFilters(),
            [Resource::getDateFilter()],
        );

        return $table
            ->recordUrl(null)
            ->columns([
                //                TextColumn::make('uid')
                //                    ->label('Offertenummer')
                //                    ->sortable()
                //                    ->searchable(),
                TextColumn::make('uid')
                    ->label('Offerte')
                    ->searchable()
                    ->extraAttributes(['class' => 'flex flex-col'])
                    ->view('components.number-without-date', ['quote' => true]),


                TextColumn::make('main.uid')
                    ->label('Aanvraagnummer')
                    ->formatStateUsing(fn (?Quote $record) => NavigationLink::main(
                        $record?->main_id,
                        $record?->main?->getUidFormatted(),
                    ))
                    ->openUrlInNewTab(false)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('sent_at')
                    ->date('j M Y')
                    ->label('Datum verzonden')
                    ->hidden(false)
                    ->sortable(['created_at']),
                OrderStatusColumn::make('type_translated')
                    ->sortable(['status'])
                    ->label('Status'),
                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn($record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q->where('name', 'like', "%{$search}%"))),
                TextColumn::make('customer_id')
                    ->label('Klant')
                    ->formatStateUsing(fn (Quote $record): string => $record->getCustomerAddressDisplayName())
                    ->sortable(['customer_id'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'customer',
                        fn (Builder $q) => $q
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"),
                    )),
            ])
            ->recordClasses(fn(Quote $record) => $record?->getIsTest() ? 'actionsJustifyEnd' : null)
            ->deferFilters(false)
            ->filters(
                $filters,
                layout: FiltersLayout::AboveContent
            )
            ->defaultSort('status', 'asc')
            ->recordActions([
            ]);
    }

    protected function updateWidgets(Builder $query): void
    {
        $this->dispatch('update-quotes-widget', [
            'pending_quotes_count' => (clone $query)
                ->where('status', 'pending')
                ->count(),
            'pending_quotes_sum' => (clone $query)
                ->where('status', 'pending')
                ->sum('company_sales_price_total'),
            'completed_quotes_count' => (clone $query)
                ->where('status', 'completed')
                ->count(),
            'completed_quotes_sum' => (clone $query)
                ->where('status', 'completed')
                ->sum('company_sales_price_total'),
            'expired_quotes_count' => (clone $query)
                ->where('status', 'expired')
                ->count(),
            'expired_quotes_sum' => (clone $query)
                ->where('status', 'expired')
                ->sum('company_sales_price_total'),
            'canceled_quotes_count' => (clone $query)
                ->where('status', 'canceled')
                ->count(),
            'canceled_quotes_sum' => (clone $query)
                ->where('status', 'canceled')
                ->sum('company_sales_price_total'),
        ]);
    }

    protected function applySearchToTableQuery(Builder $query): Builder
    {
        $parent = parent::applySearchToTableQuery($query);
        $this->updateWidgets($query);
        return $parent;
    }


    protected function applyColumnSearchToTableQuery(Builder $query): Builder
    {
        $parent = parent::applyColumnSearchToTableQuery($query);
        $this->updateWidgets($query);
        return $parent;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QuotesListBackLinkWidget::class,
            QuotesOverviewWidget::class,
        ];
    }
}
