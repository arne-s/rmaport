<?php

namespace App\Filament\Resources;

use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrdersOrdered;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrdersPartiallyConfirmed;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrdersConfirmed;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrdersPartiallyDelivered;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrdersDelivered;
use App\Enums\OrderSubtype;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Filament\Resources\PurchaseOrderResource\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrderResource\Pages\ViewPurchaseOrder;
use App\Filament\Tables\Columns\OrderNumberPageColumn;
use App\Filament\Tables\Columns\PurchaseOrderConfirmationColumn;
use App\Filament\Tables\Columns\PurchaseOrderNumberColumn;
use App\Filament\Support\PurchaseAuthorization;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $breadcrumb = 'Inkoop';
    protected static ?string $modelLabel = 'inkooporder';
    protected static ?string $slug = 'purchase-orders';

    public static function canViewAny(): bool
    {
        return PurchaseAuthorization::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationUrl(?string $panel = null): string
    {
        return static::getUrl('index', panel: $panel);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', '!=', PurchaseOrderStatus::Initial->value);
    }

    public static function table(Table $table): Table
    {
        $purchaseOrderStatuses = PurchaseOrderStatus::visibleStatuses();

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Datum ingekocht')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(function ($state) {
                        $fullDate = Carbon::parse($state);
                        $date = $fullDate->translatedFormat('d-m-Y');
                        return new HtmlString('<div class="numberPlusDate noBorder">'. $date . '</div>');
                    })
                    ->disabledClick(),

                PurchaseOrderNumberColumn::make('reference_number')
                    ->label('Inkooporder #')
                    ->linkOnly()
                    ->viewData(['displayDate' => false])
                    ->searchable()
                    ->sortable(),

                TextColumn::make('days_till_confirmation')
                    ->label('Ouderdom')
                    ->formatStateUsing(function ($state, PurchaseOrder $record) {
                        if($record->days_till_confirmation === 1) {
                            $dagen = ' dag';
                        } else {
                            $dagen = ' dagen';
                        }

                        // Order is late if it isn't confirmed after 4 business days
                        if ($record->is_late) {
                            return new HtmlString('<span class="purchaseOrderAgeNotice">' . $record->days_till_confirmation . ' dagen</span>');
                        }
                        return $record->days_till_confirmation . $dagen;
                    })
                    ->disabledClick(),

                TextColumn::make('latestExpectedDeliveryDate')
                    ->label('Verwachte levering')
                    ->date('\W\e\e\k W, Y')
                    ->extraAttributes(function (PurchaseOrder $record) {
                        $date = $record->latestExpectedDeliveryDate;
                        return $record->status !== PurchaseOrderStatus::Delivered && ($date && ($date->isPast() || $date->isCurrentWeek()))
                            ? ['style' => 'color: red; font-weight: 700;']
                            : [];
                    })
                    ->disabledClick(),

                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (PurchaseOrderType $state) => $state->getLabel())
                    ->sortable()
                    ->searchable()
                    ->disabledClick(),

                SelectColumn::make('status')
                    ->options($purchaseOrderStatuses)
                    ->selectablePlaceholder(false)
                    ->extraAttributes(['style' => 'width: max-content;'])
                    ->disabled(),

                PurchaseOrderConfirmationColumn::make('latestConfirmation.pdf_path')
                    ->label('Bevestiging (PDF)')
                    ->viewData(['displayDate' => false])
                    ->disabledClick(),

                OrderNumberPageColumn::make('order.uid')
                    ->label('Aanvraagnummer')
                    ->linkOnly()
                    ->viewData(['displayDate' => false])
                    ->empty(fn (PurchaseOrder $record) => $record->getType() === PurchaseOrderType::Stock)
                    ->searchable(['uid', 'rev'])
                    ->sortable(['uid', 'rev']),

                TextColumn::make('main_subtype')
                    ->label('Type aanvraag')
                    ->state(fn (PurchaseOrder $record): string => $record->main?->getSubtype()?->getLabel() ?? '-')
                    ->disabledClick(),

                TextColumn::make('supplier.name')
                    ->label('Leverancier')
                    ->sortable()
                    ->searchable()
                    ->disabledClick(),
            ])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                self::getSupplierFilter(relationshipColumn: 'supplier'),
                self::getPurchaseOrderStatusFilter($purchaseOrderStatuses),
                self::getPurchaseOrderTypeFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->defaultSort('id', 'desc')
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
            'ordered' => ListPurchaseOrdersOrdered::route('/ordered'),
            'partially-confirmed' => ListPurchaseOrdersPartiallyConfirmed::route('/partially-confirmed'),
            'confirmed' => ListPurchaseOrdersConfirmed::route('/confirmed'),
            'partially-delivered' => ListPurchaseOrdersPartiallyDelivered::route('/partially-delivered'),
            'delivered' => ListPurchaseOrdersDelivered::route('/delivered'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }
}
