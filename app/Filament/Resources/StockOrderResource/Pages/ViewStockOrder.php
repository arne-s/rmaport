<?php

namespace App\Filament\Resources\StockOrderResource\Pages;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\StockOrderResource;
use App\Models\Order\StockOrder;
use App\Models\OrderProduct;
use App\Models\PurchaseOrder;
use App\Services\InventoryService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\On;

/**
 * @property StockOrder $record
 */
class ViewStockOrder extends ViewRecord implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string $resource = StockOrderResource::class;

    protected static ?string $title = 'Inkooporder';

    protected string $view = 'filament.resources.stock-orders.pages.view-stock-order';

    public ?float $invoiceAmount = null;
    public array $priceTotals = [];
    public ?OrderProduct $confirmOrderProductRecord = null;
    public ?string $purchaseOrderStatus = null;
    public ?int $pendingDeliveredOrderProductId = null;
    public bool $pendingStockOrderCancellation = false;

    public function mount(int | string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->purchaseOrderStatus = $this->record->getStatus()?->value;
        $this->hydrate();
    }

    /**
     * @return list<array{value: string, label: string, selectable: bool}>
     */
    public function getPurchaseOrderStatusDropdownOptions(): array
    {
        $current = $this->record->getStatus() ?? PurchaseOrderStatus::Purchased;
        if ($current === PurchaseOrderStatus::Initial) {
            $current = PurchaseOrderStatus::Draft;
        }

        $categoryOrder = array_flip(['Concept', 'Inkopen', 'Op locatie', 'Geannuleerd']);
        $selectableMap = $this->stockOrderSelectableStatuses();
        $cancelledValue = PurchaseOrderStatus::Cancelled->value;
        $raw = [];
        foreach (PurchaseOrderStatus::allWithCategoriesForSelect() as $value => $item) {
            $cat = (string) ($item['category'] ?? '');
            $status = $item['status'] ?? PurchaseOrderStatus::tryFrom($value);
            $optionLabel = $item['label'] ?? $status?->getLabel() ?? $value;
            $next = $this->nextPurchaseOrderStatus($current);
            $selectable = $value === $current->value
                || ($value === $cancelledValue && $current === PurchaseOrderStatus::Purchased)
                || (($selectableMap[$value] ?? true) && $next !== null && $value === $next->value);

            $raw[] = [
                'value' => $value,
                'label' => $optionLabel,
                'selectable' => $selectable,
                'cat_order' => $categoryOrder[$cat] ?? 99,
            ];
        }

        usort($raw, fn (array $a, array $b): int => $a['cat_order'] <=> $b['cat_order']);

        return array_map(fn (array $row): array => [
            'value' => $row['value'],
            'label' => $row['label'],
            'selectable' => $row['selectable'],
        ], $raw);
    }

    public function updatedPurchaseOrderStatus(): void
    {
        $this->record->refresh();
        $dbValue = $this->record->getStatus()?->value;
        if ($dbValue === $this->purchaseOrderStatus || $this->purchaseOrderStatus === null) {
            return;
        }

        $incoming = (string) $this->purchaseOrderStatus;
        $selectableMap = $this->stockOrderSelectableStatuses();
        if (! ($selectableMap[$incoming] ?? true) && $incoming !== $dbValue) {
            $this->purchaseOrderStatus = $dbValue;

            return;
        }

        $newStatus = PurchaseOrderStatus::tryFrom($incoming);
        if ($newStatus === null) {
            $this->purchaseOrderStatus = $dbValue;

            return;
        }

        if ($newStatus !== PurchaseOrderStatus::Cancelled
            && ! $this->canMoveToNextPurchaseOrderStatus($this->record->getStatus(), $newStatus)) {
            $this->purchaseOrderStatus = $dbValue;

            return;
        }

        if ($newStatus === PurchaseOrderStatus::Cancelled) {
            if ($this->record->getStatus() !== PurchaseOrderStatus::Purchased) {
                $this->purchaseOrderStatus = $dbValue;

                return;
            }

            $this->pendingStockOrderCancellation = true;
            $this->dispatch('open-modal', id: 'stock_order_cancel_confirm');
            $this->purchaseOrderStatus = $dbValue;

            return;
        }

        $this->record->setStatus($newStatus);
        $this->record->save();

        if ($newStatus === PurchaseOrderStatus::Confirmed) {
            foreach (OrderProduct::query()->where('order_id', $this->record->getId())->get() as $orderProduct) {
                $orderProduct->setStatus(OrderProductStatus::Confirmed);
                $orderProduct->save();
            }
        }

        if ($newStatus === PurchaseOrderStatus::Delivered) {
            $this->dispatch('open-modal', id: 'mts_purchase_order_delivered_confirm');

            return;
        }

        $this->syncPurchaseOrderStatusFromRecord();

        Notification::make()
            ->title('Status bijgewerkt.')
            ->success()
            ->send();
    }

    public function getBackToUrl(): string
    {
        return route('filament.app.resources.purchase-orders.index');
    }

    public function getBackToTitle(): string
    {
        return 'Inkooporder-overzicht';
    }

    public function hydrate(): void
    {
        if (! empty($this->record)) {
            $purchaseOrders = $this->record->purchaseOrders()->with('purchaseOrderInvoices')->get();

            $sum = $purchaseOrders
                ->flatMap->purchaseOrderInvoices
                ->sum(fn ($inv) => abs($inv->amount));

            $this->invoiceAmount = $sum > 0 ? (float) $sum : null;

            $totals = ['companyPurchasePrice' => 0.0, 'companySalesPrice' => 0.0];
            foreach ($purchaseOrders as $po) {
                $poTotals = $po->priceTotals;
                $totals['companyPurchasePrice'] += $poTotals['companyPurchasePrice'] ?? 0;
                $totals['companySalesPrice'] += $poTotals['companySalesPrice'] ?? 0;
            }
            $this->priceTotals = $totals;
        }
    }

    public function getCompanyMarginSummary(): string
    {
        $purchase = $this->priceTotals['companyPurchasePrice'] ?? 0;
        $sales = $this->priceTotals['companySalesPrice'] ?? 0;
        $margin = $sales - $purchase;
        $add = '';

        if ($purchase > 0) {
            $percentage = ($margin / $purchase) * 100;
            $add = ' <span class="percentage">(' . round($percentage, 1) . '%)</span>';
        }

        return '€' . number_format((float) $margin, 2, ',', '.') . $add;
    }

    public function getOrderPurchasePriceMarginSummary(): ?string
    {
        if ($this->invoiceAmount === null) {
            return null;
        }

        $paymentAmount = (float) $this->invoiceAmount;
        $companySalesPrice = $this->priceTotals['companySalesPrice'] ?? 0;

        $margin = $companySalesPrice - $paymentAmount;
        $add = '';

        if ($paymentAmount > 0) {
            $percentage = ($margin / $paymentAmount) * 100;
            $add = ' <span class="percentage">(' . round($percentage, 1) . '%)</span>';
        }

        return '€' . number_format((float) $margin, 2, ',', '.') . $add;
    }

    public function getDeltaPurchaseMargin(): ?string
    {
        $erpPurchase = (float) ($this->priceTotals['companyPurchasePrice'] ?? 0);

        if ($this->invoiceAmount === null || $erpPurchase === 0.0) {
            return null;
        }

        $delta = round($erpPurchase - (float) $this->invoiceAmount, 2);
        $percentage = round(($delta / $erpPurchase) * 100, 1);

        if ($delta > 0) {
            return '<span style="color: var(--success-600, #16a34a)">-€' . number_format(abs($delta), 2, ',', '.') . ' (' . abs($percentage) . '%)</span>';
        }

        if ($delta < 0) {
            return '<span style="color: var(--danger-600, #dc2626)">+€' . number_format(abs($delta), 2, ',', '.') . ' (' . abs($percentage) . '%)</span>';
        }

        return '€' . number_format(abs($delta), 2, ',', '.');
    }

    public function syncPurchaseOrderStatusFromRecord(): void
    {
        $this->record->refresh();
        $this->purchaseOrderStatus = $this->record->getStatus()?->value;
        $this->hydrate();
        $this->refreshPurchaseOrderProductsTable();
    }

    protected function syncStockOrderStatusFromOrderProductLines(): void
    {
        if ($this->record->getStatus() === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $lineStatuses = $this->record
            ->orderProducts()
            ->get()
            ->map(static fn (OrderProduct $line): ?OrderProductStatus => $line->getStatus());

        $target = PurchaseOrder::derivePurchaseOrderStatusFromOrderProductLineStatuses($lineStatuses);
        if ($this->record->getStatus() !== $target) {
            $this->record->setStatus($target);
            $this->record->save();
        }
    }

    protected function refreshPurchaseOrderProductsTable(): void
    {
        $this->flushCachedTableRecords();
        $this->dispatch('$refresh');
    }

    public function getOrderStatusTimeline(): \Illuminate\Support\Collection
    {
        return collect(PurchaseOrderStatus::allWithCategoriesForSelect())
            ->map(fn (array $item): array => [
                ...$item,
                'date' => null,
            ]);
    }

    public function getProductsTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->record->orderProducts()->getQuery();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getProductsTableQuery())
            ->columns([
                TextColumn::make('product.name')
                    ->label('Artikelnaam')
                    ->sortable(),
                TextColumn::make('product.uid')
                    ->label('Artikelcode')
                    ->sortable(),
                TextColumn::make('qty')
                    ->label('Aantal')
                    ->sortable(),
                SelectColumn::make('status')
                    ->label('Status')
                    ->options(function (): array {
                        $options = OrderProductStatus::getStockOrderLabels();
                        // On stock orders we do not show any "Gepickt" statuses in the dropdown.
                        unset($options[OrderProductStatus::PickedStock->value]);
                        unset($options[OrderProductStatus::PickedReceived->value]);

                        return $options;
                    })
                    ->inline()
                    ->selectablePlaceholder(false)
                    ->disableOptionWhen(function (string $value, OrderProduct $record): bool {
                        $current = $record->getStatus();
                        if ($current === null) {
                            return true;
                        }

                        if ($value === $current->value) {
                            return false;
                        }

                        $next = $this->nextPurchaseOrderProductStatus($current);

                        return $next === null || $value !== $next->value;
                    })
                    ->disabled(fn (OrderProduct $record): bool => $record->getStatus() === OrderProductStatus::PickedReceived
                        || $this->record->getStatus() === PurchaseOrderStatus::Cancelled)
                    ->tooltip(function (SelectColumn $column): ?string {
                        $record = $column->getRecord();
                        if (! $record instanceof OrderProduct) {
                            return null;
                        }

                        if ($this->record->getStatus() === PurchaseOrderStatus::Cancelled) {
                            return 'Status kan niet worden gewijzigd voor geannuleerde inkooporders.';
                        }

                        return $record->getStatus() === OrderProductStatus::PickedReceived
                            ? 'Status gepickt kan niet worden gewijzigd.'
                            : null;
                    })
                    ->updateStateUsing(function (OrderProduct $record, OrderProductStatus|string $state): void {
                        $toStatus = $state instanceof OrderProductStatus
                            ? $state
                            : OrderProductStatus::tryFrom((string) $state);

                        if ($toStatus === null || $record->getStatus() === OrderProductStatus::PickedReceived) {
                            return;
                        }

                        if (! $this->canMoveToNextOrderProductStatus($record->getStatus(), $toStatus)) {
                            return;
                        }

                        if ($toStatus === OrderProductStatus::Delivered) {
                            $this->pendingDeliveredOrderProductId = $record->getId();
                            $this->dispatch('open-modal', id: 'mts_order_product_delivered_confirm');

                            return;
                        }

                        $record->setStatus($toStatus);
                        $record->save();
                        $this->syncStockOrderStatusFromOrderProductLines();
                        $this->syncPurchaseOrderStatusFromRecord();
                    })
                    ->sortable(),
                TextColumn::make('voorraad')
                    ->label('Voorraad')
                    ->state(fn (OrderProduct $record): int => $record->product?->stock?->getPhysicalStock() ?? 0),
                TextColumn::make('supplier.name')
                    ->label('Leverancier')
                    ->sortable(),
                TextColumn::make('datum_ingekocht')
                    ->label('Datum ingekocht')
                    ->state(fn (): ?string => $this->record->created_at?->format('d-m-Y') ?? '-'),
                TextColumn::make('inkoopnummer')
                    ->label('Inkoopnummer')
                    ->state(fn (): string => $this->record->getUidFormatted() ?: '-'),
            ])
            ->paginated(['all'])
            ->extraAttributes(['class' => 'orderProductsTable'])
            ->emptyStateHeading('Geen artikelen');
    }

    #[On('confirmMtsPurchaseOrderDelivered')]
    public function confirmPoDeliveredFromModal(bool $confirm): void
    {
        if (! $confirm) {
            $this->confirmOrderProductRecord = null;
            $this->dispatch('close-modal', id: 'mts_purchase_order_delivered_confirm');
            $this->syncPurchaseOrderStatusFromRecord();
            $this->refreshPurchaseOrderProductsTable();

            return;
        }

        foreach ($this->record->orderProducts as $orderProduct) {
            if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToStock) {
                app(InventoryService::class)->deliverOrderProduct($orderProduct);
            }
        }

        $this->record->setStatus(PurchaseOrderStatus::Delivered);
        $this->record->save();

        Notification::make()
            ->title('De inkooporderstatus is bijgewerkt naar Geleverd en de voorraad is opgeboekt.')
            ->success()
            ->send();

        $this->confirmOrderProductRecord = null;
        $this->dispatch('close-modal', id: 'mts_purchase_order_delivered_confirm');
        $this->syncPurchaseOrderStatusFromRecord();
        $this->refreshPurchaseOrderProductsTable();
    }

    #[On('confirmMtsOrderProductDelivered')]
    public function confirmOrderProductDeliveredFromModal(bool $confirm): void
    {
        if (! $confirm) {
            $this->pendingDeliveredOrderProductId = null;
            $this->dispatch('close-modal', id: 'mts_order_product_delivered_confirm');
            $this->refreshPurchaseOrderProductsTable();

            return;
        }

        $orderProduct = OrderProduct::query()
            ->where('order_id', $this->record->getId())
            ->find($this->pendingDeliveredOrderProductId);

        if (! $orderProduct instanceof OrderProduct) {
            $this->pendingDeliveredOrderProductId = null;
            $this->dispatch('close-modal', id: 'mts_order_product_delivered_confirm');

            return;
        }

        $orderProduct->setStatus(OrderProductStatus::Delivered);
        $orderProduct->save();

        if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToStock) {
            app(InventoryService::class)->deliverOrderProduct($orderProduct);
        }

        $this->pendingDeliveredOrderProductId = null;
        $this->dispatch('close-modal', id: 'mts_order_product_delivered_confirm');
        $this->syncStockOrderStatusFromOrderProductLines();
        $this->syncPurchaseOrderStatusFromRecord();
        $this->refreshPurchaseOrderProductsTable();

        Notification::make()
            ->title('Product bijgewerkt naar Geleverd en voorraad opgeboekt.')
            ->success()
            ->send();
    }

    #[On('confirmStockOrderCancel')]
    public function confirmStockOrderCancel(bool $confirm): void
    {
        if (! $confirm) {
            $this->pendingStockOrderCancellation = false;
            $this->dispatch('close-modal', id: 'stock_order_cancel_confirm');
            $this->syncPurchaseOrderStatusFromRecord();

            return;
        }

        if ($this->record->getStatus() !== PurchaseOrderStatus::Purchased) {
            $this->pendingStockOrderCancellation = false;
            $this->dispatch('close-modal', id: 'stock_order_cancel_confirm');
            $this->syncPurchaseOrderStatusFromRecord();

            return;
        }

        $this->record->setStatus(PurchaseOrderStatus::Cancelled);
        $this->record->save();

        $this->pendingStockOrderCancellation = false;
        $this->dispatch('close-modal', id: 'stock_order_cancel_confirm');
        $this->syncPurchaseOrderStatusFromRecord();

        Notification::make()
            ->title('Status bijgewerkt.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, bool>
     */
    protected function stockOrderSelectableStatuses(): array
    {
        $map = PurchaseOrderStatus::selectableStatuses();
        $map[PurchaseOrderStatus::Cancelled->value] = true;

        return $map;
    }

    protected function canMoveToNextPurchaseOrderStatus(?PurchaseOrderStatus $from, PurchaseOrderStatus $to): bool
    {
        return $this->nextPurchaseOrderStatus($from) === $to;
    }

    protected function canMoveToNextOrderProductStatus(?OrderProductStatus $from, OrderProductStatus $to): bool
    {
        if ($from === null) {
            return true;
        }

        $next = $this->nextPurchaseOrderProductStatus($from);

        return $next !== null && $next === $to;
    }

    protected function nextPurchaseOrderStatus(?PurchaseOrderStatus $from): ?PurchaseOrderStatus
    {
        if ($from === PurchaseOrderStatus::Draft || $from === PurchaseOrderStatus::Initial) {
            return PurchaseOrderStatus::Purchased;
        }

        if ($from === PurchaseOrderStatus::PartiallyConfirmed) {
            return PurchaseOrderStatus::Confirmed;
        }

        if ($from === PurchaseOrderStatus::Purchased) {
            return PurchaseOrderStatus::Confirmed;
        }

        if ($from === PurchaseOrderStatus::Confirmed || $from === PurchaseOrderStatus::PartiallyDelivered) {
            return PurchaseOrderStatus::Delivered;
        }

        return null;
    }

    protected function nextPurchaseOrderProductStatus(OrderProductStatus $from): ?OrderProductStatus
    {
        return match ($from) {
            OrderProductStatus::Purchased => OrderProductStatus::Confirmed,
            OrderProductStatus::Confirmed => OrderProductStatus::Delivered,
            OrderProductStatus::Delivered => OrderProductStatus::PickedReceived,
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

