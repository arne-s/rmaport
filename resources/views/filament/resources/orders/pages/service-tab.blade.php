@php
    use App\Models\Order\Main;

    /** @var Main $record */
@endphp

<main class="ordersTab serviceTab">
    <section id="card-service-info" class="card">
        <h3 class="card__title">Onderhoud</h3>

        <ul class="kv" style="align-content: flex-start">
            <li>
                <span class="k">Klant:</span>
                <span class="v">{{ $record->getCustomerAddressDisplayName() ?: '-' }}</span>
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

    <section
        id="card-service-artikelen"
        class="card service-tab__artikelen flex h-full min-h-0 flex-col"
    >
        <div class="card__header-row fi-ta-actions shrink-0">
            <h3 class="card__title">Artikelen</h3>
        </div>
        <div class="assembly-products-table-wrap min-h-0 flex-1 overflow-auto" style="max-height: 610px">
            <table class="assembly-products-table">
                <thead>
                <tr>
                    <th>Aantal</th>
                    <th>Artikelcode</th>
                    <th>Naam</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </section>

    <section id="card-service-notes" class="card">
        <div class="card__header-row fi-ta-actions">
            <h3 class="card__title">Omschrijving werkzaamheden</h3>
        </div>

        <div class="note fitting-passing-form mt-4">
            <div class="fi-fo-field fi-fo-field-wrdp w-full max-w-none">
                <div class="fi-input-wrp">
                    <textarea
                        id="serviceNoteGeneralNotes"
                        wire:model="serviceNoteGeneralNotes"
                        rows="14"
                        maxlength="65535"
                        class="fi-input block w-full min-h-[280px]"
                        placeholder=""
                    ></textarea>
                </div>
            </div>
        </div>
    </section>

    <livewire:documents-block
        :owner-id="$record->getId()"
        :owner-class="get_class($record)"
        collection="delivery_documents"
        block-title="Documenten en Afbeeldingen"
        :accept-attribute-override="config('documents.accept_attribute') . ',' . config('documents.images_accept_attribute')"
        upload-zone-key="delivery-documents"
        section-id="card-service-documenten"
        :key="'documents-delivery-' . $record->getId()"
    />
</main>
