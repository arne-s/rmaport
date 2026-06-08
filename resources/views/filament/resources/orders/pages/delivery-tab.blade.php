@php
    use App\Enums\AppointmentType;
    use App\Models\Appointment;
    use App\Models\Order\Main;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    /** @var Main $record */
    /** @var Appointment $appointment */

    $deliveryOnHoldAppointmentId = $record->getDeliveryOnHoldAppointmentId();
@endphp

<main class="ordersTab deliveryTab">
    <section id="card-delivery-info" class="card">
        <h3 class="card__title">Levering</h3>

        <ul class="kv" style="align-content: flex-start">
            <li>
                <span class="k">Klant:</span>
                <span class="v">{{ $record->getCustomerAddressDisplayName() ?? '-' }}</span>
            </li>
            @php
                $activeDeliveryAppointment = $record->getActiveDeliveryAppointment();
                $deliveryLocationType = $activeDeliveryAppointment?->location_type;
                $deliveryLocationCustomer = $activeDeliveryAppointment?->locationCustomer;
                $deliveryLocationAddress = $deliveryLocationCustomer?->billingAddress;
                $deliveryLocationCustomJson = $activeDeliveryAppointment?->location_custom ? json_decode($activeDeliveryAppointment->location_custom, true) : null;
                $deliveryLocationName = $deliveryLocationCustomer?->id === $record->customer_id
                    ? $record->getCustomerAddressDisplayName()
                    : ($deliveryLocationCustomer?->getName() ?? ($deliveryLocationCustomJson['name'] ?? '-'));
            @endphp
            <li>
                <span class="k">Datum/Tijdstip:</span>
                <span class="v">
                    @if ($activeDeliveryAppointment?->datetime)
                        {{ $activeDeliveryAppointment->datetime->translatedFormat('d-m-Y - H:i') }} uur
                    @else
                        -
                    @endif
                </span>
            </li>
            <li>
                <span class="k">&nbsp;</span>
                <span class="v">&nbsp;</span>
            </li>
            <li>
                <span class="k">Locatienaam:</span>
                <span class="v">{{ $deliveryLocationName }}</span>
            </li>
            @if($deliveryLocationType === 'phone')
                <li>
                    <span class="k">Telefoonnummer:</span>
                    <span class="v">{{ $record->getCustomerContactPhone() ?: '-' }}</span>
                </li>
                <li>
                    <span class="k">Mobiel nummer:</span>
                    <span class="v">{{ $record->getCustomerContactMobile() ?: '-' }}</span>
                </li>
            @elseif($deliveryLocationAddress)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $deliveryLocationAddress->getAddressTemplate() ?: '-' }}</span>
                </li>
            @elseif($deliveryLocationCustomJson)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $deliveryLocationCustomJson['address'] ?? '-' }}</span>
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

    <section id="card-delivery-appointments" class="card">
        <div class="card__header-row fi-ta-actions shrink-0" style="margin-bottom: 18px">
            <h3 class="card__title">Afspraken</h3>
            @if (! $record->is_completed)
            <div class="card__header-action">
                <button
                    type="button"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                    wire:click="mountAction('newDeliveryAppointment')"
                    wire:loading.attr="disabled"
                >
                    <span
                        wire:loading
                        wire:target="mountAction('newDeliveryAppointment')"
                        class="inline-flex items-center fi-ac-btn-action-label"
                    >
                        <x-filament::loading-indicator class="fi-icon fi-size-md animate-spin ml-2" />
                    </span>
                    <span
                        wire:loading.remove
                        wire:target="mountAction('newDeliveryAppointment')"
                        class="new-appointment-btn-label fi-ac-btn-action-label"
                    >
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
                    <span class="doc__passing-datum">Levering-datum</span>
                    <span class="doc__tijdstip">Tijdstip</span>
                    <span class="doc__reden">Reden wijziging</span>
                    <span class="doc__acties">Acties</span>
                </div>
                @php
                    $appointments = $record->getAppointments(AppointmentType::Delivery) ?? collect();
                @endphp
                @forelse($appointments as $index => $appointment)
                    @php
                        $time = $appointment->getCustomerDatetimeStart() ?? $appointment->getDatetime();
                        $isActive = $appointment->is_active;
                        $showReplanButton = ! $isActive
                            && ! $record->is_completed
                            && $deliveryOnHoldAppointmentId !== null
                            && $appointment->getId() === $deliveryOnHoldAppointmentId;
                    @endphp

                    <div class="doc doc--appointment {{ $isActive ? 'doc--appointment-active' : '' }}" wire:key="delivery-appointment-{{ $appointment->getId() }}">
                        <span class="doc__aantal" style="font-size: 12px">{{ $appointments->count() - $index }}</span>
                        <div class="doc__left">
                            <button
                                type="button"
                                class="doc__name doc__name--underline doc__name--clickable"
                                wire:click="mountAction('viewDeliveryAppointment', { appointmentId: {{ $appointment->getId() }} })"
                            >
                                {{ $time?->translatedFormat('d-m-Y') }}
                            </button>
                        </div>
                        <span class="doc__meta"
                              style="font-size: 12px;">{{ $time?->translatedFormat('H:i') }}</span>
                        <span class="doc__meta doc__meta--comment"
                              style="font-size: 12px;">{{ $appointment->getComment() ?? '–' }}</span>
                        <span class="doc__acties doc__appointment-actions">
                            @if ($isActive && ! $record->is_completed)
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn"
                                    wire:click="mountAction('editDeliveryAppointment')"
                                >
                                    Wijzigen
                                </button>
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn doc__appointment-action-btn--danger"
                                    wire:click="mountAction('cancelDeliveryAppointment')"
                                >
                                    Annuleren
                                </button>
                            @elseif ($showReplanButton)
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn"
                                    wire:click="mountAction('editDeliveryAppointment', { appointmentId: {{ $appointment->getId() }} })"
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
