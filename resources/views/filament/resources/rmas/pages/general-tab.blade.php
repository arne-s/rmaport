@php
    use App\Enums\RmaAssessment;
    use App\Filament\Resources\OrderResource\Widgets\OrderDocsTableWidget;
    use App\Filament\Resources\RmaResource\Support\RmaViewPresenter;
    use App\Models\Rma;

    /** @var Rma $record */
    $generalPrimaryFields = RmaViewPresenter::generalPrimaryFields($record);
    $generalDetailFields = RmaViewPresenter::generalDetailFields($record);
    $productFields = RmaViewPresenter::productFields($record);
    $returnReadOnlyFields = RmaViewPresenter::returnReadOnlyFields($record);
    $relatedMain = $this->getRelatedMain();
@endphp

<main class="rmasTab">
    <div class="rmasTab__top-row">
        <div class="rmasTab__retour-row">
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

            <hr class="rmasTab__kv-divider" aria-hidden="true">

            <ul class="kv">
                <li>
                    <span class="k">Beoordeling:</span>
                    <span class="v">
                        <select id="rma-assessment" wire:model="assessment" class="fi-select rmasTab__assessment-select">
                            <option value="">—</option>
                            @foreach (RmaAssessment::labels() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </span>
                </li>
            </ul>
        </section>
        </div>

        <section
            id="card-rma-financial_docs"
            class="card rmasTab__financial-docs"
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
                @livewire(
                    OrderDocsTableWidget::class,
                    [
                        'record' => $relatedMain,
                        'showRmaPlaceholderButtons' => true,
                    ],
                    key('rma-financial-docs-' . $record->getKey() . '-' . ($relatedMain?->getId() ?? 'none'))
                )
            </div>
        </section>
    </div>

    <div class="rmasTab__columns">
        <section class="card rmasTab__card">
            <h3 class="card__title">Algemeen</h3>
            <ul class="kv">
                @foreach ($generalPrimaryFields as $field)
                    <li>
                        <span class="k">{{ $field['label'] }}:</span>
                        <span class="v">{{ $field['value'] }}</span>
                    </li>
                @endforeach
            </ul>

            <hr class="rmasTab__kv-divider" aria-hidden="true">

            <ul class="kv">
                @foreach ($generalDetailFields as $field)
                    <li>
                        <span class="k">{{ $field['label'] }}:</span>
                        <span class="v">{{ $field['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card rmasTab__card">
            <h3 class="card__title">Artikel</h3>
            <ul class="kv">
                @foreach ($productFields as $field)
                    <li>
                        <span class="k">{{ $field['label'] }}:</span>
                        <span class="v">{{ $field['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card rmasTab__card rmasTab__internal-notes">
            <h3 class="card__title">Interne notities</h3>
            <div class="rmasTab__internal-notes-field fi-input-wrp">
                <textarea
                    id="rma-internal-notes"
                    wire:model="internalNotes"
                    rows="9"
                    aria-label="Interne notities"
                    class="fi-input block w-full"
                ></textarea>
            </div>
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
</main>
