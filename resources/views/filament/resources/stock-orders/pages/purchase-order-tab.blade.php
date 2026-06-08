@php
    use App\Models\Customer;
    use App\Models\Country;

    $company = Customer::getRdMobilityCustomer();
    $supplier = $record?->supplier;
    $additional = $record?->getAdditional() ?? [];

    $billingAddress = $record?->billingAddress;
    $invoiceName = $additional['billing_name'] ?? ($company?->name ?? 'RD Mobility');
    $invoiceCountry = $billingAddress?->country_id ? Country::find($billingAddress->country_id)?->getName() : null;

    $deliveryAddress = is_array($additional['delivery_address'] ?? null) ? $additional['delivery_address'] : [];
    $hasDeliveryFromAdditional = ! empty($deliveryAddress)
        && (($deliveryAddress['street'] ?? '') !== '' || ($deliveryAddress['city'] ?? '') !== '');

    if (! $hasDeliveryFromAdditional && $record?->shippingAddress) {
        $addr = $record->shippingAddress;
        $deliveryAddress = [
            'street' => $addr->street,
            'house_number' => $addr->house_number,
            'house_number_addition' => $addr->house_number_addition,
            'postcode' => $addr->postcode,
            'city' => $addr->city,
            'country_id' => $addr->country_id,
        ];
    }

    $deliveryName = trim((string) ($additional['shipping_name'] ?? ''));
    if ($deliveryName === '') {
        $deliveryName = Customer::getRdMobilityCustomer()?->getName() ?? 'RD Mobility';
    }

    $deliveryCountry = null;
    if (! empty($deliveryAddress['country_id'])) {
        $deliveryCountry = Country::find($deliveryAddress['country_id'])?->getName();
    }
@endphp

<main class="ordersTab">
    <section id="card-order" class="card">
        <h3 class="card__title">Inkooporder details</h3>
        <ul class="kv">
            <li>
                <span class="k">Type:</span>
                <span class="v">Voorraadorder</span>
            </li>
            <li>
                <span class="k">Inkooporder datum:</span>
                <span class="v">{{ $record?->getCreatedAt()?->format('d-m-Y') }}</span>
            </li>
            <li>
                <span class="k">Referentie:</span>
                <span class="v">{{ $record?->reference ?: '-' }}</span>
            </li>
        </ul>
    </section>

    <section id="card-finance" class="card">
        <h3 class="card__title">
            <span class="title">Financieel overzicht</span>
            <span class="muted small">(excl. BTW)</span>
        </h3>
        <div class="finance">
            <ul class="kv">
                <li>
                    <span class="k">ERP | Inkoop:</span>
                    <span class="v">@money($this->priceTotals['companyPurchasePrice'] ?? 0)</span>
                </li>
                <li>
                    <span class="k">Factuur | Inkoop:</span>
                    <span class="v">
                        @if ($this->invoiceAmount !== null)
                            @money($this->invoiceAmount)
                        @else
                            -
                        @endif
                    </span>
                </li>
                <li>
                    <span class="k">Delta | Inkoop:</span>
                    <span class="v">{!! $this->getDeltaPurchaseMargin() ?? '-' !!}</span>
                </li>
            </ul>
        </div>
    </section>

    @include('filament.resources.purchase-orders.pages.partials.purchase-order-status-overview', [
        'record' => $record,
        'timeline' => $this->getOrderStatusTimeline(),
    ])

    <section id="card-dealer" class="card" x-data="{ activeTab: 'customer' }">
        <div class="tabs" role="tablist" aria-label="Stock order tabs">
            <button class="tab" role="tab" :aria-selected="activeTab === 'customer'" @click="activeTab = 'customer'">
                Klant
            </button>
            @if (!empty($supplier))
                <button class="tab" role="tab" :aria-selected="activeTab === 'supplier'" @click="activeTab = 'supplier'">
                    Leverancier
                </button>
            @endif
        </div>

        <div x-show="activeTab === 'customer'">
            <h4 class="block-title">Factuuradres</h4>
            <div class="tab-grid">
                <ul class="kv">
                    <li><span class="k">Naam:</span><span class="v">{{ $invoiceName ?: '-' }}</span></li>
                    <li><span class="k">Straatnaam:</span><span class="v">{{ $billingAddress?->street ?: '-' }}</span></li>
                    <li><span class="k">Huisnummer:</span><span class="v">{{ $billingAddress?->house_number ?: '-' }}</span></li>
                    <li><span class="k">Toevoeging:</span><span class="v">{{ $billingAddress?->house_number_addition ?: '-' }}</span></li>
                    <li><span class="k">Postcode:</span><span class="v">{{ $billingAddress?->postcode ?: '-' }}</span></li>
                    <li><span class="k">Plaatsnaam:</span><span class="v">{{ $billingAddress?->city ?: '-' }}</span></li>
                    <li><span class="k">Land:</span><span class="v">{{ $invoiceCountry ?: '-' }}</span></li>
                </ul>
            </div>

            <h4 class="block-title">Leveradres</h4>
            <div class="tab-grid last">
                <ul class="kv">
                    <li><span class="k">Naam:</span><span class="v">{{ $deliveryName ?: '-' }}</span></li>
                    <li><span class="k">Straatnaam:</span><span class="v">{{ $deliveryAddress['street'] ?? '-' }}</span></li>
                    @php
                        $delHn = trim((string) ($deliveryAddress['house_number'] ?? ''));
                        $delAdd = trim((string) ($deliveryAddress['house_number_addition'] ?? ''));
                        $delPc = trim((string) ($deliveryAddress['postcode'] ?? ''));
                        $delCity = trim((string) ($deliveryAddress['city'] ?? ''));
                        $delLine1 = ($delHn !== '' || $delAdd !== '') ? $delHn . ($delAdd !== '' ? ', ' . $delAdd : '') : '';
                        $delLine2 = ($delPc !== '' || $delCity !== '') ? $delPc . ($delPc !== '' && $delCity !== '' ? ', ' : '') . $delCity : '';
                    @endphp
                    <li><span class="k">Huisnummer:</span><span class="v">{{ $delLine1 !== '' ? $delLine1 : '-' }}</span></li>
                    <li><span class="k">Postcode en plaats:</span><span class="v">{{ $delLine2 !== '' ? $delLine2 : '-' }}</span></li>
                    <li><span class="k">Land:</span><span class="v">{{ $deliveryCountry ?? '-' }}</span></li>
                </ul>
            </div>
        </div>

        @if (!empty($supplier))
            <div x-show="activeTab === 'supplier'">
                <div class="rel-link">
                    <a href="{{ route('filament.app.resources.suppliers.edit', $supplier?->id) }}" target="_blank">
                        <span>Relatiepagina Leverancier</span>
                        @svgImg('img/icons/chevron-left.svg')
                    </a>
                </div>

                <h4 class="block-title">Gegevens</h4>
                <div class="tab-grid last">
                    <ul class="kv">
                        <li><span class="k">Naam:</span><span class="v">{{ $supplier?->name ?: '-' }}</span></li>
                        <li><span class="k">Straatnaam:</span><span class="v">{{ $supplier?->street ?: '-' }}</span></li>
                        <li><span class="k">Huisnummer:</span><span class="v">{{ $supplier?->house_number ?: '-' }}</span></li>
                        <li><span class="k">Toevoeging:</span><span class="v">{{ $supplier?->house_number_addition ?: '-' }}</span></li>
                        <li><span class="k">Postcode:</span><span class="v">{{ $supplier?->postcode ?: '-' }}</span></li>
                        <li><span class="k">Plaatsnaam:</span><span class="v">{{ $supplier?->city ?: '-' }}</span></li>
                        <li><span class="k">Land:</span><span class="v">{{ $supplier?->country?->name ?: '-' }}</span></li>
                        <li><span class="k">KvK-nummer:</span><span class="v">{{ $supplier?->kvk_number ?: '-' }}</span></li>
                        <li><span class="k">BTW-nummer:</span><span class="v">{{ $supplier?->vat_number ?: '-' }}</span></li>
                    </ul>
                </div>
            </div>
        @endif
    </section>

    @if ($record)
        <livewire:documents-block
            :owner-id="$record->id"
            :owner-class="\App\Models\Order\StockOrder::class"
            collection="documents"
            :allowed-mime-types="config('documents.allowed_mime_types', [])"
            upload-zone-key="stock-order-documents"
            section-id="card-docs"
            :key="'documents-stock-order-' . $record->id"
        />
    @endif
</main>
