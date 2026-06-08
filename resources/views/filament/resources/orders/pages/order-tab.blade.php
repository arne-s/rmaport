@php
    use App\Enums\OrderSubtype;
    use App\Enums\PaymentTerms;
    use App\Enums\ReleaseOrderStatus;
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Filament\Resources\OrderResource\Widgets\OrderDocsTableWidget;
    use App\Models\Order\Main;
    use App\Models\Product;
    use App\Models\ReleaseOrder;

       /** @var Main $record **/
       /** @var ViewOrder $this **/

    $frameProductForChair = ($record?->subtype?->value ?? '') === 'unit'
        ? $record?->orders?->last()?->frameProduct
        : $record?->getLastOrder()?->frameProduct;
    $chairTypeRaw = $frameProductForChair?->getChairType();
    $chairType = Product::getFrameChairTypeLabel(is_string($chairTypeRaw) ? $chairTypeRaw : null);

    $linkedSerialNumber = null;
    if (in_array($record?->subtype?->value, ['part', 'service'], true)) {
        $linkedSnValue = $record->getFittingNote()['linked_serial_number'] ?? null;
        if ($linkedSnValue) {
            $linkedSerialNumber = \App\Models\SerialNumber::query()->where('serial_number', $linkedSnValue)->first();
            if ($linkedSerialNumber) {
                $chairTypeRaw = $linkedSerialNumber->getType();
                $chairType = Product::getFrameChairTypeLabel(is_string($chairTypeRaw) ? $chairTypeRaw : null);
            }
        }
    }
    $quote = $record?->quote;
    $mainAdditional = $record->getAdditional() ?? [];
    $billingKeyForView = $mainAdditional['billing_address_type_key'] ?? null;
    if (($billingKeyForView === null || $billingKeyForView === '') && $quote !== null) {
        $billingKeyForView = data_get($quote->getAdditional(), 'billing_address_type_key');
    }
    if ($billingKeyForView === null || $billingKeyForView === '') {
        $billingKeyForView = $record->billing_customer_id ? 'customer-' . $record->billing_customer_id : 'customer';
    }
    $invoiceFactuurCustomer = $billingKeyForView === 'customer';
    $invoiceFactuurCustom = $billingKeyForView === 'custom';
    $invoiceFactuurCompany = $record->billingCustomer;
    $paymentTerms = $record->payment_terms;
    if (! $paymentTerms instanceof PaymentTerms) {
        $paymentTerms = PaymentTerms::tryFrom($record->getPaymentTermsValueForBillingContext());
    }
    $paymentLabel = $paymentTerms?->getLabel() ?? '-';
    $paymentConditionLabel = $record->getExactPaymentConditionLabelForView();
    $hasCustomer = $record?->customer !== null;
    $customerDisplayName = $record->getCustomerAddressDisplayName() ?? '-';
    $deliveryCustomerForLever = $record->shippingCustomer ?? $record->customer;
    $shippingAddress = $deliveryCustomerForLever?->getPhysicalDeliveryAddress();
    $shippingAddressName = $shippingAddress?->getLocationName()
        ?? $record->shippingCustomer?->getName()
        ?? $record->customer?->getName()
        ?? '-';
    $customerAddress = $record->getCustomerAddress();
    $customerEmail = $record->getCustomerContactEmail();
    $customerPhone = $record->getCustomerContactPhone();
    $customerMobile = $record->getCustomerContactMobile();
@endphp

<main class="ordersTab">

    <!-- Top row -->
    <section id="card-order" class="card">
        <h3 class="card__title">Details</h3>

        <h4 class="block-title">Klant</h4>

        <ul class="kv" style="margin-bottom: 12px;">
            <li>
                <span class="k">Naam:</span>
                <span class="v">
                    @if($hasCustomer)
                        <a href="{{ route('filament.app.resources.customers.edit', ['record' => $record->customer?->id]) }}"
                           target="_blank" rel="noopener noreferrer"
                           class="text-primary-600 hover:underline dark:text-primary-400">{{ $customerDisplayName }}</a>
                    @else
                        -
                    @endif
                    @if($record?->customer?->comment)
                        <span class="note-icon"
                              style="display: inline-block; position: relative; cursor: pointer; top: -5px;"
                              x-data="{ show: false }" @mouseenter="show = true" @mouseleave="show = false">
                            <svg class="fi-icon" style="width: 15px; height: 15px; margin-bottom: -3px;"
                                 xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fabd09"
                                 aria-hidden="true"><path fill-rule="evenodd"
                                                          d="M4.5 9.75a6 6 0 0 1 11.573-2.226 3.75 3.75 0 0 1 4.133 4.303A4.5 4.5 0 0 1 18 20.25H6.75a5.25 5.25 0 0 1-2.23-10.004 6.072 6.072 0 0 1-.02-.496Z"
                                                          clip-rule="evenodd"></path></svg>
                            <div x-show="show" x-cloak
                                 class="customer-note-tooltip">
                                <strong>Interne Notitie:</strong><br><br>{{ $record->customer?->comment }}
                            </div>
                        </span>
                    @endif
                </span>
            </li>
            <li>
                <span class="k">E-mailadres:</span>
                <span
                    class="v">{{ $customerEmail ?? '-' }}</span>
            </li>
            <li>
                <span class="k">Adres:</span>
                <span
                    class="v">{{ $customerAddress?->getAddressTemplate() ?? '-' }}</span>
            </li>
            <li>
                <span class="k">Telefoonnummer:</span>
                <span
                    class="v">{{ $customerPhone ?? '-' }}</span>
            </li>
            <li>
                <span class="k">Mobielnummer:</span>
                <span
                    class="v">{{ $customerMobile ?? '-' }}</span>
            </li>
        </ul>

        @if($hasCustomer)
            <h4 class="block-title">Referentie</h4>

            <ul class="kv" style="margin-bottom: 12px;">

                <li>
                    <span class="k">Referentie (intern):</span>
                    <span class="v">
                        <input
                            type="text"
                            wire:model="referenceInternal"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm"
                            placeholder=""
                            style="width: 200px"
                        />
                    </span>
                </li>
                @if (!$record->billingCustomer)
                <li>
                    <span class="k">Uw referentie (klant):</span>
                    <span class="v">
                        <input
                            type="text"
                            wire:model="orderReference"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm"
                            placeholder=""
                            style="width: 200px"
                        />
                    </span>
                </li>
                @endif

                @if ($record->billingCustomer)
                <li>
                    <span class="k">Inkoopordernummer:</span>
                    <span class="v"> <input
                            type="text"
                            wire:model="orderReference"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm"
                            placeholder=""
                            style="width: 200px"
                        /></span>
                </li>
                @endif
                {{--                <li>--}}
                {{--                    <span class="k">Naam:</span>--}}
                {{--                    @php--}}
                {{--                        $mainStatus = \App\Enums\OrderStatus::getMainStatusFor($record->getOrderStatus());--}}
                {{--                        $dealerEditable = in_array($mainStatus, [\App\Enums\OrderStatus::Fitting, \App\Enums\OrderStatus::Quote], true);--}}
                {{--                    @endphp--}}
                {{--                    <select--}}
                {{--                        wire:model="companyId"--}}
                {{--                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm"--}}
                {{--                        aria-label="Dealer"--}}
                {{--                        style="width: 200px"--}}
                {{--                        @if(!$dealerEditable) disabled @endif--}}
                {{--                    >--}}
                {{--                        @foreach ($this->getCompanyOptionsForSelect() as $value => $label)--}}
                {{--                            <option value="{{ $value }}">{{ $label }}</option>--}}
                {{--                        @endforeach--}}
                {{--                    </select>--}}
                {{--                </li>--}}
{{--                <li>--}}
{{--                    <span class="k"> @if ($record->company)--}}
{{--                            Inkoopordernummer: </span>--}}
{{--                    <span class="v">--}}

{{--                            <input--}}
{{--                                type="text"--}}
{{--                                wire:model="orderReference"--}}
{{--                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm"--}}
{{--                                placeholder=""--}}
{{--                                style="width: 200px"--}}
{{--                            />--}}
{{--                        @else--}}
{{--                            ---}}
{{--                        @endif--}}
{{--                        </span>--}}
{{--                </li>--}}

            </ul>
        @endif

        @if ($record->getSubtype() !== OrderSubtype::Part)
        <h4 class="block-title">Adviseur</h4>
        <ul class="kv" style="margin-bottom: 12px;">
            <li>
                <span class="k">Naam:</span>
                <span class="v">{{ $record?->advisor?->name ?? '-' }}</span>
            </li>
        </ul>
        @endif

        <h4 class="block-title">Factuurgegevens</h4>
        <ul class="kv" style="margin-bottom: 12px;">
            @if ($invoiceFactuurCustomer && $hasCustomer)
                <li>
                    <span class="k">Klant:</span>
                    <span class="v">{{ $record->getCustomerAddressDisplayName() }}</span>
                </li>

                <li>
                    <span class="k">E-mailadres:</span>
                    <span class="v">{{ $record?->customer?->getEmail() }}</span>
                </li>
                {{--                <li>--}}
                {{--                    <span class="k">Naam:</span>--}}
                {{--                    <span class="v">{{ $record?->customer?->name }}</span>--}}
                {{--                </li>--}}
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $record?->customer?->billingAddress?->getAddressTemplate() ?? '-' }}</span>
                </li>
            @elseif ($invoiceFactuurCustom)
                <li>
                    <span class="k">Naam:</span>
                    <span class="v">{{ trim((string) (data_get($mainAdditional, 'billing_name') ?? '')) ?: '-' }}</span>
                </li>
                @php $customBillingAddr = data_get($mainAdditional, 'invoice_address'); @endphp
                @if($customBillingAddr)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ implode(', ', array_filter([$customBillingAddr['street'] ?? '', ($customBillingAddr['house_number'] ?? '') . ' ' . ($customBillingAddr['house_number_addition'] ?? ''), $customBillingAddr['postcode'] ?? '', $customBillingAddr['city'] ?? ''])) ?: '-' }}</span>
                </li>
                @endif
            @elseif ($invoiceFactuurCompany)
                <li>
                    <span class="k">Naam:</span>
                    <span class="v">
                        <a href="{{ route('filament.app.resources.customers.edit', ['record' => $invoiceFactuurCompany->id]) }}"
                           target="_blank" rel="noopener noreferrer"
                           class="text-primary-600 hover:underline dark:text-primary-400">{{ $invoiceFactuurCompany->getName() }}</a>
                        @if($invoiceFactuurCompany?->comment)
                            <span class="note-icon"
                                  style="display: inline-block; position: relative; cursor: pointer; top: -5px;"
                                  x-data="{ show: false }" @mouseenter="show = true" @mouseleave="show = false">
                                <svg class="fi-icon" style="width: 15px; height: 15px; margin-bottom: -3px;"
                                     xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fabd09"
                                     aria-hidden="true"><path fill-rule="evenodd"
                                                              d="M4.5 9.75a6 6 0 0 1 11.573-2.226 3.75 3.75 0 0 1 4.133 4.303A4.5 4.5 0 0 1 18 20.25H6.75a5.25 5.25 0 0 1-2.23-10.004 6.072 6.072 0 0 1-.02-.496Z"
                                                              clip-rule="evenodd"></path></svg>
                                <div x-show="show" x-cloak
                                     class="customer-note-tooltip">
                                    <strong>Interne Notitie:</strong><br><br>{{ $invoiceFactuurCompany?->comment }}
                                </div>
                            </span>
                        @endif
                    </span>
                </li>

                <li>
                    <span class="k">Email:</span>
                    <span class="v">{{ $invoiceFactuurCompany?->getEmail() ?? '-' }}</span>
                </li>
                <li>
                    <span class="k" style="margin-bottom: 10px">Adres:</span>
                    <span class="v">{{ $invoiceFactuurCompany?->billingAddress?->getAddressTemplate() ?? '-' }}</span>
                </li>

                <li>
                    <span class="k">Type:</span>
                    <span class="v">{{ $invoiceFactuurCompany->getType()?->getLabel() ?? '-' }}</span>
                </li>


            @elseif ($hasCustomer)
                <li>
                    <span class="k">Klant:</span>
                    <span class="v">{{ $record->getCustomerAddressDisplayName() }}</span>
                </li>

                <li>
                    <span class="k">E-mailadres:</span>
                    <span class="v">{{ $record?->customer?->getEmail() }}</span>
                </li>
                <li>
                    <span class="k">Naam:</span>
                    <span class="v">{{ $record->getCustomerAddressDisplayName() }}</span>
                </li>
                <li style="margin-bottom: 10px">
                    <span class="k">Adres:</span>
                    <span class="v">{{ $record?->customer?->billingAddress?->getAddressTemplate() ?? '-' }}</span>
                </li>
            @endif


            <li>
                <span class="k">Betalingsvoorwaarden:</span>
                <span class="v">{{ $paymentLabel }}</span>
            </li>
            <li>
                <span class="k">Betalingsconditie:</span>
                <span class="v">{{ $paymentConditionLabel }}</span>
            </li>
{{--            @if (!$record->company)--}}

{{--                <li>--}}
{{--                    <span class="k">Referentie:</span>--}}
{{--                    <span class="v">        <input--}}
{{--                            type="text"--}}
{{--                            wire:model="orderReference"--}}
{{--                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm"--}}
{{--                            placeholder=""--}}
{{--                            style="width: 200px"--}}
{{--                        /></span>--}}
{{--                </li>--}}
{{--            @endif--}}


        </ul>

        <h4 class="block-title">Leveradres</h4>

        <ul class="kv">
            <li>
                <span class="k">Naam:</span>
                <span class="v">{{ $shippingAddressName }}</span>
            </li>
            @if($shippingAddress)
            <li>
                <span class="k">Adres:</span>
                <span class="v">{{ $shippingAddress->getAddressTemplate() }}</span>
            </li>
            @endif
        </ul>


        <div class="note">
            {{ $this->customerForm }}
        </div>

    </section>

    <section id="card-logistics" class="card">
        <div class="flex justify-between items-start">
            <h3 class="card__title">Logistiek</h3>
        </div>

        <div class="logistics">
            <div class="logistics__head" style="margin-top: 10px">
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
                /** @var \Illuminate\Support\Collection<int, ReleaseOrder> $releaseOrders */
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

    <section id="card-finance" class="card">

        <h3 class="card__title">
            <span class="title" style="padding-bottom: 3px">Aanvraag</span>
        </h3>
        <h4 class="block-title" style="margin-top: 7px">Informatie</h4>

        {{--            <a--}}
        {{--                class="card__action"--}}
        {{--                href="#"--}}
        {{--                wire:click="$dispatch('open-modal', { id: 'open-order-margins', orderId: '{{ $record->id }}' });"--}}
        {{--            >--}}
        {{--                Margeoverzicht--}}
        {{--            </a>--}}


        <div class="border: 1px solid red;">

            <ul class="kv">
                <li>
                    <span class="k">Type aanvraag:</span>
                    <span class="v">{{ $record?->subtype?->getLabel() ?? '-' }}</span>
                </li>
                <li>
                    <span class="k">Serienummer:</span>
                    <span class="v">
                        @if($linkedSerialNumber)
                            {{ $linkedSerialNumber->getSerialNumber() }}
                        @else
                            {{ $record->getSerialNumberRecord()?->getSerialNumber() ?: '-' }}
                        @endif
                    </span>
                </li>
                <li>
                    <span class="k">Frame:</span>
                    <span class="v">
                        @if(($record?->subtype?->value ?? '') === 'unit')
                            {{ $record?->orders?->last()?->frameProduct?->getName() ?? '-' }}
                        @elseif($linkedSerialNumber)
                            {{ $linkedSerialNumber->getName() ?? '-' }}
                        @else
                            {{ $record?->getSerialNumberRecord()?->getFrameName() ?? '-' }}
                        @endif
                    </span>
                </li>
                <li>
                    <span class="k">Type:</span>
                    <span class="v">{{ $chairType }}</span>
                </li>
                <li>
                    <span class="k">Kleur:</span>
                    <span class="v">
                        @if($record?->getSubtype() === OrderSubtype::Unit)
                            <input
                                type="text"
                                wire:model="orderChairColor"
                                class="fi-input block w-full rounded-lg border border-gray-300 shadow-sm text-sm"
                                placeholder=""
                            />
                        @else
                            {{ $orderChairColor ?: '-' }}
                        @endif
                    </span>
                </li>
            </ul>
            <h4 class="block-title">Waarde</h4>
            <ul class="kv">
                <li>
                    <span class="k">Offerte:</span>
                    <span class="v">
                    @if ($quote && $quote->getUid() && $quote->status !== 'initial')
                            @money($record->quote->getCompanySalesPriceTotal())
                            <span class="value"> <span class="tax">excl. BTW</span>
                    </span>

                        @elseif ($quote && $quote->getUid() && $quote->status == 'draft')
                            <span class="v">
                        <button
                            type="button"
                            class="underline !bg-transparent !p-0 !border-0 cursor-pointer text-left font-semibold"
                            x-data
                            x-on:click="$dispatch('open-modal', { id: 'quote-preview', title: 'Conceptofferte' })"
                        >Conceptofferte</button>
                        / {{ $recordQuoteDraft->getCreatedAt()?->format('d-m-Y') }}
                    </span>
                        @else
                            <span class="v">-</span>
                    @endif
                </li>
                @php
                    $firstOrder = $record->getFirstOrder();
                    $lastOrder = $record->getLastOrder();
                @endphp
                <li>
                    <span class="k">Order | initiëel:</span>
                    @if ($firstOrder)
                        <span class="v">
                            @money($firstOrder->getCompanySalesPriceTotal())
                            <span class="value"> <span class="tax">excl. BTW</span>
                                <span
                                    class="date">| &nbsp;{{ $firstOrder->getSentAt()?->format('d-m-Y') ?? $firstOrder->getOrderDate()->format('d-m-Y') }}</span>
                        </span>

                            @else
                                <span class="v">-</span>
                    @endif
                </li>

                <li>
                    <span class="k">Order | actueel:</span>
                    <span class="v">
                              @if ($lastOrder)
                            @money($lastOrder->getCompanySalesPriceTotal())
                            <span class="value"> <span class="tax">excl. BTW</span>
                                <span
                                    class="date">| &nbsp;{{ $lastOrder->getSentAt()?->format('d-m-Y') ?? $lastOrder->getOrderDate()->format('d-m-Y') }}</span>
                        </span>
                        @else
                            -
                        @endif

                        </span>

                </li>
            </ul>
        </div>
    </section>

    <section
        id="card-financial_docs"
        class="card"
        x-data="{
            isDragging: false,
            dragDepth: 0,
            handleDrop(e) {
                e.preventDefault();
                e.stopPropagation();
                this.isDragging = false;
                this.dragDepth = 0;
                const input = this.$el.querySelector('input[type=file].financial-docs-upload');
                if (!input || !e.dataTransfer.files.length) return;
                const dt = new DataTransfer();
                for (const file of e.dataTransfer.files) dt.items.add(file);
                input.files = dt.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            },
            handleDragenter(e) {
                e.preventDefault();
                e.stopPropagation();
                this.dragDepth += 1;
                this.isDragging = true;
            },
            handleDragover(e) {
                e.preventDefault();
                e.stopPropagation();
                this.isDragging = true;
            },
            handleDragleave(e) {
                e.preventDefault();
                e.stopPropagation();
                this.dragDepth = Math.max(0, this.dragDepth - 1);
                this.isDragging = this.dragDepth > 0;
            },
        }"
        x-on:dragenter="handleDragenter"
        x-on:dragover="handleDragover"
        x-on:dragleave="handleDragleave"
        x-on:drop="handleDrop"
        x-bind:class="{ 'border-primary-500': isDragging }"
    >
        <div class="docs-list">
            @if ($record)
                @livewire(OrderDocsTableWidget::class, ['record' => $record], 'order-docs-' . $record->getId())
            @endif
        </div>
    </section>
</main>
