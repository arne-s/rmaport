@php
    use App\Enums\PurchaseOrderType;
    use App\Filament\Resources\PurchaseOrderResource\Pages\ViewPurchaseOrder;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    $type = $record?->getType();
    /** @var ViewPurchaseOrder $this */
@endphp

<main class="ordersTab">
    <!-- Top row -->
    <section id="card-order" class="card">
        <h3 class="card__title">Inkooporder details</h3>
        <ul class="kv">
            <li>
                <span class="k">Type:</span>
                <span class="v">{{ $type?->getLabel() }}</span>
            </li>
            @if ($type === PurchaseOrderType::Order && filled($record->main_id))
                <li>
                    <span class="k">Aanvraag #:</span>
                    <span class="v">
                        <a href="{{ route('filament.app.resources.mains.view', ['record' => $record->main_id]) }}?tab=purchase" class="main-request-number-link hover:underline" target="_blank">
                            {{ $record->main?->getUidFormatted() ?? $record->order?->getUidFormatted() }}
                        </a>
                    </span>
                </li>
            @endif
            <li>
                <span class="k">Type aanvraag:</span>
                <span class="v">{{ $record->main?->getSubtype()?->getLabel() ?? '-' }}</span>
            </li>        <li>
                <span class="k">Inkooporder datum:</span>
                <span class="v">{{ $record?->getCreatedAt()?->format('d-m-Y') }}</span>
            </li>
            <li>
                <span class="k">Leverweek:</span>
                <span class="v">
                    <input
                        type="text"
                        wire:model="purchaseOrderDeliveryWeek"
                        class="fi-input block w-full rounded-lg border border-gray-300 shadow-sm text-sm"
                        placeholder=""
                    />
                </span>
            </li>
        </ul>
    </section>

    <section id="card-finance" class="card">
        <div class="flex justify-between items-start">
            <h3 class="card__title">
                <span class="title">Financieel overzicht</span>
                <span class="muted small">(excl. BTW)</span>
            </h3>
        </div>

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

    <!-- Bottom row: Verzendadres/Leverancier + Documenten -->
    <section id="card-dealer" class="card" x-data="{ activeTab: 'company' }">
        @php
            use App\Enums\CustomerAddressType;
            use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
            use App\Models\Customer;
            use App\Models\Order\BaseOrder;

            $rdCustomer = Customer::getRdMobilityCustomer();
            $rdBillingAddr = $rdCustomer?->billingAddress;
            $companyAddress = [
                'fullName' => $rdCustomer?->getName(),
                'email' => $rdCustomer?->getEmail(),
                'phone_number' => $rdCustomer?->getPhoneNumber(),
                'street' => $rdBillingAddr?->street,
                'house_number' => $rdBillingAddr?->house_number,
                'house_number_addition' => $rdBillingAddr?->house_number_addition,
                'postcode' => $rdBillingAddr?->postcode,
                'city' => $rdBillingAddr?->city,
                'country' => $rdBillingAddr?->country?->name,
            ];
            $supplier = $record?->supplier;
            $customer = $record?->main?->customer ?? $record?->order?->customer;
            $orderForCustomerContext = $record?->main ?? $record?->order?->getMain() ?? $record?->order;
            if ($orderForCustomerContext instanceof BaseOrder
                && $orderForCustomerContext->customer === null
                && $record?->order instanceof BaseOrder
                && $record->order->customer !== null) {
                $orderForCustomerContext = $record->order;
            }
            $billingCustomer = $record?->main?->billingCustomer
                ?? $record?->order?->main?->billingCustomer;

            $customerTab = null;
            if ($customer !== null && $orderForCustomerContext instanceof BaseOrder && $orderForCustomerContext->customer !== null) {
                $addr = $orderForCustomerContext->getCustomerAddress();
                $email = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($orderForCustomerContext, null);
                $customerTab = [
                    'contact_basis' => match ($orderForCustomerContext->getCustomerAddressType()) {
                        CustomerAddressType::Shipping => 'Leveradres',
                        CustomerAddressType::Billing => 'Factuuradres',
                    },
                    'email' => filled($email) ? $email : '—',
                    'name' => filled($orderForCustomerContext->getCustomerAddressDisplayName()) ? $orderForCustomerContext->getCustomerAddressDisplayName() : '—',
                    'phone' => filled($orderForCustomerContext->getCustomerContactPhone()) ? $orderForCustomerContext->getCustomerContactPhone() : '—',
                    'mobile' => filled($orderForCustomerContext->getCustomerContactMobile()) ? $orderForCustomerContext->getCustomerContactMobile() : '—',
                    'street' => filled($addr?->getStreet()) ? $addr->getStreet() : '—',
                    'house_number' => filled($addr?->getHouseNumber()) ? $addr->getHouseNumber() : '—',
                    'house_number_addition' => filled(trim((string) ($addr?->house_number_addition ?? ''))) ? $addr->house_number_addition : '—',
                    'postcode' => filled($addr?->getPostcode()) ? $addr->getPostcode() : '—',
                    'city' => filled($addr?->city) ? $addr->city : '—',
                    'country' => $addr?->country?->name ?? '—',
                ];
            } elseif ($customer !== null) {
                $addr = $customer->billingAddress ?? $customer->address;
                $customerTab = [
                    'contact_basis' => null,
                    'email' => filled($customer->getEmail()) ? $customer->getEmail() : '—',
                    'name' => filled($customer->getName()) ? $customer->getName() : '—',
                    'phone' => filled($customer->getPhoneNumber()) ? $customer->getPhoneNumber() : '—',
                    'mobile' => filled($customer->getMobilePhoneNumber()) ? $customer->getMobilePhoneNumber() : '—',
                    'street' => filled($addr?->getStreet()) ? $addr->getStreet() : '—',
                    'house_number' => filled($addr?->getHouseNumber()) ? $addr->getHouseNumber() : '—',
                    'house_number_addition' => filled(trim((string) ($addr?->house_number_addition ?? ''))) ? $addr->house_number_addition : '—',
                    'postcode' => filled($addr?->getPostcode()) ? $addr->getPostcode() : '—',
                    'city' => filled($addr?->city) ? $addr->city : '—',
                    'country' => $addr?->country?->name ?? '—',
                ];
            }

            $additional = $record?->getAdditional() ?? [];
            $poDelivery = is_array($additional['delivery_address'] ?? null) ? $additional['delivery_address'] : [];
            $hasPoDelivery = ! empty($poDelivery)
                && (($poDelivery['street'] ?? '') !== '' || ($poDelivery['city'] ?? '') !== '');

            $orderProductWithDeliveryAddress = $this->record->orderProducts->first(fn ($product) => ! empty($product->getDeliveryAddress()));

            if ($hasPoDelivery) {
                $nameLine = trim((string) ($additional['shipping_name'] ?? ''));
                if ($nameLine === '') {
                    if ($record?->order?->customer) {
                        $nameLine = $record->order?->customer?->getName() ?? '';
                    } elseif ($type === PurchaseOrderType::Stock) {
                        $nameLine = Customer::getRdMobilityCustomer()?->getName() ?? '';
                    }
                }
                $deliveryAddress = [
                    'fullName' => $nameLine,
                    'email' => $rdCustomer?->getEmail(),
                    'phone_number' => $rdCustomer?->getPhoneNumber(),
                    'street' => $poDelivery['street'] ?? '',
                    'house_number' => $poDelivery['house_number'] ?? '',
                    'house_number_addition' => $poDelivery['house_number_addition'] ?? '',
                    'postcode' => $poDelivery['postcode'] ?? '',
                    'city' => $poDelivery['city'] ?? '',
                    'country' => \App\Models\Country::find($poDelivery['country_id'] ?? null)?->getName() ?? '',
                ];
            } elseif ($orderProductWithDeliveryAddress) {
                $deliveryAddress = $orderProductWithDeliveryAddress->getDeliveryAddress();
                $deliveryAddress['fullName'] = trim(($deliveryAddress['first_name'] ?? '') . ' ' . ($deliveryAddress['last_name'] ?? ''));
                $deliveryAddress['country'] = \App\Models\Country::find($deliveryAddress['country_id'] ?? null)?->getName() ?? '';
            } else {
                $deliveryAddress = $companyAddress;
            }
        @endphp

        <div class="tabs" role="tablist" aria-label="Adres tabs">
            <button class="tab" role="tab" :aria-selected="activeTab === 'company'" @click="activeTab = 'company'">
                Verzendadres
            </button>
            @if (!empty($customer))
                <button class="tab" role="tab" :aria-selected="activeTab === 'customer'"
                        @click="activeTab = 'customer'">Klant
                </button>
            @endif
            <button class="tab" role="tab" :aria-selected="activeTab === 'supplier'" @click="activeTab = 'supplier'">
                Leverancier
            </button>
        </div>

        <div x-show="activeTab === 'company'">
            <h4 class="block-title">Verzendadres inkooporder</h4>
            <div class="tab-grid last">
                <div class="shipping">
                    <ul class="kv">
                        <li>
                            <span class="k">Naam:</span>
                            <span class="v">{{ $deliveryAddress['fullName'] }}</span>
                        </li>
                        <li>
                            <span class="k">E-mailadres:</span>
                            <span class="v">{{ $deliveryAddress['email'] }}</span>
                        </li>
                        <li>
                            <span class="k">Mobiel:</span>
                            <span class="v">{{ $deliveryAddress['phone_number'] }}</span>
                        </li>
                        <li>
                            <span class="k">Straatnaam:</span>
                            <span class="v">{{ $deliveryAddress['street'] }}</span>
                        </li>
                        <li>
                            <span class="k">Huisnummer:</span>
                            <span class="v">{{ trim((string) ($deliveryAddress['house_number'] ?? '')) }}{{ filled(trim((string) ($deliveryAddress['house_number_addition'] ?? ''))) ? ', ' . trim((string) ($deliveryAddress['house_number_addition'] ?? '')) : '' }}</span>
                        </li>
                        <li>
                            <span class="k">Postcode en plaats:</span>
                            <span class="v">{{ trim((string) ($deliveryAddress['postcode'] ?? '')) }}{{ filled(trim((string) ($deliveryAddress['postcode'] ?? ''))) && filled(trim((string) ($deliveryAddress['city'] ?? ''))) ? ', ' : '' }}{{ trim((string) ($deliveryAddress['city'] ?? '')) }}</span>
                        </li>
                        <li>
                            <span class="k">Land:</span>
                            <span class="v">{{ $deliveryAddress['country'] }}</span>
                        </li>
                    </ul>
                </div>
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
                        <li>
                            <span class="k">Naam:</span>
                            <span class="v">{{ $supplier?->name }}</span>
                        </li>
                        <li>
                            <span class="k">Straatnaam:</span>
                            <span class="v">{{ $supplier?->street }}</span>
                        </li>
                        <li>
                            <span class="k">Huisnummer:</span>
                            <span class="v">{{ $supplier?->house_number }}</span>
                        </li>
                        <li>
                            <span class="k">Toevoeging:</span>
                            <span class="v">{{ $supplier?->house_number_addition ?: '-' }}</span>
                        </li>
                        <li>
                            <span class="k">Postcode:</span>
                            <span class="v">{{ $supplier?->postcode }}</span>
                        </li>
                        <li>
                            <span class="k">Plaatsnaam:</span>
                            <span class="v">{{ $supplier?->city }}</span>
                        </li>
                        <li>
                            <span class="k">Land:</span>
                            <span class="v">{{ $supplier?->country?->name }}</span>
                        </li>
                        <li>
                            <span class="k">KvK-nummer:</span>
                            <span class="v">{{ $supplier?->kvk_number ?? '-' }}</span>
                        </li>
                        <li>
                            <span class="k">BTW-nummer:</span>
                            <span class="v">{{ $supplier?->vat_number ?? '-' }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        @endif

        @if ($type === PurchaseOrderType::Order && !empty($customer))
            <div x-show="activeTab === 'customer'">
                <div class="rel-link">
                    <a href="{{ route('filament.app.resources.customers.edit', $customer?->id) }}" target="_blank">
                        <span>Relatiepagina klant</span>
                        @svgImg('img/icons/chevron-left.svg')
                    </a>
                </div>

                <h4 class="block-title">Klantgegevens</h4>
                <div class="tab-grid last">
                    <ul class="kv">
                        @if (!empty($customerTab['contact_basis']))
                            <li>
                                <span class="k">Contact op basis van:</span>
                                <span class="v">{{ $customerTab['contact_basis'] }}</span>
                            </li>
                        @endif
                        <li>
                            <span class="k">E-mailadres:</span>
                            <span class="v">{{ $customerTab['email'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Naam:</span>
                            <span class="v">{{ $customerTab['name'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Telefoon:</span>
                            <span class="v">{{ $customerTab['phone'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Mobiel:</span>
                            <span class="v">{{ $customerTab['mobile'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Straatnaam:</span>
                            <span class="v">{{ $customerTab['street'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Huisnummer:</span>
                            <span class="v">{{ $customerTab['house_number'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Toevoeging:</span>
                            <span class="v">{{ $customerTab['house_number_addition'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Postcode:</span>
                            <span class="v">{{ $customerTab['postcode'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Plaatsnaam:</span>
                            <span class="v">{{ $customerTab['city'] ?? '—' }}</span>
                        </li>
                        <li>
                            <span class="k">Land:</span>
                            <span class="v">{{ $customerTab['country'] ?? '—' }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        @endif
    </section>

    @if ($record)
        <section id="card-docs" class="card">
            <div class="flex justify-between items-center gap-4 purchase-order-docs-header">
                <h3 class="card__title mb-0">Documenten</h3>
                <button
                    type="button"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action uploadDocumentAction"
                    wire:click="mountAction('uploadDocument')"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="mountAction('uploadDocument')" class="inline-flex items-center gap-1">
                        {{ generate_icon_html(Heroicon::OutlinedArrowUpTray, size: IconSize::Small) }}
                        <span>Uploaden</span>
                    </span>
                    <x-filament::loading-indicator
                        class="fi-icon fi-size-sm animate-spin"
                        wire:loading
                        wire:target="mountAction('uploadDocument')"
                    />
                </button>
            </div>

            <livewire:purchase-order-documents-panel
                :purchase-order-id="$record->id"
                :key="'purchase-order-documents-' . $record->id"
            />
        </section>
    @else
        <section id="card-docs" class="card">
            <h3 class="card__title">Documenten</h3>
        </section>
    @endif
</main>
