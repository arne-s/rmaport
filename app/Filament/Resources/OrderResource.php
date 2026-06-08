<?php

namespace App\Filament\Resources;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Concerns\GeneralStatusFilter;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\CreateOrder;
use App\Filament\Resources\OrderResource\Pages\CreateOrderFromMain;
use App\Filament\Resources\OrderResource\Pages\ViewOrderRecord;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Support\SalesAuthorization;
use App\Models\Order\Order;
use App\Support\NavigationLink;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

use App\Filament\Tables\Actions\{
    OrderMarginsAction,
};
use App\Filament\Tables\Columns\OrderNumberPageColumn;
use Filament\Tables\Columns\{
    TextColumn
};

class OrderResource extends Resource
{
    use GeneralStatusFilter;

    protected static ?string $model = Order::class;

    protected static ?string $breadcrumb = 'Verkoop';
    protected static ?string $modelLabel = 'verkooporders';
    protected static ?string $slug = 'orders';

    protected static ?string $recordTitleAttribute = 'uid';

    public static function canViewAny(): bool
    {
        return SalesAuthorization::canManage();
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'order')
            ->whereNotNull('uid')
            ->whereNotIn('status', [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft])
            ->whereExists(function (\Illuminate\Database\Query\Builder $query): void {
                $query->selectRaw('1')
                    ->from('orders as main_orders')
                    ->whereColumn('main_orders.order_id', 'orders.id')
                    ->where('main_orders.type', OrderType::Main->value);
            })
            ->with('main');
    }

    public static function table(Table $table): Table
    {
        $orderStatuses = OrderGeneralStatus::labels();

        $filters = array_merge(
            static::tableFiltersForQuery(static::getEloquentQuery()),
            [Resource::getDateFilter()],
        );

        return $table

            ->columns([
                OrderNumberPageColumn::make('uid')
                    ->label('Ordernummer')
                    ->viewData(['displayDate' => false])
                    ->searchable(['uid', 'rev'])
                    ->sortable(['uid', 'rev'])
                    ->disabledClick(),

                TextColumn::make('main.uid')
                    ->label('Aanvraagnummer')
                    ->formatStateUsing(fn (?Order $record) => NavigationLink::main(
                        $record?->main_id,
                        $record?->main?->getUidFormatted(),
                    ))
                    ->openUrlInNewTab(false)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('sent_at')
                    ->label('Datum')
                    ->date('j M Y (H:i)')
                    ->sortable(['sent_at'])
                    // sent_at and created_at columns are hidden in css for some reason???
                    ->extraAttributes(['class' => 'visible'])
                    ->extraHeaderAttributes(['class' => 'visible'])
                    ->extraCellAttributes(['class' => 'visible'])
                    ->disabledClick(),

                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn(Order $record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q->where('name', 'like', "%{$search}%"))),

                TextColumn::make('id')
                    ->label('Klant')
                    ->formatStateUsing(fn($record) => $record?->customer?->getName() ?? '')
                    ->url(fn(Order $record): string => $record?->customer
                        ? route('filament.app.resources.customers.edit', ['record' => $record->customer->id])
                        : '')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(function ($state) use ($orderStatuses) {
                        $statusValue = $state instanceof \BackedEnum ? $state->value : $state;
                        return $orderStatuses[$statusValue] ?? $statusValue;
                    })
                    ->sortable()
                    ->disabledClick(),

                TextColumn::make('sp_margin_summary')
                    ->label(new HtmlString('<span>Marge <span class="taxOverview">(excl. BTW)</span></span>'))
                    ->extraAttributes(fn ($record) => [
                        'class' => preg_match('/\((\d+(?:\.\d+)?)%\)/', $record->sp_margin_summary, $matches) && floatval($matches[1]) < 20 ? 'purchaseOrderAgeNotice' : '',
                    ])
                    ->disabledClick(),
            ])
            ->defaultSort('status', 'desc')
            ->deferFilters(false)
            ->filters($filters, layout: FiltersLayout::AboveContent)
            ->recordActions([
                OrderMarginsAction::make('order_margins'),
            ])
            ->recordAction(null);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with(['main.customer', 'customer', 'billingCustomer']);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'uid',
            'rev',
            'reference',
            'main.uid',
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'billingCustomer.name',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string | Htmlable
    {
        /** @var Order $record */
        $uid = $record->getUid();

        return $uid !== null && $uid !== '' ? "Order {$uid}" : 'Order #' . $record->getKey();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Order $record */
        $details = [];

        if ($record->main?->getUid()) {
            $details['Aanvraag'] = $record->main->getUid();
        }

        if ($record->customer) {
            $details['Klant'] = $record->customer->getName() ?? '';
        }

        return array_filter($details, fn (string $v): bool => $v !== '');
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        /** @var Order $record */
        $mainId = $record->main_id;

        if ($mainId === null) {
            return parent::getGlobalSearchResultUrl($record);
        }

        if (! static::canEdit($record) && ! static::canView($record)) {
            return null;
        }

        return route('filament.app.resources.mains.view', ['record' => $mainId]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'from-main' => CreateOrderFromMain::route('/from-main/{main}'),
            'view' => ViewOrderRecord::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
