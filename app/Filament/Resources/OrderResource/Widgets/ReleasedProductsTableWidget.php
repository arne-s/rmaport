<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Widgets\Concerns\ConfiguresClickableProductNameColumn;
use App\Filament\Resources\OrderResource\Widgets\Concerns\DerivesMainOrderStatusAfterProductLineSave;
use App\Filament\Resources\OrderResource\Widgets\Concerns\LocksProductTabWhenMainOrderPhase;
use App\Filament\Support\PurchaseAuthorization;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Enums\ReleaseOrderStatus;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Support\NavigationLink;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReleasedProductsTableWidget extends TableWidget
{
    use ConfiguresClickableProductNameColumn;
    use DerivesMainOrderStatusAfterProductLineSave;
    use LocksProductTabWhenMainOrderPhase;

    protected static ?string $model = OrderProduct::class;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '';

    public static function canView(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        /* @var $this->record Main */
        $query = $this->record?->getReleasedProducts()?->getQuery();

        return $query ?? OrderProduct::query()->whereRaw('0 = 1');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                $this->configureProductNameColumn(
                    TextColumn::make('product.name')
                        ->label('Artikelnaam RD Mobility')
                        ->placeholder('-')
                        ->sortable(),
                ),

                TextColumn::make('product.type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state): string => $state instanceof ProductType
                        ? ($state->getLabel() ?? '-')
                        : (ProductType::tryFrom((string) $state)?->getLabel() ?? '-'))
                    ->sortable(),

                $this->configureClickableProductUidColumn(
                    TextColumn::make('product.uid')
                        ->label('Artikelnummer RD Mobility')
                        ->placeholder('-')
                        ->sortable(),
                ),

                TextColumn::make('qty')
                    ->label('Aantal')
                    ->sortable(),

                SelectColumn::make('status')
                    ->label('Status')
                    ->options(OrderProductStatus::getReleaseOrderLineStatusLabels())
                    ->selectablePlaceholder(false)
                    ->disabled(fn (): bool => ! $this->canInteractWithPurchaseTabProducts())
                    ->updateStateUsing(function (OrderProduct $record, OrderProductStatus|string|null $state): void {
                        abort_unless(PurchaseAuthorization::canManage(), 403);

                        $status = $state instanceof OrderProductStatus ? $state : OrderProductStatus::tryFrom((string) $state);
                        if ($status !== null) {
                            $record->setStatus($status);
                            $record->save();
                            $this->deriveMainOrderStatusAfterProductLineSave();
                            if ($status === OrderProductStatus::Delivered) {
                                $this->dispatch(
                                    'orderProductStatusChangedFromProductsTab',
                                    orderProductId: $record->id,
                                    status: $status->value
                                );
                            }
                            $this->dispatch('refreshProductsTab');
                            $this->dispatch('$refresh');
                        }
                    }),

                TextColumn::make('datum_afgeroepen')
                    ->label('Datum afgeroepen')
                    ->state(function (OrderProduct $record): string {
                        $ro = $record->releaseOrder;
                        if ($ro === null) {
                            return '–';
                        }
                        $sentAt = $ro->sent_at ?? null;

                        return $sentAt !== null ? $sentAt->format('d-m-Y') : '–';
                    }),

                TextColumn::make('afroepnummer')
                    ->label('Afroepverzoek')
                    ->state(function (OrderProduct $record): string {
                        $ro = $record->releaseOrder;
                        if ($ro === null) {
                            return '–';
                        }

                        return $ro->getReferenceNumber() ?? '–';
                    })
                    ->formatStateUsing(function ($state, OrderProduct $record): \Illuminate\Contracts\Support\Htmlable|string {
                        if (! PurchaseAuthorization::canManage() || $record->release_order_id === null) {
                            return (string) $state;
                        }

                        return NavigationLink::releaseOrder($record->release_order_id, (string) $state, '–');
                    })
                    ->openUrlInNewTab(false),

                TextColumn::make('releaseOrder.dealer.name')
                    ->label('Dealer')
                    ->placeholder('-'),
            ])
            ->paginated(false)
            ->striped(false)
            ->headerActions([])
            ->bulkActions([])
            ->searchable(false)
            ->emptyStateHeading('Geen afgeroepen producten')
            ->recordUrl(null)
            ->defaultSort('release_order_id', 'desc')
            ->extraAttributes(['class' => 'orderProductsTable']);
    }
}
