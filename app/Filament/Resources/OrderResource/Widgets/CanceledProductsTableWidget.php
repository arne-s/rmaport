<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Widgets\Concerns\ConfiguresClickableProductNameColumn;
use App\Filament\Resources\OrderResource\Widgets\Concerns\DerivesMainOrderStatusAfterProductLineSave;
use App\Filament\Resources\OrderResource\Widgets\Concerns\LocksProductTabWhenMainOrderPhase;
use App\Filament\Support\PurchaseAuthorization;
use App\Filament\Support\RecordLockNavigation;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\OrderProduct;
use App\Services\InventoryService;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CanceledProductsTableWidget extends TableWidget
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
        $query = $this->record?->getCanceledProducts()?->getQuery();

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

                TextColumn::make('additional.order_rev')
                    ->label('Order-revisie')
                    ->state(fn (OrderProduct $record): string => (string) ($record->getAdditionalOrderRev() ?? '-'))
                    ->extraCellAttributes(['class' => 'fi-ta-cell--nav-link'])
                    ->action(function (OrderProduct $record): void {
                        $orderId = $record->getAdditionalSourceOrderId();

                        if ($orderId === null) {
                            return;
                        }

                        $order = Order::withoutGlobalScopes()->find($orderId);

                        if ($order === null) {
                            return;
                        }

                        RecordLockNavigation::attemptRedirectToEdit(
                            $this,
                            $order,
                            route('filament.app.resources.orders.edit', ['record' => $order->getId()]),
                        );
                    }),

                TextColumn::make('created_at')
                    ->label('Toegevoegd')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),

                TextColumn::make('additional.updated_by')
                    ->label('Door')
                    ->state(fn (OrderProduct $record): string => $record->getAdditionalUpdatedBy() ?? '-'),

                SelectColumn::make('status')
                    ->label('Status')
                    ->options(function (?OrderProduct $record): array {
                        if ($record !== null && $record->getStatus() === OrderProductStatus::AddToStock) {
                            return OrderProductStatus::getCanceledTabBookedToStockStatusLabels();
                        }

                        return OrderProductStatus::getCanceledTabStatusLabelsForRecord($record);
                    })
                    ->rememberOptions(false)
                    ->selectablePlaceholder(false)
                    ->disabled(function (?OrderProduct $record): bool {
                        if (! $this->canInteractWithPurchaseTabProducts()) {
                            return true;
                        }

                        return $record !== null && $record->getStatus() === OrderProductStatus::AddToStock;
                    })
                    ->updateStateUsing(function (OrderProduct $record, OrderProductStatus|string|null $state): void {
                        abort_unless(PurchaseAuthorization::canManage(), 403);

                        if ($record->getStatus() === OrderProductStatus::AddToStock) {
                            return;
                        }

                        $status = $state instanceof OrderProductStatus ? $state : OrderProductStatus::tryFrom((string) $state);
                        if ($status === null) {
                            return;
                        }

                        $productName = $record->getValue();

                        if ($status === OrderProductStatus::AddToStock) {
                            if (! $record->hasBeenInPurchaseProcess()) {
                                return;
                            }

                            try {
                                app(InventoryService::class)->addCanceledProductToStock($record);
                                $record->setStatus($status);
                                $record->save();
                                $this->deriveMainOrderStatusAfterProductLineSave();
                                $this->dispatch('refreshProductsTab');
                                $this->dispatch('$refresh');
                                Notification::make()
                                    ->title('Voorraad opgeboekt')
                                    ->body("{$record->getQty()}× {$productName} toegevoegd aan de voorraad.")
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                report($e);
                                Notification::make()
                                    ->title('Fout bij opboeken voorraad')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                                $this->dispatch('$refresh');
                            }

                            return;
                        }

                        $record->setStatus($status);
                        $record->save();
                        $this->deriveMainOrderStatusAfterProductLineSave();
                        $this->dispatch('refreshProductsTab');
                        $this->dispatch('$refresh');
                    }),
            ])
            ->paginated(false)
            ->striped(false)
            ->headerActions([])
            ->bulkActions([])
            ->searchable(false)
            ->emptyStateHeading('Geen geannuleerde producten')
            ->recordUrl(null)
            ->extraAttributes(['class' => 'orderProductsTable']);
    }
}
