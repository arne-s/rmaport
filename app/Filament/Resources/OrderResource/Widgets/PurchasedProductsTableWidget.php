<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Widgets\Concerns\ConfiguresClickableProductNameColumn;
use App\Filament\Resources\OrderResource\Widgets\Concerns\DerivesMainOrderStatusAfterProductLineSave;
use App\Filament\Resources\OrderResource\Widgets\Concerns\LocksProductTabWhenMainOrderPhase;
use App\Filament\Support\PurchaseAuthorization;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Models\OrderProduct;
use App\Support\NavigationLink;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PurchasedProductsTableWidget extends TableWidget
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
        $query = $this->record?->getPurchasedProducts()?->getQuery();

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
                    ->options(OrderProductStatus::getMtoLabels())
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

                TextColumn::make('datum_ingekocht')
                    ->label('Datum ingekocht')
                    ->state(function (OrderProduct $record): string {
                        $po = $record->purchaseOrder;
                        if ($po === null) {
                            return '–';
                        }
                        $sentAt = $po->sent_at ?? null;

                        return $sentAt !== null ? $sentAt->format('d-m-Y') : '–';
                    }),

                TextColumn::make('purchaseOrder.reference_number')
                    ->label('Inkooporder')
                    ->sortable()
                    ->formatStateUsing(function (OrderProduct $record): \Illuminate\Contracts\Support\Htmlable|string {
                        $label = $record->purchaseOrder?->reference_number ?? '–';

                        if (! PurchaseAuthorization::canManage() || $record->purchase_order_id === null) {
                            return $label;
                        }

                        return NavigationLink::purchaseOrder($record->purchase_order_id, $label, '–');
                    }),

                TextColumn::make('product.supplier.name')
                    ->label('Leverancier')
                    ->placeholder('-'),
            ])
            ->paginated(false)
            ->striped(false)
            ->headerActions([])
            ->bulkActions([])
            ->searchable(false)
            ->emptyStateHeading('Geen ingekochte producten')
            ->recordUrl(null)
            ->defaultSort('purchase_order_id', 'asc')
            ->extraAttributes(['class' => 'orderProductsTable']);
    }
}
