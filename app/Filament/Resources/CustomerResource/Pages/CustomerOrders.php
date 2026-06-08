<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\Resource;
use App\Filament\Tables\Columns\OrderStatusColumn;
use App\Filament\Tables\Columns\ReportingOrderNumberColumn;
use App\Filament\Widgets\CustomerFormWidget;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerOrders extends ListOrders
{
    protected static string $resource = CustomerResource::class;
    protected static ?string $model = Customer::class;

    protected static ?string $breadcrumb = 'Klanten';
    protected static ?string $modelLabel = 'Klant';
    protected static ?string $title = 'Klant';

    protected ?Model $record = null;

    public function getPluralModelLabel(): string
    {
        return 'Klanten';
    }


    public function mount(): void
    {
        $this->getCustomer();
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CustomerFormWidget::class,
        ];
    }

    protected function getTableQuery(): Builder
    {
        $this->getCustomer();

        return BaseOrder::query()
//            ->whereHas('customer', function ($q) {
//                return $q->where('email', $this->record->email);
            //})
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                // sent_at column won't display for some reason? so workaround
                TextColumn::make('id')
                    ->label('Datum')
                    ->formatStateUsing(fn ($state, $record) => $record->sent_at?->translatedFormat('j M Y (H:i)'))
                    ->searchable(['sent_at'])
                    ->sortable(['sent_at'])
                    ->disabledClick(),

                TextColumn::make('type')
                    ->label('Document type')
                    ->formatStateUsing(fn ($state) => ucfirst(__(sprintf('orders.type.%s', $state))))
                    ->disabledClick(),

                ReportingOrderNumberColumn::make('dynamic.uid')
                    ->label('Nummer')
                    ->viewData(['displayDate' => false])
                    ->searchable(['uid'])
                    ->sortable(['uid'])
                    ->disabledClick(),

                OrderStatusColumn::make('type_translated')
                    ->label('Status')
                    ->disabledClick(),

                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn($record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: fn(Builder $query, string $search): Builder => $query->whereHas('billingCustomer', fn(Builder $q) => $q->where('name', 'like', "%{$search}%")))
                    ->disabledClick(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->deferFilters(false)
            ->filters([
                Resource::getTypeFilter(),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Geen documenten');
    }

    protected function getCustomer()
    {
//        $src = str_contains(request()->path(), 'customers/')
//            ? request()->path()
//            : request()->header('referer');
//
//        $t = explode('/customers/', $src);
//        [$id] = explode('/', $t[1]);

        $this->record = Customer::first();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.app.resources.customers.index') => 'Klanten',
            route('filament.app.resources.customers.index') => 'Klanten',
        ];
    }
}
