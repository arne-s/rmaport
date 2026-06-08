<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\Actions\UploadDocumentAction;
use App\Models\OrderProduct;
use Filament\Actions\Action;
use App\Models\PurchaseOrder;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
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
 * @property PurchaseOrder $record
 */
class ViewPurchaseOrder extends ViewRecord implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'Inkooporder';

    protected string $view = 'filament.resources.purchase-orders.pages.view-purchase-order';

    public ?float $invoiceAmount = null;
    public array $priceTotals = [];
    public ?OrderProduct $confirmOrderProductRecord = null;

    /** Delivery week: stored in `additional.delivery_week`. */
    public string $purchaseOrderDeliveryWeek = '';

    /** Volledige PO op Geleverd zetten (header-dropdown); los van per-product modal. */
    public ?PurchaseOrder $confirmPurchaseOrderForStatus = null;

    public ?string $purchaseOrderStatus = null;


    /** When set, back link goes to this order (main) view with purchase tab. */
    public ?int $returnToOrderId = null;

    public function mount(int | string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record);

        $returnToOrder = request()->query('return_to_order');
        $this->returnToOrderId = $returnToOrder !== null && $returnToOrder !== '' ? (int) $returnToOrder : null;

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->purchaseOrderStatus = $this->record->getStatus()?->value;

        $savedDeliveryWeek = $this->record->getAdditional()['delivery_week'] ?? null;
        if ($savedDeliveryWeek !== null && $savedDeliveryWeek !== '') {
            $this->purchaseOrderDeliveryWeek = (string) $savedDeliveryWeek;
        } else {
            $product = $this->record->orderProducts()->with('product')->first()?->product;
            $avgLeadTime = $product?->getAverageLeadTimeHuman();
            $this->purchaseOrderDeliveryWeek = ($avgLeadTime !== null && $avgLeadTime !== '-') ? $avgLeadTime : '';
        }

        $this->hydrate();
    }

    /**
     * @return list<array{value: string, label: string, selectable: bool}>
     */
    public function getPurchaseOrderStatusDropdownOptions(): array
    {
        $current = $this->record->getStatus() ?? PurchaseOrderStatus::Purchased;
        if ($current === PurchaseOrderStatus::Initial) {
            $current = PurchaseOrderStatus::Purchased;
        }

        $categoryOrder = array_flip(['Inkopen', 'Op locatie', 'Geannuleerd']);
        $selectableMap = PurchaseOrderStatus::selectableStatuses();
        $raw = [];
        foreach (PurchaseOrderStatus::allWithCategoriesForSelect() as $value => $item) {
            $cat = (string) ($item['category'] ?? '');
            $status = $item['status'] ?? PurchaseOrderStatus::tryFrom($value);
            $optionLabel = $item['label'] ?? $status?->getLabel() ?? $value;
            $next = $this->nextPurchaseOrderStatus($current);
            $selectable = $value === $current->value
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
        $selectableMap = PurchaseOrderStatus::selectableStatuses();
        if (! ($selectableMap[$incoming] ?? true) && $incoming !== $dbValue) {
            $this->purchaseOrderStatus = $dbValue;

            return;
        }

        $newStatus = PurchaseOrderStatus::tryFrom($incoming);
        if ($newStatus === null) {
            $this->purchaseOrderStatus = $dbValue;

            return;
        }
        if (! $this->canMoveToNextPurchaseOrderStatus($this->record->getStatus(), $newStatus)) {
            $this->purchaseOrderStatus = $dbValue;

            return;
        }

        if ($newStatus === PurchaseOrderStatus::Delivered) {
            $this->record->loadMissing('orderProducts');
            if ($this->record->getType() !== PurchaseOrderType::Stock && $this->record->orderProductsAreAllPickedReceived()) {
                $this->record->setStatus(PurchaseOrderStatus::Delivered);
                $this->record->save();
                $this->record->refresh();
                $this->purchaseOrderStatus = $this->record->getStatus()?->value;
                $this->hydrate();
                $this->refreshPurchaseOrderProductsTable();
                Notification::make()
                    ->title('Status bijgewerkt.')
                    ->success()
                    ->send();

                return;
            }

            $this->record->setStatus(PurchaseOrderStatus::Delivered);
            $this->record->save();
            $this->confirmPurchaseOrderForStatus = $this->record;
            $this->purchaseOrderStatus = PurchaseOrderStatus::Delivered->value;
            if ($this->record->getType() === PurchaseOrderType::Stock) {
                $this->dispatch('open-modal', id: 'mts_purchase_order_delivered_confirm');
            } else {
                $this->dispatch('open-modal', id: 'mto_purchase_order_delivered_confirm');
            }

            return;
        }

        $this->record->setStatus($newStatus);
        $this->record->save();

        $this->record->refresh();
        $this->purchaseOrderStatus = $this->record->getStatus()?->value;
        $this->hydrate();
        $this->refreshPurchaseOrderProductsTable();

        Notification::make()
            ->title('Status bijgewerkt.')
            ->success()
            ->send();
    }

    public function getBackToUrl(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        if ($orderId !== null) {
            return route('filament.app.resources.mains.view', ['record' => $orderId]) . '?tab=purchase';
        }
        return route('filament.app.resources.purchase-orders.index');
    }

    public function getBackToTitle(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        return $orderId !== null ? 'Aanvraag' : 'Inkooporder-overzicht';
    }

    public function hydrate()
    {
        if (!empty($this->record)) {
            $sum = $this->record->purchaseOrderInvoices()->sum(DB::raw('ABS(amount)'));
            $this->invoiceAmount = $sum > 0 ? (float) $sum : null;

            $this->priceTotals = $this->record->priceTotals;
        }
    }

    public function savePurchaseOrderDetails(): void
    {
        $additional = $this->record->getAdditional() ?? [];

        $deliveryWeek = trim($this->purchaseOrderDeliveryWeek);
        if ($deliveryWeek === '') {
            unset($additional['delivery_week']);
        } else {
            $additional['delivery_week'] = $deliveryWeek;
        }

        $this->record->setAdditional($additional !== [] ? $additional : null);
        $this->record->save();
        $this->record->refresh();
        $this->purchaseOrderDeliveryWeek = (string) ($this->record->getAdditional()['delivery_week'] ?? '');

        Notification::make()
            ->title('Opgeslagen.')
            ->success()
            ->send();
    }

    /**
     * Sync header status dropdown with DB (e.g. PO derived from order lines) without a full page refresh.
     */
    public function syncPurchaseOrderStatusFromRecord(): void
    {
        $this->record->refresh();
        $this->purchaseOrderStatus = $this->record->getStatus()?->value;
        $this->hydrate();
        $this->refreshPurchaseOrderProductsTable();
    }

    /**
     * Filament SelectColumn embeds disabled state in the DOM; flush cache + refresh so picks like
     * PickedReceived re-render as disabled without a full page reload.
     */
    protected function refreshPurchaseOrderProductsTable(): void
    {
        $this->flushCachedTableRecords();
        $this->dispatch('$refresh');
    }

    public function getCompanyMarginSummary(): string
    {
        $margin = $this->priceTotals['companySalesPrice'] - $this->priceTotals['companyPurchasePrice'];
        $add = '';

        if ($this->priceTotals['companyPurchasePrice'] > 0) {
            $percentage = ($margin / $this->priceTotals['companyPurchasePrice']) * 100;
            $add = ' <span class="percentage">(' . round($percentage, 1) . '%)</span>';
        }

        return '€' . number_format((float)$margin, 2, ',', '.') . $add;
    }

    public function getOrderPurchasePriceMarginSummary(): ?string
    {
        if ($this->invoiceAmount === null) return null;

        $paymentAmount = floatval($this->invoiceAmount ?? 0);
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


    public function getOrderStatusTimeline()
    {
        return collect(PurchaseOrderStatus::allWithCategoriesForSelect())
            ->map(function ($item) {
                $item['date'] = $this->record->statusChanges()
                    ->where('to_status', $item['status']->value)
                    ->latest()
                    ->value('created_at') ?? null;
                return $item;
            });
    }

    public function uploadDocumentAction(): Action
    {
        return UploadDocumentAction::make('uploadDocument')
            ->record($this->record);
    }


    public function getProductsTableQuery()
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
                    ->options(OrderProductStatus::getStockOrderLabels())
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
                    ->disabled(fn (OrderProduct $record): bool => $record->getStatus() === OrderProductStatus::PickedReceived)
                    ->tooltip(function (SelectColumn $column): ?string {
                        $record = $column->getRecord();
                        if (! $record instanceof OrderProduct) {
                            return null;
                        }

                        return $record->getStatus() === OrderProductStatus::PickedReceived
                            ? 'Status gepickt kan niet worden gewijzigd.'
                            : null;
                    })
                    ->updateStateUsing(function (OrderProduct $record, OrderProductStatus|string $state) {
                        if (empty($state) || empty($record)) {
                            return;
                        }
                        $toStatus = $state instanceof OrderProductStatus
                            ? $state
                            : OrderProductStatus::tryFrom((string) $state);
                        if ($toStatus === null) {
                            return;
                        }

                        if ($record->getStatus() === OrderProductStatus::PickedReceived) {
                            return;
                        }
                        if (! $this->canMoveToNextOrderProductStatus($record->getStatus(), $toStatus)) {
                            return;
                        }

                        // Are all other order products delivered?
                        $areOthersDelivered = $this->getTableRecords()->except($record->id)->every('status', '=', OrderProductStatus::Delivered);
                        if ($areOthersDelivered && $toStatus === OrderProductStatus::Delivered) {
                            $record->setStatus(OrderProductStatus::Delivered);
                            $record->save();
                            $this->confirmOrderProductRecord = $record;
                            if ($this->record->getType() === PurchaseOrderType::Stock) {
                                $this->dispatch('open-modal', id: 'mts_purchase_order_delivered_confirm');
                            } else {
                                $this->dispatch('open-modal', id: 'mto_purchase_order_delivered_confirm');
                            }
                        } else {
                            // No product modals on purchase order (e.g. "Receive products / post stock"); those had no active Livewire handlers.
                            $record->setStatus($toStatus);
                            $record->save();
                            $this->syncPurchaseOrderStatusFromRecord();
                        }
                    })
                    ->sortable(),

                TextColumn::make('voorraad')
                    ->label('Voorraad')
                    ->state(fn (OrderProduct $record): int => $record->product?->stock?->getPhysicalStock() ?? 1),

                TextColumn::make('supplier.name')
                    ->label('Leverancier')
                    ->sortable(),

                TextColumn::make('datum_ingekocht')
                    ->label('Datum ingekocht')
                    ->state(fn (): ?string => $this->record->created_at?->format('d-m-Y') ?? '-'),

                TextColumn::make('inkoopnummer')
                    ->label('Inkoopnummer')
                    ->state(fn (): string => $this->record->order?->uid ?? '-'),
            ])
            ->paginated(['all'])
            ->extraAttributes(['class' => 'orderProductsTable'])
            ->emptyStateHeading('Geen artikelen');
    }

    #[On('confirmMtsPurchaseOrderDelivered')]
    #[On('confirmMtoPurchaseOrderDelivered')]
    public function confirmPoDeliveredFromModal(bool $confirm, ?string $type = null): void
    {
        $type = ($type === 'mts') ? 'mts' : 'mto';
        $modalId = $type === 'mts'
            ? 'mts_purchase_order_delivered_confirm'
            : 'mto_purchase_order_delivered_confirm';

        if (! $confirm) {
            $this->confirmPurchaseOrderForStatus = null;
            $this->confirmOrderProductRecord = null;
            $this->dispatch('close-modal', id: $modalId);
            $this->syncPurchaseOrderStatusFromRecord();
            $this->refreshPurchaseOrderProductsTable();

            return;
        }

        $po = $this->record;

        if ($type === 'mts') {
            foreach ($po->orderProducts as $orderProduct) {
                if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToStock) {
                    app(InventoryService::class)->deliverOrderProduct($orderProduct);
                }
            }

            $po->setStatus(PurchaseOrderStatus::Delivered);
            $po->save();

            Notification::make()
                ->title('De inkooporderstatus is bijgewerkt naar Geleverd en de voorraad is opgeboekt.')
                ->success()
                ->send();
        } else {
            $po->applyMtoDeliveredModalConfirm($this->confirmOrderProductRecord);

            Notification::make()
                ->title('Geleverde regels zijn op Gepickt (ingekocht) gezet.')
                ->success()
                ->send();
        }

        $this->confirmPurchaseOrderForStatus = null;
        $this->confirmOrderProductRecord = null;
        $this->dispatch('close-modal', id: $modalId);
        $this->syncPurchaseOrderStatusFromRecord();
        $this->refreshPurchaseOrderProductsTable();
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
        if ($from === null || $from === PurchaseOrderStatus::Initial || $from === PurchaseOrderStatus::PartiallyConfirmed) {
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

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Verkoop',
            route('filament.app.resources.orders.index') => 'Orders',
        ];
    }
}
