@php
    use App\Enums\OrderStatus;
    use App\Enums\OrderSubtype;
    use App\Enums\ReleaseOrderStatus;
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Filament\Resources\OrderResource\Widgets\CanceledProductsTableWidget;
    use App\Filament\Resources\OrderResource\Widgets\OpenProductsTableWidget;
    use App\Filament\Resources\OrderResource\Widgets\PickedProductsTableWidget;
    use App\Filament\Resources\OrderResource\Widgets\PurchasedProductsTableWidget;
    use App\Filament\Resources\OrderResource\Widgets\ReleasedProductsTableWidget;
    use App\Models\Order\Main;
    use App\Models\ReleaseOrder;
    use Illuminate\Support\Collection;

    /** @var Main $record */
    /** @var ViewOrder $this */

    $openCount = $record->getPurchaseOpenProducts()?->count() ?? 0;
    $purchasedCount = $record->getPurchasedProducts()?->count() ?? 0;
    $releasedCount = $record->getReleasedProducts()?->count() ?? 0;
    $canceledCount = $record->getCanceledProducts()?->count() ?? 0;
    $pickedCount = $record->getPurchasedPickedProducts()?->count() ?? 0;

    $adminCheck = $record->getAdministrationCheck();
    $advisorCheck = $record->getAdvisorCheck();

    $productSubtabStorageKey = 'filament-main-' . ($record?->getId() ?? 0) . '-inkoop-product-subtab';
@endphp

<main class="ordersTab inkoopTab">
    <section id="card-inkoop-details" class="card">
        <h3 class="card__title">Details</h3>
        <h4 class="block-title">Checks</h4>

        <ul class="kv" style="margin-bottom: 12px;">
            <li>
                <span class="k">Administratie:</span>
                <span
                    class="v">{{ $adminCheck ? ($adminCheck->changedBy?->name ?? '-') . ' - ' . $adminCheck->created_at?->format('d-m-Y (H:i)') : '-' }}</span>
            </li>
            @if ($record->getSubtype() !== OrderSubtype::Part)
            <li>
                <span class="k">Adviseur:</span>
                <span
                    class="v">{{ $advisorCheck ? ($advisorCheck->changedBy?->name ?? '-') . ' - ' . $advisorCheck->created_at?->format('d-m-Y (H:i)') : '-' }}</span>
            </li>
            @endif
        </ul>
    </section>

    @if ($record)
        <livewire:documents-block
            :owner-id="$record->id"
            :owner-class="get_class($record)"
            info="Alle definitieve documenten voor de inkoop hier uploaden."
            collection="product_documents"
            :allowed-mime-types="config('documents.allowed_mime_types', [])"
            upload-zone-key="inkoop-product-documents"
            section-id="card-inkoop-documenten"
            :key="'product_documents-main-' . $record->id"
        />
    @else
        <section id="card-inkoop-documenten" class="card">
            <h3 class="card__title">Documenten</h3>
        </section>
    @endif

    <section id="card-logistics" class="card">
        <div class="flex justify-between items-start">
            <h3 class="card__title">Logistiek</h3>
        </div>


        <div class="logistics">
            <div class="logistics__head">
                <div>Inkooporder</div>
                <div>Datum</div>
                <div>Leverancier</div>
                <div class="right">Leverweek</div>
            </div>

            @php
                $purchaseOrders = $record?->purchaseOrders;
            @endphp
            @foreach ($purchaseOrders as $purchaseOrder)
                @if ($purchaseOrder->getStatus()?->value !== 'initial' && !empty($purchaseOrder->getStatus()))
                    <div class="logistics__row">
                        <div>
                            @can('manage purchases')
                            <a href="{{ route('filament.app.resources.purchase-orders.view', ['record' => $purchaseOrder->id]) }}" class="main-request-number-link hover:underline">
                                {{ $purchaseOrder->reference_number }}
                            </a>
                            @else
                            {{ $purchaseOrder->reference_number }}
                            @endcan
                        </div>

                        <div class="date"> {{ $purchaseOrder->getSentAt()?->format('d-m-Y') }}</div>

                        <div class="supplier">
                            {{ $purchaseOrder->supplier?->getName() }}
                        </div>


                        @php
                            $latestDeliveryDate = $purchaseOrder->getLatestExpectedDeliveryDateAttribute();
                        @endphp
                        <div class="right">
                            @if (!empty($latestDeliveryDate))
                                Week {{ $latestDeliveryDate?->format('W') }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach

            @php
                /** @var Collection<int, ReleaseOrder> $releaseOrders */
                $releaseOrders = $record?->releaseOrders ?? collect();
                $visibleReleaseOrders = $releaseOrders->filter(
                    fn (ReleaseOrder $ro): bool => $ro->getStatus() !== ReleaseOrderStatus::Initial
                );
            @endphp
            @foreach ($visibleReleaseOrders as $releaseOrder)
                <div class="logistics__row">
                    <div>
                        @can('manage purchases')
                        <a href="{{ route('filament.app.resources.release-orders.view', ['record' => $releaseOrder->id]) }}" class="main-request-number-link hover:underline">
                            {{ $releaseOrder->reference_number }}
                        </a>
                        @else
                        {{ $releaseOrder->reference_number }}
                        @endcan
                    </div>

                    <div class="date"> {{ $releaseOrder->getSentAt()?->format('d-m-Y') }}</div>


                    <div class="dealer">
                        {{ $releaseOrder->dealer?->getName() }}
                    </div>


                    <div class="right">-</div>
                </div>
            @endforeach

        </div>
    </section>

    <section
        id="products-section"
        class="card w-full"
        x-data="{
            storageKey: @js($productSubtabStorageKey),
            activeProductTab: 'open',
            allowedTabs: ['open', 'purchased', 'released', 'picked', 'canceled'],
            init() {
                try {
                    const stored = sessionStorage.getItem(this.storageKey);
                    if (this.allowedTabs.includes(stored)) {
                        this.activeProductTab = stored;
                    }
                } catch (e) {
                }
            },
            selectProductSubtab(tab) {
                if (! this.allowedTabs.includes(tab)) {
                    return;
                }
                this.activeProductTab = tab;
                try {
                    sessionStorage.setItem(this.storageKey, tab);
                } catch (e) {
                }
            },
        }"
    >
        <div class="products-tabs border-b border-gray-200 dark:border-white/10 mb-4">
            <nav class="flex flex-wrap gap-4" aria-label="Product subtabs">
                <button
                    type="button"
                    class="products-tab-btn py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    :class="activeProductTab === 'open' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    x-on:click="selectProductSubtab('open')"
                >
                    Openstaand ({{ $openCount }})
                </button>
                <button
                    type="button"
                    class="products-tab-btn py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    :class="activeProductTab === 'purchased' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    x-on:click="selectProductSubtab('purchased')"
                >
                    Ingekocht ({{ $purchasedCount }})
                </button>
                <button
                    type="button"
                    class="products-tab-btn py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    :class="activeProductTab === 'released' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    x-on:click="selectProductSubtab('released')"
                >
                    Afgeroepen ({{ $releasedCount }})
                </button>
                <button
                    type="button"
                    class="products-tab-btn py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    :class="activeProductTab === 'picked' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    x-on:click="selectProductSubtab('picked')"
                >
                    {{ $record->getSubtype() === \App\Enums\OrderSubtype::Part ? 'Gepickt / verzonden' : 'Gepickt' }} ({{ $pickedCount }})
                </button>
                <button
                    type="button"
                    class="products-tab-btn py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    :class="activeProductTab === 'canceled' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    x-on:click="selectProductSubtab('canceled')"
                >
                    Geannuleerd ({{ $canceledCount }})
                </button>
            </nav>
        </div>
        <div class="products-panels">
            <div x-show="activeProductTab === 'open'"
                 class="products-panel products-panel-open inkoopTab__table w-full">
                @if ($record)
                    @livewire(OpenProductsTableWidget::class, ['record' => $record], 'open-products-' . $record->id . '-' . $openCount)
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Geen openstaande producten</p>
                @endif
            </div>
            <div x-show="activeProductTab === 'purchased'" x-cloak
                 class="products-panel products-panel-purchased inkoopTab__table w-full">
                @if ($record)
                    @livewire(PurchasedProductsTableWidget::class, ['record' => $record], 'purchased-products-' . $record->id . '-' . $purchasedCount)
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Geen ingekochte producten</p>
                @endif
            </div>
            <div x-show="activeProductTab === 'released'" x-cloak
                 class="products-panel products-panel-released inkoopTab__table w-full">
                @if ($record)
                    @livewire(ReleasedProductsTableWidget::class, ['record' => $record], 'released-products-' . $record->id . '-' . $releasedCount)
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Geen afgeroepen producten</p>
                @endif
            </div>
            <div x-show="activeProductTab === 'picked'" x-cloak
                 class="products-panel products-panel-picked inkoopTab__table w-full">
                @if ($record)
                    @livewire(PickedProductsTableWidget::class, ['record' => $record], 'picked-products-' . $record->id . '-' . $pickedCount)
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Geen gepickte producten</p>
                @endif
            </div>
            <div x-show="activeProductTab === 'canceled'" x-cloak
                 class="products-panel products-panel-canceled inkoopTab__table w-full">
                @if ($record)
                    @livewire(CanceledProductsTableWidget::class, ['record' => $record], 'canceled-products-' . $record->id . '-' . $canceledCount)
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Geen geannuleerde producten</p>
                @endif
            </div>
        </div>
    </section>
</main>

@include('livewire.modals.order-picked-confirm-modal')
@include('livewire.modals.order-delivered-pick-confirm-modal')
