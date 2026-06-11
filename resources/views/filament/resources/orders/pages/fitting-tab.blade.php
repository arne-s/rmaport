@php
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Models\Order\Main;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    $fittingPreviousUnitCustomValue = ViewOrder::FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE;
    $showFittingPreviousUnitCustomFields = ($fittingNotePreviousUnit ?? '') === $fittingPreviousUnitCustomValue;

    /** @var Main $record */

    $displayName = $record->getCustomerAddressDisplayName() ?: '-';
@endphp

<main class="ordersTab fittingTab">
    <section id="card-fitting-info" class="card">
        <h3 class="card__title">Algemeen</h3>

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
            <h3 class="card__title">Informatie</h3>
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
            </div>
        </div>



    </section>


</main>
