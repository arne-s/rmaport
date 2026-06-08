@php
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Models\Order\Main;

    $fittingPreviousUnitCustomValue = ViewOrder::FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE;
    $showFittingPreviousUnitCustomFields = ($fittingNotePreviousUnit ?? '') === $fittingPreviousUnitCustomValue;

    /** @var Main $record */

    $displayName = $record->getCustomerAddressDisplayName() ?: '-';
@endphp

<main class="ordersTab fittingTab">
    <section id="card-fitting-info" class="card">
        <h3 class="card__title">Passing</h3>

        <ul class="kv" style="align-content: flex-start">
            <li>
                <span class="k">Klant:</span>
                <span class="v">{{ $displayName }}</span>
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
        collection="fitting_documents"
        block-title="Documenten en Afbeeldingen"
        upload-zone-key="fitting"
        section-id="card-fitting-documenten"
        :key="'documents-fitting-' . $record->getId()"
    />

    <section id="card-fitting-notes" class="card">
        <div class="card__header-row fi-ta-actions">
            <h3 class="card__title">Informatie passing</h3>
        </div>

        <div class="note fitting-passing-form mt-4">
            <div class="fitting-note-textareas-grid">
                <div class="fitting-note-fields-col">
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNoteAttendees" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Aanwezigen</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="fittingNoteAttendees" wire:model="fittingNoteAttendees"
                                       maxlength="65535" class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                    @include('filament.resources.orders.pages.partials.advisor-dealer-fields', [
                        'tab' => 'fitting',
                        'idPrefix' => 'fittingNote',
                        'emailRowStyle' => 'margin-bottom: 25px',
                    ])
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNoteBirthDate" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Geboortedatum</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="date" id="fittingNoteBirthDate" wire:model="fittingNoteBirthDate"
                                       class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNoteBodyLength" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Lichaamslengte</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="fittingNoteBodyLength" wire:model="fittingNoteBodyLength"
                                       maxlength="255" class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNoteBodyWeight" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Lichaamsgewicht</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="fittingNoteBodyWeight" wire:model="fittingNoteBodyWeight"
                                       maxlength="255" class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                    <div class="fitting-note-field-row" style="margin-bottom: 25px">
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNoteHandicap" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Handicap</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="fittingNoteHandicap" wire:model="fittingNoteHandicap"
                                       maxlength="65535" class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                    <div class="fitting-note-field-row">
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNotePreviousUnit" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Merk/type oude stoel</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <select id="fittingNotePreviousUnit" wire:model.live="fittingNotePreviousUnit"
                                        class="fi-input block w-full">
                                    <option value="">Selecteer</option>
                                    <option value="{{ $fittingPreviousUnitCustomValue }}">
                                        Zelf ingeven
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div
                        class="fitting-note-field-row"
                        wire:key="fitting-note-previous-unit-custom"
                        x-show="$wire.fittingNotePreviousUnit === @js($fittingPreviousUnitCustomValue)"
                        @unless($showFittingPreviousUnitCustomFields) style="display: none;" @endunless
                    >
                        <div class="fitting-note-field-label-col" style="visibility: hidden">
                            <label for="fittingNotePreviousUnitCustom" class="fi-fo-field-label-ctn">
                                <span class="fi-fo-field-label-content text-sm font-medium">Eigen invoer</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="fittingNotePreviousUnitCustom"
                                       wire:model="fittingNotePreviousUnitCustom" class="fi-input block w-full"
                                       placeholder="Merk/type oude stoel">
                            </div>
                        </div>
                    </div>
                    <div
                        class="fitting-note-field-row"
                        wire:key="fitting-note-previous-unit-note"
                        x-show="$wire.fittingNotePreviousUnit === @js($fittingPreviousUnitCustomValue)"
                        style="{{ $showFittingPreviousUnitCustomFields ? 'margin-bottom: 25px' : 'display: none; margin-bottom: 25px' }}"
                    >
                        <div class="fitting-note-field-label-col">
                            <label for="fittingNotePreviousUnitNote" class="fi-fo-field-label-ctn">
                                <span
                                    class="fi-fo-field-label-content text-sm font-medium">Opmerkingen oude stoel</span>
                            </label>
                        </div>
                        <div class="fitting-note-field-content-col">
                            <div class="fi-input-wrp">
                                <input type="text" id="fittingNotePreviousUnitNote"
                                       wire:model="fittingNotePreviousUnitNote" maxlength="65535"
                                       class="fi-input block w-full">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fi-fo-field fi-fo-field-wrdp" style="margin-top: -25px">
                    <label for="fittingNoteGeneralNotes" class="fi-fo-field-label-ctn">
                        <span class="fi-fo-field-label-content text-sm font-medium">Aantekeningen</span>
                    </label>
                    <div class="fi-input-wrp">
                        <textarea id="fittingNoteGeneralNotes" wire:model="fittingNoteGeneralNotes" rows="10"
                                  maxlength="65535" class="fi-input block w-full h-full" placeholder=""></textarea>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <section
        id="card-fitting-measurements"
        class="card"
        x-data="{ measurementsReferenceOpen: false }"
    >
        <div class="card__header-row fi-ta-actions">
            <div class="fitting-measurements-title-group">
                <h3 class="card__title">Maten</h3>
                <button
                    type="button"
                    class="fitting-measurements-info-btn inline-flex shrink-0 items-center justify-center rounded-md text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                    x-on:click="measurementsReferenceOpen = true"
                    title="Referentie maten"
                    aria-label="Referentie-afbeelding maten tonen"
                >
                    {{ generate_icon_html(Heroicon::OutlinedInformationCircle, size: IconSize::Medium) }}
                </button>
            </div>
        </div>
        @if($record)
            <div class="fitting-passing-form mt-4">
                <livewire:fitting-measurement-table
                    :owner-id="$record->getId()"
                    :owner-class="get_class($record)"
                />
            </div>
        @endif

        <template x-teleport="body">
            <div
                x-show="measurementsReferenceOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fi-modal-window fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                role="dialog"
                aria-modal="true"
                aria-label="Referentie-afbeelding maten"
                x-on:keydown.escape.window="measurementsReferenceOpen = false"
                x-on:click="measurementsReferenceOpen = false"
            >
                <div
                    class="relative flex max-h-[90vh] w-full max-w-[760px] flex-col overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-800"
                    x-on:click.stop
                >
                    <button
                        type="button"
                        class="fi-icon-btn fi-size-md fitting-measurements-modal-close absolute end-2 top-2 z-10 rounded-lg bg-white/90 text-gray-500 hover:text-gray-700 dark:bg-gray-800/90 dark:text-gray-400 dark:hover:text-gray-200"
                        x-on:click="measurementsReferenceOpen = false"
                        aria-label="Sluiten"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                             aria-hidden="true">
                            <path
                                d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
                        </svg>
                    </button>
                    <div class="min-h-0 flex-1 overflow-auto p-4 pt-10">
                        <img
                            src="{{ asset('img/reference.webp') }}"
                            alt="Referentie maten"
                            class="mx-auto max-h-[min(80vh,900px)] w-auto max-w-full object-contain"
                            loading="lazy"
                        />
                    </div>
                </div>
            </div>
        </template>
    </section>


</main>
