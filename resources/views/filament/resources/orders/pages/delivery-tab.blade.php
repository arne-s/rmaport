@php
    use App\Models\Order\Main;

    /** @var Main $record */
@endphp

<main class="ordersTab deliveryTab">
    <section id="card-delivery-info" class="card">
        <h3 class="card__title">Levering</h3>

        <ul class="kv" style="align-content: flex-start">
            <li>
                <span class="k">Klant:</span>
                <span class="v">{{ $record->getCustomerAddressDisplayName() ?? '-' }}</span>
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

    <livewire:documents-block
        :owner-id="$record->getId()"
        :owner-class="get_class($record)"
        collection="delivery_documents"
        block-title="Documenten en Afbeeldingen"
        :accept-attribute-override="config('documents.accept_attribute') . ',' . config('documents.images_accept_attribute')"
        upload-zone-key="delivery-documents"
        section-id="card-delivery-documenten"
        :key="'documents-delivery-' . $record->getId()"
    />

    <section id="card-delivery-notes" class="card">
        <div class="card__header-row fi-ta-actions">
            <h3 class="card__title">Informatie levering</h3>
        </div>

        <div class="note fitting-passing-form mt-4">
            <div class="fitting-note-textareas-grid">
                <div class="fitting-note-fields-col">
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="deliveryNoteAttendees" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Aanwezigen</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="deliveryNoteAttendees" wire:model="deliveryNoteAttendees"
                                       maxlength="65535" class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                    @include('filament.resources.orders.pages.partials.advisor-dealer-fields', [
                        'tab' => 'delivery',
                        'idPrefix' => 'deliveryNote',
                    ])
                </div>
                <div class="fi-fo-field fi-fo-field-wrdp" style="margin-top: -25px">
                    <label for="deliveryNoteGeneralNotes" class="fi-fo-field-label-ctn">
                        <span class="fi-fo-field-label-content text-sm font-medium">Aantekeningen</span>
                    </label>
                    <div class="fi-input-wrp">
                        <textarea id="deliveryNoteGeneralNotes" wire:model="deliveryNoteGeneralNotes" rows="10"
                                  maxlength="65535" class="fi-input block w-full h-full" placeholder=""></textarea>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>
