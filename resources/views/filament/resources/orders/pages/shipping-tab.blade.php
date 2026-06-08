@php
    use App\Models\Order\Main;

    /** @var Main $record */
@endphp

<main class="ordersTab shippingTab">
    <section id="card-shipping-info" class="card">
        <h3 class="card__title">Verzending</h3>

        <ul class="kv" style="align-content: flex-start">
            <li>
                <span class="k">Klant:</span>
                <span class="v">{{ $record->getCustomerAddressDisplayName() ?? '-' }}</span>
            </li>
            @php
                $shippingCustomer = $record->shippingCustomer ?? $record->customer;
                $shippingAddr = $shippingCustomer?->getPhysicalDeliveryAddress();
            @endphp
            <li>
                <span class="k">Naam:</span>
                <span class="v">{{ $shippingCustomer?->getName() ?? '-' }}</span>
            </li>
            @if($shippingAddr)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $shippingAddr->getAddressTemplate() ?: '-' }}</span>
                </li>
            @else
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">-</span>
                </li>
            @endif
            <li>
                <span class="k">&nbsp;</span>
                <span class="v">&nbsp;</span>
            </li>
            <li>
                <span class="k">Adviseur:</span>
                <span class="v">{{ $record?->advisor?->name ?? '-' }}</span>
            </li>
            @if($record?->billingCustomer)
            <li>
                <span class="k">Factuurgegevens:</span>
                <span class="v">{{ $record->billingCustomer->getName() }}</span>
            </li>
            @endif
        </ul>
    </section>

    <section id="card-shipping-notes" class="card" style="display: flex; flex-direction: column;">
        <div class="card__header-row fi-ta-actions">
            <h3 class="card__title">Aantekeningen</h3>
        </div>
        <div class="fi-input-wrp" style="flex: 1; display: flex; flex-direction: column; margin-top: 8px; box-shadow: none;">
            <textarea
                id="shippingNotesTextarea"
                wire:model="shippingNotes"
                rows="10"
                maxlength="65535"
                class="fi-input block w-full h-full"
                placeholder=""
                style="flex: 1; resize: none;"
            ></textarea>
        </div>
    </section>

    <livewire:documents-block
        :owner-id="$record->getId()"
        :owner-class="get_class($record)"
        collection="delivery_documents"
        block-title="Documenten en Afbeeldingen"
        :accept-attribute-override="config('documents.accept_attribute') . ',' . config('documents.images_accept_attribute')"
        upload-zone-key="shipping-documents"
        section-id="card-shipping-documenten"
        :key="'documents-shipping-' . $record->getId()"
    />
</main>
