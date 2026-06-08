@php
    use App\Enums\OrderSubtype;
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Filament\Resources\OrderResource\Widgets\CanceledProductsTableWidget;
    use App\Filament\Resources\OrderResource\Widgets\OpenProductsTableWidget;
    use App\Models\Order\Main;

    /** @var Main $record */
    /** @var ViewOrder $this */

    $openCount = $record->getPurchaseOpenProducts()?->count() ?? 0;
    $canceledCount = $record->getCanceledProducts()?->count() ?? 0;

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

    <section
        id="products-section"
        class="card w-full"
        x-data="{
            storageKey: @js($productSubtabStorageKey),
            activeProductTab: 'open',
            allowedTabs: ['open', 'canceled'],
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
