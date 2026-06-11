@php
    use App\Filament\Resources\RmaResource\Support\RmaViewPresenter;
    use App\Models\Rma;

    /** @var Rma $record */
    $generalFields = RmaViewPresenter::generalFields($record);
    $productFields = RmaViewPresenter::productFields($record);
    $returnReadOnlyFields = RmaViewPresenter::returnReadOnlyFields($record);
@endphp

<main class="rmasTab">
    <div class="rmasTab__columns">
        <section class="card rmasTab__card">
            <h3 class="card__title">Algemeen</h3>
            <ul class="kv">
                @foreach ($generalFields as $field)
                    <li>
                        <span class="k">{{ $field['label'] }}:</span>
                        <span class="v">{{ $field['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card rmasTab__card">
            <h3 class="card__title">Product</h3>
            <ul class="kv">
                @foreach ($productFields as $field)
                    <li>
                        <span class="k">{{ $field['label'] }}:</span>
                        <span class="v">{{ $field['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="rmasTab__documents">
            <livewire:documents-block
                :owner-id="$record->getKey()"
                owner-class="{{ Rma::class }}"
                collection="rma_documents"
                block-title="Documenten en Afbeeldingen"
                upload-zone-key="rma"
                section-id="card-rma-documenten"
                :key="'documents-rma-'.$record->getKey()"
            />
        </div>
    </div>

    <section class="card rmasTab__retour">
        <h3 class="card__title">Retour</h3>
        <ul class="kv">
            @foreach ($returnReadOnlyFields as $field)
                <li>
                    <span class="k">{{ $field['label'] }}:</span>
                    <span class="v whitespace-pre-wrap">{{ $field['value'] }}</span>
                </li>
            @endforeach
        </ul>

        <div class="note fitting-passing-form rmasTab__editable-fields">
            <div class="fitting-note-textareas-grid">
                <div class="fi-fo-field fi-fo-field-wrdp">
                    <label for="rma-service" class="fi-fo-field-label-ctn">
                        <span class="fi-fo-field-label-content text-sm font-medium">Werkzaamheden</span>
                    </label>
                    <div class="fi-input-wrp">
                        <textarea
                            id="rma-service"
                            wire:model="service"
                            rows="9"
                            class="fi-input block w-full"
                        ></textarea>
                    </div>
                </div>

                <div class="fi-fo-field fi-fo-field-wrdp">
                    <label for="rma-internal-notes" class="fi-fo-field-label-ctn">
                        <span class="fi-fo-field-label-content text-sm font-medium">Interne notities</span>
                    </label>
                    <div class="fi-input-wrp">
                        <textarea
                            id="rma-internal-notes"
                            wire:model="internalNotes"
                            rows="9"
                            class="fi-input block w-full"
                        ></textarea>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
