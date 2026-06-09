<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Widgets\Concerns\ConfiguresClickableProductNameColumn;
use App\Filament\Resources\OrderResource\Widgets\Concerns\LocksProductTabWhenMainOrderPhase;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Services\InventoryService;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OpenProductsTableWidget extends TableWidget
{
    use ConfiguresClickableProductNameColumn;
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
        /** @var Main|null $main */
        $main = $this->record;

        if ($main === null) {
            return OrderProduct::query()->whereRaw('0 = 1');
        }

        return $main->orderProducts()
            ->where(function (Builder $query): void {
                $query->where('order_products.type', '!=', ProductType::Service->value)
                    ->orWhereNull('order_products.type');
            })
            ->with(['product.stock'])
            ->whereNotIn('status', [
                OrderProductStatus::PickedStock->value,
                OrderProductStatus::PickedReceived->value,
                OrderProductStatus::Canceled->value,
                OrderProductStatus::AddToStock->value,
            ])
            ->getQuery();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->selectable(false)
            ->columns([
                $this->configureProductNameColumn(
                    TextColumn::make('product.name')
                        ->label('Artikelnaam'),
                ),
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state): string => $state instanceof ProductType
                        ? ($state->getLabel() ?? '-')
                        : (ProductType::tryFrom((string) $state)?->getLabel() ?? '-')),

                $this->configureClickableProductUidColumn(
                    TextColumn::make('product.uid')
                        ->label('Artikelnummer')
                        ->placeholder('-'),
                ),
                TextColumn::make('product.supplier.name')
                    ->label('Leverancier')
                    ->placeholder('-')
                    ->sortable('product.supplier.name'),
                TextColumn::make('qty')
                    ->label('Benodigd aantal'),

                SelectColumn::make('status')
                    ->label('Status')
                    ->options(fn (): array => OrderProductStatus::getInitialStatusLabels())
                    ->selectablePlaceholder(false)
                    ->rememberOptions(false)
                    ->disabled(function (?OrderProduct $record): bool {
                        if (! $this->canInteractWithPurchaseTabProducts()) {
                            return true;
                        }
                        if ($record === null) {
                            return true;
                        }
                        if (! static::orderProductLineUsesStock($record)) {
                            return true;
                        }
                        $required = (int) $record->getQty();
                        $available = $record->product?->stock?->getAvailableStock() ?? 0;

                        return $required <= 0 || $available < $required;
                    })
                    ->updateStateUsing(function (OrderProduct $record, OrderProductStatus|string|null $state): void {
                        abort_unless(auth()->user()?->can('manage purchases'), 403);

                        $productName = $record->getValue();
                        $inventory = app(InventoryService::class);

                        if (blank($state) || $state === OrderProductStatus::Initial->value) {
                            if ($record->getStatus() === OrderProductStatus::PickedStock) {
                                try {
                                    $inventory->unpickOrderProductFromStock($record);
                                    Notification::make()
                                        ->title('Picken ongedaan gemaakt.')
                                        ->body("Product {$productName} picken ongedaan gemaakt.")
                                        ->success()
                                        ->send();
                                } catch (\Throwable $e) {
                                    report($e);
                                    Notification::make()
                                        ->title('Fout')
                                        ->body("Kon picken niet ongedaan maken: {$e->getMessage()}")
                                        ->danger()
                                        ->send();
                                    $this->dispatch('$refresh');

                                    return;
                                }
                            }
                            $record->setStatus(OrderProductStatus::Initial);
                            $record->save();
                            $this->dispatch('refreshProductsTab');
                            $this->dispatch('$refresh');

                            return;
                        }

                        $status = $state instanceof OrderProductStatus ? $state : OrderProductStatus::tryFrom((string) $state);
                        if ($status === null) {
                            return;
                        }

                        if ($status === OrderProductStatus::PickedStock) {
                            try {
                                $inventory->pickOrderProductFromStock($record);
                                $record->setStatus($status);
                                $record->save();
                                $this->dispatch('refreshProductsTab');
                                Notification::make()
                                    ->title('Gepickt en afgeboekt')
                                    ->body("Product {$productName} gepickt en afgeboekt van voorraad.")
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                report($e);
                                Notification::make()
                                    ->title('Fout bij afboeken')
                                    ->body("Kon voorraad niet afboeken: {$e->getMessage()}")
                                    ->danger()
                                    ->send();
                            }
                            $this->dispatch('$refresh');

                            return;
                        }

                        $record->setStatus($status);
                        $record->save();
                        $this->record?->refresh();
                        $this->dispatch('refreshProductsTab');

                        if ($status === OrderProductStatus::Delivered) {
                            $this->dispatch(
                                'orderProductStatusChangedFromProductsTab',
                                orderProductId: $record->id,
                                status: $status->value
                            );
                        }
                        $this->dispatch('$refresh');
                    }),

                TextColumn::make('voorraad')
                    ->label('Voorraad')
                    ->state(function (OrderProduct $record): int|string {
                        if ($record->product === null) {
                            return 0;
                        }
                        if (! static::orderProductLineUsesStock($record)) {
                            return 'n.v.t.';
                        }

                        return $record->product->stock?->getAvailableStock() ?? 0;
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('products', 'order_products.product_id', '=', 'products.id')
                            ->leftJoin('product_stock', 'products.id', '=', 'product_stock.product_id')
                            ->orderBy('product_stock.available_stock', $direction);
                    }),

            ])
            ->defaultSort('id', 'asc')
            ->paginated(['all'])
            ->extraAttributes(['class' => 'orderProductsTable'])
            ->emptyStateHeading('Geen Artikelen');
    }

    /**
     * True when the line's product tracks stock (same rule as "Voorraad" not being n.v.t.).
     */
    private static function orderProductLineUsesStock(OrderProduct $record): bool
    {
        $product = $record->product;
        if ($product === null) {
            return false;
        }

        return true;
    }
}
