<?php

namespace App\Filament\Resources;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Filament\Resources\ProductionResource\Pages\ListProduction;
use App\Filament\Resources\ProductionResource\Pages\ListProductionAssembled;
use App\Filament\Resources\ProductionResource\Pages\ListProductionDelivered;
use App\Filament\Resources\ProductionResource\Pages\ListProductionFitting;
use App\Filament\Resources\ProductionResource\Pages\ListProductionOrdered;
use App\Filament\Resources\ProductionResource\Pages\ListProductionPurchased;
use App\Filament\Resources\ProductionResource\Pages\ListProductionQuote;
use App\Filament\Actions\CreateMainAction;
use App\Filament\Support\SalesAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use App\Models\Order\Main;
use App\Support\NavigationLink;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductionResource extends Resource
{
    protected static ?string $model = Main::class;

    protected static ?string $breadcrumb = 'Proces';

    protected static ?string $modelLabel = 'Proces';

    protected static ?string $pluralLabel = 'verkooporders';

    protected static ?string $slug = 'production';

    public static function canViewAny(): bool
    {
        return SalesAuthorization::canManage();
    }

    public static function canView(Model $record): bool
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
            ->whereNotIn('order_status', [OrderGeneralStatus::Initial->value]);
    }

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    /**
     * Table toolbar actions (Filament renders these top-right above the grid).
     *
     * @return array<int, Action|ActionGroup>
     */
    public static function productionTableHeaderActions(): array
    {
        if (! CreateMainAction::canCreate()) {
            return [];
        }

        return [
            CreateMainAction::make(),
        ];
    }

    public static function aanvraagNummerColumn(string $label = 'Aanvraagnummer'): TextColumn
    {
        return TextColumn::make('uid')
            ->label($label)
            ->formatStateUsing(fn (Main $record): \Illuminate\Contracts\Support\Htmlable|string => NavigationLink::main(
                $record->getId(),
                $record->getUidFormatted() ?: $record->getUid() ?: '-',
            ))
            ->searchable(['uid', 'rev'])
            ->sortable(['uid', 'rev']);
    }

    public static function table(Table $table): Table
    {
        $orderStatuses = OrderStatus::labels();

        return $table
            ->headerActions(self::productionTableHeaderActions())
            ->columns([
                self::aanvraagNummerColumn(),


                TextColumn::make('subtype')
                    ->label('Type')
                    ->sortable(['subtype'])
                    ->searchable()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof OrderSubtype
                        ? ($state->getLabel() ?? $state->value)
                        : '-'),

                TextColumn::make('order_status')
                    ->label('Aanvraag status')
                    ->formatStateUsing(function (mixed $state): string {
                        $status = $state instanceof OrderStatus
                            ? $state
                            : OrderStatus::tryFrom((string) $state);

                        if ($status === null) {
                            return (string) $state;
                        }

                        return OrderStatus::formatWithMainIndexAndSubLabel($status);
                    })
                    ->sortable(['order_status'])
                    ->searchable(false),


                TextColumn::make('customer_id')
                    ->label('Klant')
                    ->formatStateUsing(fn (Main $record): string => $record->getCustomerAddressDisplayName() ?? '')
                    ->url(fn (Main $record): ?string => $record->customer_id
                        ? route('filament.app.resources.customers.edit', ['record' => $record->customer_id])
                        : null)
                    ->color('primary')
                    ->sortable(['customer_id'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('customer', function (Builder $q) use ($search): Builder {
                            return $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),


                TextColumn::make('reference_internal')
                    ->label('Referentie (intern)')
                    ->placeholder('—')
                    ->sortable(['reference_internal'])
                    ->searchable(['reference_internal']),

                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn (Main $record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('billingCustomer', function (Builder $q) use ($search): Builder {
                            return $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('advisor_id')
                    ->label('Adviseur')
                    ->formatStateUsing(fn (Main $record): string => $record->advisor?->getName() ?? '')
                    ->sortable(['advisor_id'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('advisor', function (Builder $q) use ($search): Builder {
                            return $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),


                TextColumn::make('created_at')
                    ->label('Datum (aangemaakt)')
                    ->date('j M Y')
                    ->sortable(['created_at'])
                    ->searchable(false),
            ])
            ->toolbarActions([])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                Resource::getDealerFilter('orders'),
                Resource::getOrderStatusFilter($orderStatuses),
            ], layout: FiltersLayout::AboveContent)
            ->defaultSort('sent_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProduction::route('/'),
            'fitting' => ListProductionFitting::route('/fitting'),
            'quote' => ListProductionQuote::route('/quote'),
            'purchased' => ListProductionPurchased::route('/purchased'),
            'assembled' => ListProductionAssembled::route('/assembled'),
            'ordered' => ListProductionOrdered::route('/ordered'),
            'delivered' => ListProductionDelivered::route('/delivered'),
        ];
    }
}
