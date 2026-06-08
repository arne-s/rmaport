<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Widgets\Concerns\ConfiguresClickableProductNameColumn;
use App\Filament\Resources\OrderResource\Widgets\Concerns\LocksProductTabWhenMainOrderPhase;
use App\Filament\Support\PurchaseAuthorization;
use App\Filament\Support\RecordLockNavigation;
use App\Enums\CustomerType;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Enums\ReleaseOrderStatus;
use App\Models\Customer;
use App\Models\Country;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Models\PurchaseOrder;
use App\Models\ReleaseOrder;
use App\Models\User;
use App\Services\OrderProductSelectionLockService;
use App\Services\InventoryService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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

        $query = $main?->getPurchaseOpenProducts()?->getQuery();

        if ($query === null) {
            return OrderProduct::query()->whereRaw('0 = 1');
        }

        return $query->with(['purchaseOrder', 'product.stock']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->selectable($this->canInteractWithPurchaseTabProducts())
            ->columns([
                $this->configureProductNameColumn(
                    TextColumn::make('product.name')
                        ->label('Artikelnaam RD Mobility'),
                ),
                TextColumn::make('product.type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state): string => $state instanceof ProductType
                        ? ($state->getLabel() ?? '-')
                        : (ProductType::tryFrom((string) $state)?->getLabel() ?? '-')),

                $this->configureClickableProductUidColumn(
                    TextColumn::make('product.uid')
                        ->label('Artikelnummer RD Mobility')
                        ->placeholder('-'),
                ),
                TextColumn::make('product.supplier.name')
                    ->label('Leverancier')
                    ->placeholder('-')
                    ->sortable('product.supplier.name'),
                TextColumn::make('qty')
                    ->label('Benodigd aantal'),

                TextColumn::make('concept_purchase_order_link')
                    ->label('Status')
                    ->state('concept-inkooporder')
                    ->action(function (OrderProduct $record): void {
                        if (! PurchaseAuthorization::canManage() || $record->purchase_order_id === null) {
                            return;
                        }

                        $purchaseOrder = $record->purchaseOrder;
                        if ($purchaseOrder === null) {
                            return;
                        }

                        $returnToOrder = $this->record instanceof Main && $this->record->getId()
                            ? '?return_to_order=' . $this->record->getId()
                            : '';

                        $this->redirectToPurchaseOrderEditIfAllowed(
                            $purchaseOrder,
                            route('filament.app.resources.purchase-orders.edit', ['record' => $purchaseOrder->getId()]) . $returnToOrder,
                        );
                    })
                    ->extraCellAttributes(['class' => 'fi-ta-cell--nav-link'])
                    ->visible(fn (?OrderProduct $record): bool => $record !== null && $record->purchase_order_id !== null),

                SelectColumn::make('status')
                    ->label('Status')
                    ->options(function (?OrderProduct $record): array {
                        if ($record === null) {
                            return OrderProductStatus::getInitialStatusLabels();
                        }

                        $po = $record->purchaseOrder;

                        return ($po !== null && $po->getStatus() !== PurchaseOrderStatus::Initial)
                            ? OrderProductStatus::getMtoLabels()
                            : OrderProductStatus::getInitialStatusLabels();
                    })
                    ->selectablePlaceholder(false)
                    ->rememberOptions(false)
                    ->visible(fn (?OrderProduct $record): bool => $record === null || $record->purchase_order_id === null)
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
                        abort_unless(PurchaseAuthorization::canManage(), 403);

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
            ->emptyStateHeading('Geen Artikelen')
            ->toolbarActions(
                $this->canInteractWithPurchaseTabProducts()
                    ? [
                BulkAction::make('inkopen')
                    ->label('Inkopen')
                    ->icon('heroicon-o-shopping-cart')
                    ->action(function (Collection $records): void {
                        abort_unless(PurchaseAuthorization::canManage(), 403);
                        if ($records->isEmpty()) {
                            Notification::make()
                                ->title('Selecteer minimaal één product.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $records = $this->guardBulkOrderProductSelection($records);
                        if ($records === null) {
                            return;
                        }

                        try {
                        $withPurchaseOrder = $records->filter(fn (OrderProduct $r): bool => $r->purchase_order_id !== null);

                        if ($withPurchaseOrder->isNotEmpty() && $withPurchaseOrder->first()->getStatus()->value !== 'initial') {
                            Notification::make()
                                ->title('Selectie bevat artikelen die al een inkooporder hebben')
                                ->danger()
                                ->send();

                            return;
                        }

                        $recordsWithoutSupplier = $records->filter(
                            fn (OrderProduct $record): bool => static::getPurchaseTabSupplierId($record) === null
                        );

                        if ($recordsWithoutSupplier->isNotEmpty()) {
                            Notification::make()
                                ->title('Niet alle geselecteerde producten hebben een leverancier.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $supplierIds = $records->map(
                            fn (OrderProduct $record): ?int => static::getPurchaseTabSupplierId($record)
                        )->unique()->values();

                        if ($supplierIds->count() > 1) {
                            Notification::make()
                                ->title('Meerdere leveranciers geselecteerd')
                                ->body('Selecteer alleen producten van dezelfde leverancier om een inkooporder aan te maken.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $supplierId = $supplierIds->first();

                        $main = $this->record instanceof Main ? $this->record : $this->record?->main;
                        $main?->loadMissing(['billingCustomer']);
                        $mainId = $main?->getId();
                        $orderId = $this->record instanceof Main
                            ? ($main?->getOrderId() ?? $main?->getLastOrder()?->getId())
                            : $this->record->getId();

                        $quoteId = $main?->getNewestApprovedQuote()?->getId() ?? $this->record->quote_id ?? null;

                        $purchaseOrder = new PurchaseOrder;
                        $purchaseOrder->setType(PurchaseOrderType::Order);
                        $purchaseOrder->setOrderId($orderId);
                        $purchaseOrder->setSupplierId($supplierId);
                        $purchaseOrder->setReferenceNumber('concept');
                        $purchaseOrder->setMainId($mainId);
                        $purchaseOrder->setQuoteId($quoteId);
                        $purchaseOrder->save();

                        $rdCustomer = Customer::getRdMobilityCustomer();
                        $addr = $rdCustomer->billingAddress;
                        $shippingName = $rdCustomer->getName();

                        $purchaseOrderAdditionalHandled = false;
                        if ($main instanceof Main && $main->usesUnitSimplifiedSalesFlow()) {
                            $bc = $main->billingCustomer;
                            $physical = $bc?->getPhysicalDeliveryAddress();
                            if ($physical !== null) {
                                $purchaseOrder->setAdditional([
                                    'shipping_address_type_key' => 'billing',
                                    'shipping_name' => $bc->getName(),
                                    'delivery_address' => [
                                        'street' => $physical->street,
                                        'house_number' => $physical->house_number,
                                        'house_number_addition' => $physical->house_number_addition,
                                        'postcode' => $physical->postcode,
                                        'city' => $physical->city,
                                        'country_id' => $physical->country_id ?? Country::NL_ID,
                                    ],
                                ]);
                                $purchaseOrder->save();
                                $purchaseOrderAdditionalHandled = true;
                            }
                        }

                        if (! $purchaseOrderAdditionalHandled && $addr !== null) {
                            $purchaseOrder->setAdditional([
                                'shipping_address_type_key' => 'rd',
                                'shipping_name' => $shippingName,
                                'delivery_address' => [
                                    'street' => $addr->street,
                                    'house_number' => $addr->house_number,
                                    'house_number_addition' => $addr->house_number_addition,
                                    'postcode' => $addr->postcode,
                                    'city' => $addr->city,
                                    'country_id' => $addr->country_id ?? Country::NL_ID,
                                ],
                            ]);
                            $purchaseOrder->save();
                        }

                        $orderProductIds = $records->pluck('id');

                        OrderProduct::whereIn('id', $orderProductIds)->update([
                            'purchase_order_id' => $purchaseOrder->getId(),
                            'status' => OrderProductStatus::Initial->value,
                        ]);

                        $returnToOrder = $mainId !== null ? '?return_to_order=' . $mainId : '';
                        $editUrl = route('filament.app.resources.purchase-orders.edit', ['record' => $purchaseOrder->getId()]) . $returnToOrder;
                        $this->redirectToPurchaseOrderEditIfAllowed($purchaseOrder, $editUrl);
                        } finally {
                            $this->releaseBulkOrderProductSelection($records);
                        }
                    }),
                BulkAction::make('afroepen')
                    ->label('Afroepen')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->disabled(fn (): bool => ! $this->billingCustomerOnContextIsDealer())
                    ->action(function (Collection $records): void {
                        abort_unless(PurchaseAuthorization::canManage(), 403);
                        if ($records->isEmpty()) {
                            Notification::make()
                                ->title('Selecteer minimaal één product.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $records = $this->guardBulkOrderProductSelection($records);
                        if ($records === null) {
                            return;
                        }

                        try {
                        if (! $this->billingCustomerOnContextIsDealer()) {
                            Notification::make()
                                ->title('Afroepen niet mogelijk')
                                ->body('Afroepen is alleen beschikbaar als de factuurklant het type Dealer heeft.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $withReleaseOrder = $records->filter(fn (OrderProduct $r): bool => $r->release_order_id !== null);
                        if ($withReleaseOrder->isNotEmpty()) {
                            $first = $withReleaseOrder->first();
                            $ro = $first->releaseOrder;
                            if ($ro !== null && $ro->getStatus() !== ReleaseOrderStatus::Initial) {
                                Notification::make()
                                    ->title('Selectie bevat artikelen die al een afroepverzoek hebben')
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        $main = $this->record instanceof Main ? $this->record : $this->record?->main;
                        $mainId = $main?->getId();
                        $dealerId = $main?->billing_customer_id;
                        if ($dealerId === null) {
                            Notification::make()
                                ->title('Geen factuurklant')
                                ->body('De aanvraag heeft geen factuurklant gekoppeld.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $orderId = $this->record instanceof Main ? null : $this->record->getId();
                        $quoteId = $main?->getNewestApprovedQuote()?->getId() ?? $this->record->quote_id ?? null;

                        $releaseOrder = new ReleaseOrder;
                        $releaseOrder->setReferenceNumber('concept');
                        $releaseOrder->setOrderId($orderId);
                        $releaseOrder->setMainId($mainId);
                        $releaseOrder->setQuoteId($quoteId);
                        $releaseOrder->setDealerId($dealerId);
                        $releaseOrder->setStatus(ReleaseOrderStatus::Initial);
                        $releaseOrder->save();

                        $orderProductIds = $records->pluck('id');

                        OrderProduct::whereIn('id', $orderProductIds)->update([
                            'release_order_id' => $releaseOrder->getId(),
                            'status' => OrderProductStatus::Initial->value,
                        ]);

                        if ($main !== null) {
                            $main->recalculateProductSummary();
                        }

                        $returnToOrder = $mainId !== null ? '?return_to_order=' . $mainId : '';
                        $this->redirect(
                            route('filament.app.resources.release-orders.edit', ['record' => $releaseOrder->getId()]) . $returnToOrder,
                            navigate: false
                        );
                        } finally {
                            $this->releaseBulkOrderProductSelection($records);
                        }
                    }),
            ]
                    : [],
            );
    }

    /**
     * Supplier on the purchase tab comes from product master data (see leverancier column).
     */
    private static function getPurchaseTabSupplierId(OrderProduct $record): ?int
    {
        $supplierId = $record->product?->supplier_id;

        if ($supplierId === null || $supplierId === 0) {
            return null;
        }

        return (int) $supplierId;
    }

    private function billingCustomerOnContextIsDealer(): bool
    {
        $main = $this->record instanceof Main ? $this->record : $this->record?->main;
        if ($main === null) {
            return false;
        }

        $main->loadMissing('billingCustomer');

        return $main->billingCustomer?->getType() === CustomerType::Dealer;
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

        return filter_var($product->is_stock_enabled, FILTER_VALIDATE_BOOLEAN);
    }

    private function redirectToPurchaseOrderEditIfAllowed(PurchaseOrder $purchaseOrder, string $editUrl): void
    {
        RecordLockNavigation::attemptRedirectToEdit(
            $this,
            $purchaseOrder,
            $editUrl,
            navigate: false,
        );
    }

    private function purchaseTabBackUrl(): string
    {
        $main = $this->record instanceof Main ? $this->record : $this->record?->main;

        if ($main instanceof Main) {
            return route('filament.app.resources.mains.view', ['record' => $main->getId()]) . '?tab=purchase';
        }

        return url()->current();
    }

    /**
     * @param  Collection<int, OrderProduct>  $records
     * @return Collection<int, OrderProduct>|null
     */
    private function guardBulkOrderProductSelection(Collection $records): ?Collection
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $records;
        }

        $blocking = app(OrderProductSelectionLockService::class)->acquireBulkSelectionOrGetBlockingDetails(
            $records,
            $user,
            $this->purchaseTabBackUrl(),
        );

        if ($blocking !== null) {
            RecordLockNavigation::notifyOrderProductSelectionBlocked($blocking);

            return null;
        }

        return $records;
    }

    /**
     * @param  Collection<int, OrderProduct>  $records
     */
    private function releaseBulkOrderProductSelection(Collection $records): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        app(OrderProductSelectionLockService::class)->releaseBulkSelection($records, $user);
    }
}

