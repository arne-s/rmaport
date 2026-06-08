@php
    use App\Enums\AppointmentType;
    use App\Enums\OrderStatus;
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Models\Appointment;
    use App\Models\Order\Main;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    $fittingPreviousUnitCustomValue = ViewOrder::FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE;
    $showFittingPreviousUnitCustomFields = ($fittingNotePreviousUnit ?? '') === $fittingPreviousUnitCustomValue;

    /** @var Main $record */
    /** @var Appointment $appointment */

    $displayName = $record->getCustomerAddressDisplayName() ?: '-';
    $displayPhone = $record->getCustomerContactPhone() ?: '-';
    $displayMobile = $record->getCustomerContactMobile() ?: '-';
    $activeFittingAppointment = $record->getActiveFittingAppointment();
    $appointments = $record->getAppointments(AppointmentType::Fitting) ?? collect();
    $showNewAppointmentButton = $appointments->isEmpty() && ! $record->is_completed;
    $fittingOnHoldAppointmentId = $record->getFittingOnHoldAppointmentId();
    $canModifyFittingAppointment = $record->canModifyFittingAppointment();
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
                <span class="k">Datum/Tijdstip: </span>
                <span class="v">
                        @if($activeFittingAppointment?->datetime)
                        {{ $activeFittingAppointment->datetime->translatedFormat('d-m-Y - H:i') }} uur
                    @else
                        -
                    @endif
                    </span>
            </li>
            <li>
                <span class="k">&nbsp;</span>
                <span class="v">&nbsp;</span>
            </li>


            @php
                $fittingLocationType = $activeFittingAppointment?->location_type;
                $fittingLocationCustomer = $activeFittingAppointment?->locationCustomer;
                $fittingLocationAddress = $fittingLocationCustomer?->billingAddress;
                $fittingLocationCustomJson = $activeFittingAppointment?->location_custom ? json_decode($activeFittingAppointment->location_custom, true) : null;
                $fittingLocationName = $fittingLocationCustomer?->id === $record->customer_id
                    ? $record->getCustomerAddressDisplayName()
                    : ($fittingLocationCustomer?->getName() ?? ($fittingLocationCustomJson['name'] ?? '-'));
            @endphp
            <li>
                <span class="k">Locatienaam:</span>
                <span class="v">{{ $fittingLocationName }}</span>
            </li>
            @if($fittingLocationType === 'phone')
                <li>
                    <span class="k">Telefoonnummer:</span>
                    <span class="v">{{ $displayPhone }}</span>
                </li>
                <li>
                    <span class="k">Mobiel nummer:</span>
                    <span class="v">{{ $displayMobile }}</span>
                </li>
            @elseif($fittingLocationCustomer?->id === $record->customer_id)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $record->getCustomerAddress()?->getAddressTemplate() ?: '-' }}</span>
                </li>
            @elseif($fittingLocationAddress)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $fittingLocationAddress->getAddressTemplate() ?: '-' }}</span>
                </li>
            @elseif($fittingLocationCustomJson)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $fittingLocationCustomJson['address'] ?? '-' }}</span>
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

    <section id="card-fitting-afspraken" class="card">
        <div class="card__header-row fi-ta-actions" style="margin-bottom: 18px">
            <h3 class="card__title">Afspraken</h3>
            @if ($showNewAppointmentButton)
                <div class="card__header-action">
                    <button
                        type="button"
                        class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                        wire:click="mountAction('newAppointment')"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading wire:target="mountAction('newAppointment')" class="inline-flex items-center fi-ac-btn-action-label">
                            <x-filament::loading-indicator class="fi-icon fi-size-md animate-spin ml-2" />
                        </span>
                        <span wire:loading.remove wire:target="mountAction('newAppointment')"
                              class="new-appointment-btn-label fi-ac-btn-action-label">
                            <span class="new-appointment-btn-label__icon">
                                {{ generate_icon_html(Heroicon::PlusCircle, size: IconSize::Medium) }}
                            </span>
                            <span class="new-appointment-btn-label__text">Nieuwe afspraak</span>
                        </span>
                    </button>
                </div>
            @endif
        </div>
        <div class="docs-list docs-list--afspraken">
            <div class="docs-list-inner" style="margin-top: -10px">
                <div class="doc doc--appointment doc--header">
                    <span class="doc__aantal">Aantal</span>
                    <span class="doc__passing-datum">Passing-datum</span>
                    <span class="doc__tijdstip">Tijdstip</span>
                    <span class="doc__reden">Reden wijziging</span>
                    <span class="doc__acties">Acties</span>
                </div>
                @forelse($appointments as $index => $appointment)
                    @php
                    $time = $appointment->getCustomerDatetimeStart() ?? $appointment->getDatetime();
                    $isActive = $appointment->is_active;
                    $showReplanButton = ! $isActive
                        && ! $record->is_completed
                        && $fittingOnHoldAppointmentId !== null
                        && $appointment->getId() === $fittingOnHoldAppointmentId;
                    @endphp

                    <div class="doc doc--appointment {{ $isActive ? 'doc--appointment-active' : '' }}" wire:key="fitting-appointment-{{ $appointment->getId() }}">
                        <span class="doc__aantal" style="font-size: 12px">{{ $appointments->count()-$index }}</span>
                        <div class="doc__left">
                            <button
                                type="button"
                                class="doc__name doc__name--underline doc__name--clickable"
                                wire:click="mountAction('viewFittingAppointment', { appointmentId: {{ $appointment->getId() }} })"
                            >
                                {{ $time?->translatedFormat('d-m-Y') }}
                            </button>
                        </div>
                        <span class="doc__meta"
                              style="font-size: 12px;">{{ $time?->translatedFormat('H:i') }}</span>
                        <span class="doc__meta doc__meta--comment"
                              style="font-size: 12px;">{{ $appointment->getComment() ?? '–' }}</span>
                        <span class="doc__acties doc__appointment-actions">
                            @if ($isActive && ! $record->is_completed && $canModifyFittingAppointment)
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn"
                                    wire:click="mountAction('editFittingAppointment')"
                                >
                                    Wijzigen
                                </button>
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn doc__appointment-action-btn--danger"
                                    wire:click="mountAction('cancelFittingAppointment')"
                                >
                                    Annuleren
                                </button>
                            @elseif ($showReplanButton && $canModifyFittingAppointment)
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn"
                                    wire:click="mountAction('editFittingAppointment', { appointmentId: {{ $appointment->getId() }} })"
                                >
                                    Opnieuw inplannen
                                </button>
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="muted text-sm text-center pt-4">Geen afspraken.</p>
                @endforelse
            </div>
        </div>
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
                                    @foreach($serialNumberOptions ?? [] as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
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
