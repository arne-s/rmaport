@php
    use App\Enums\OrderSubtype;
    use App\Models\Order\Main;
    use App\Models\Product;

    /** @var Main $record */

    $imageMimes = config('documents.openable_mime_types', []);
    $fittingNote = $record->getFittingNote() ?? [];
    $frameProduct = $record->getOrderForPurchase()?->frameProduct;
    $frameSupplier = $frameProduct?->supplier;
    $chairColorRaw = data_get($record->getAdditional(), 'chair_color')
        ?: data_get($frameProduct?->additional, 'chair_color');
    $assemblyChairColor = (is_string($chairColorRaw) && $chairColorRaw !== '') ? $chairColorRaw : '-';
    $chairTypeRaw = $frameProduct?->getChairType();
    $assemblyChairTypeLabel = Product::getFrameChairTypeLabel(is_string($chairTypeRaw) ? $chairTypeRaw : null);
    $advisorName = $record->advisor?->name ?? '-';
    $fittingAttendees = $fittingNote['attendees'] ?? '-';
    $bodyLength = $fittingNote['body_length'] ?? '-';
    $bodyWeight = $fittingNote['body_weight'] ?? '-';
    $fittingAt = '-';
    $deliveryWeek = null;
    $pickedProducts = $record->getPurchasedPickedProducts()?->get() ?? collect();
    $isServiceOrder = $record->getSubtype() === OrderSubtype::Service;
@endphp


<main class="ordersTab assembly-montage-tab">
    <div
        class="assembly-montage__grid grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-4 lg:items-stretch"
    >
        <div class="assembly-montage__left flex min-h-0 flex-col gap-4 lg:min-h-0">
            <section
                id="assembly-section-details"
                class="card shrink-0"
            >
                <h3 class="card__title">Details</h3>

                <ul class="kv">
                    <li>
                        <span class="k">Klantnaam:</span>
                        <span class="v">{{ $record->getCustomerAddressDisplayName() ?? '-' }}</span>
                    </li>
                </ul>

                <ul class="kv" style="margin-top: 12px;">
                    <li>
                        <span class="k">Type aanvraag:</span>
                        <span class="v">{{ $record->getSubtype()?->getLabel() ?? '-' }}</span>
                    </li>
                    <li>
                        <span class="k">Merk:</span>
                        <span class="v">{{ $frameSupplier?->getName() ?? $frameSupplier?->name ?? '-' }}</span>
                    </li>
                    <li>
                        <span class="k">Frame:</span>
                        <span class="v">
                                {{ $frameProduct?->getName() ?? '-' }}
                        </span>
                    </li>
                    <li>
                        <span class="k">Type:</span>
                        <span class="v">{{ $assemblyChairTypeLabel }}</span>
                    </li>
                    <li>
                        <span class="k">Kleur:</span>
                        <span class="v">{{ $assemblyChairColor }}</span>
                    </li>
                    <li style="margin-top: 12px">
                        <span class="k">Leverweek:</span>
                        <span class="v">{{ $deliveryWeek ? 'Week ' . $deliveryWeek : '-' }}</span>
                    </li>
                </ul>
            </section>

            <div class="assembly-montage__documents flex min-h-0 flex-1 flex-col">
                <livewire:documents-block
                    :owner-id="$record->id"
                    :owner-class="get_class($record)"
                    collection="assembly_documents"
                    :allowed-mime-types="$imageMimes"
                    upload-zone-key="assembly-documents"
                    section-id="assembly-section-images"
                    blockTitle="Afbeeldingen"
                    acceptAttributeOverride="image/jpeg,image/png,image/gif,image/webp,image/svg+xml,.jpg,.jpeg,.png,.gif,.webp,.svg"
                    :key="'assembly_documents-main-' . $record->id"
                />
            </div>
        </div>

        <section
            id="assembly-section-delivery-notes"
            class="card"
        >
            <h3 class="card__title">Checklist</h3>
            <div class="fitting-passing-form mt-4">
                <livewire:checklist-table
                    :owner-id="$record->getId()"
                    :owner-class="get_class($record)"
                    :default-items="$isServiceOrder ? ['Onderhoud uitgevoerd', 'Eindcontrole'] : null"
                />
            </div>
            <div class="note fitting-passing-form mt-4">
                <div class="fitting-note-fields-col">
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="checklistAxleSize" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Asmaat</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input
                                    id="checklistAxleSize"
                                    wire:model.defer="checklistAxleSize"
                                    type="text"
                                    maxlength="255"
                                    class="fi-input block w-full"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="checklistWeight" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Gewicht</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input
                                    id="checklistWeight"
                                    wire:model.defer="checklistWeight"
                                    type="text"
                                    maxlength="255"
                                    class="fi-input block w-full"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <label for="checklistComments" class="fi-fo-field-label-ctn">
                        <span class="fi-fo-field-label-content text-sm font-medium">Opmerkingen:</span>
                    </label>
                    <div class="fi-input-wrp mt-1">
                        <textarea
                            id="checklistComments"
                            wire:model.defer="checklistComments"
                            rows="5"
                            maxlength="65535"
                            class="fi-input block w-full checklist-comments-input"
                        ></textarea>
                    </div>
                </div>
            </div>
        </section>

        <section
            id="assembly-section-products"
            class="card assembly-montage__products-card flex h-full min-h-0 flex-col"
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
                    @forelse($pickedProducts as $orderProduct)
                        <tr>
                            <td>{{ (int) ($orderProduct->qty ?? 0) }}x</td>
                            <td>{{ $orderProduct->product?->uid ?? '-' }}</td>
                            <td>{{ $orderProduct->product?->name ?? $orderProduct->value ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="assembly-products-table__empty">Geen artikelen.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
