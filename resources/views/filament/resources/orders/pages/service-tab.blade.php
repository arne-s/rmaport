@php
    use App\Enums\AppointmentType;
    use App\Models\Order\Main;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    /** @var Main $record */

    $pickedProducts = $record->getPurchasedPickedProducts()?->get() ?? collect();

    $displayName = $record->getCustomerAddressDisplayName() ?: '-';
    $displayPhone = $record->getCustomerContactPhone() ?: '-';
    $displayMobile = $record->getCustomerContactMobile() ?: '-';
    $activeServiceAppointment = $record->getActiveServiceAppointment();
    $assemblyOnHoldAppointmentId = $record->getAssemblyOnHoldAppointmentId();
@endphp

<main class="ordersTab serviceTab">
    <section id="card-service-info" class="card">
        <h3 class="card__title">Onderhoud</h3>

        <ul class="kv" style="align-content: flex-start">
            <li>
                <span class="k">Klant:</span>
                <span class="v">{{ $displayName }}</span>
            </li>
            @php
                $serviceLocationType = $activeServiceAppointment?->location_type;
                $serviceLocationCustomer = $activeServiceAppointment?->locationCustomer;
                $serviceLocationAddress = $serviceLocationCustomer?->billingAddress;
                $serviceLocationCustomJson = $activeServiceAppointment?->location_custom ? json_decode($activeServiceAppointment->location_custom, true) : null;
            @endphp
            <li>
                <span class="k">Datum/Tijdstip:</span>
                <span class="v">
                    @if ($activeServiceAppointment?->datetime)
                        {{ $activeServiceAppointment->datetime->translatedFormat('d-m-Y - H:i') }} uur
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
                <span class="v">{{ $serviceLocationCustomer?->getName() ?? ($serviceLocationCustomJson['name'] ?? '-') }}</span>
            </li>
            @if($serviceLocationType === 'phone')
                <li>
                    <span class="k">Telefoonnummer:</span>
                    <span class="v">{{ $displayPhone }}</span>
                </li>
                <li>
                    <span class="k">Mobiel nummer:</span>
                    <span class="v">{{ $displayMobile }}</span>
                </li>
            @elseif($serviceLocationAddress)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $serviceLocationAddress->getAddressTemplate() ?: '-' }}</span>
                </li>
            @elseif($serviceLocationCustomJson)
                <li>
                    <span class="k">Adres:</span>
                    <span class="v">{{ $serviceLocationCustomJson['address'] ?? '-' }}</span>
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

    <section id="card-service-afspraken" class="card">
        <div class="card__header-row fi-ta-actions" style="margin-bottom: 18px">
            <h3 class="card__title">Afspraken</h3>
            @if (! $record->is_completed)
            <div class="card__header-action">
                <button
                    type="button"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                    wire:click="mountAction('newServiceAppointment')"
                    wire:loading.attr="disabled"
                >
                    <span
                        wire:loading
                        wire:target="mountAction('newServiceAppointment')"
                        class="inline-flex items-center fi-ac-btn-action-label"
                    >
                        <x-filament::loading-indicator class="fi-icon fi-size-md animate-spin ml-2" />
                    </span>
                    <span
                        wire:loading.remove
                        wire:target="mountAction('newServiceAppointment')"
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
                    <span class="doc__passing-datum">Onderhoud-datum</span>
                    <span class="doc__tijdstip">Tijdstip</span>
                    <span class="doc__reden">Reden wijziging</span>
                    <span class="doc__acties">Acties</span>
                </div>
                @php
                    $appointments = $record->getAppointments(AppointmentType::Service) ?? [];
                @endphp
                @forelse($appointments as $index => $appointment)
                    @php
                        $time = $appointment->getCustomerDatetimeStart() ?? $appointment->getDatetime();
                        $isActive = $appointment->is_active;
                        $showReplanButton = ! $isActive
                            && ! $record->is_completed
                            && $assemblyOnHoldAppointmentId !== null
                            && $appointment->getId() === $assemblyOnHoldAppointmentId;
                    @endphp

                    <div class="doc doc--appointment {{ $isActive ? 'doc--appointment-active' : '' }}" wire:key="service-appointment-{{ $appointment->getId() }}">
                        <span class="doc__aantal" style="font-size: 12px">{{ $appointments->count() - $index }}</span>
                        <div class="doc__left">
                            <button
                                type="button"
                                class="doc__name doc__name--underline doc__name--clickable"
                                wire:click="mountAction('viewServiceAppointment', { appointmentId: {{ $appointment->getId() }} })"
                            >
                                {{ $time?->translatedFormat('d-m-Y') }}
                            </button>
                        </div>
                        <span class="doc__meta" style="font-size: 12px;">{{ $time?->translatedFormat('H:i') }}</span>
                        <span class="doc__meta doc__meta--comment" style="font-size: 12px;">{{ $appointment->getComment() ?? '–' }}</span>
                        <span class="doc__acties doc__appointment-actions">
                            @if ($isActive && ! $record->is_completed)
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn"
                                    wire:click="mountAction('editServiceAppointment')"
                                >
                                    Wijzigen
                                </button>
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn doc__appointment-action-btn--danger"
                                    wire:click="mountAction('cancelServiceAppointment')"
                                >
                                    Annuleren
                                </button>
                            @elseif ($showReplanButton)
                                <button
                                    type="button"
                                    class="doc__appointment-action-btn"
                                    wire:click="mountAction('editServiceAppointment', { appointmentId: {{ $appointment->getId() }} })"
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
