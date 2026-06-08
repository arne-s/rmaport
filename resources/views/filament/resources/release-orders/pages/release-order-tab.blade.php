@php
    $record = $this->record;
    $dealer = $record?->dealer;
@endphp

<main @class(['ordersTab', 'inkoopTab', 'release-order-tab--no-dealer' => ! $dealer])>
    <section id="card-release-details" class="card">
        <h3 class="card__title">Details</h3>
        <ul class="kv">
            <li>
                <span class="k">Type:</span>
                <span class="v">Afroep</span>
            </li>
            @if ($record?->main_id)
                <li>
                    <span class="k">Aanvraag #:</span>
                    <span class="v">
                        <a href="{{ route('filament.app.resources.mains.view', $record->main_id) }}?tab=purchase" class="main-request-number-link hover:underline" target="_blank">
                            {{ $record->main?->getUidFormatted() }}
                        </a>
                    </span>
                </li>
            @endif
            <li>
                <span class="k">Datum:</span>
                <span class="v">{{ $record?->created_at?->format('d-m-Y') }}</span>
            </li>
            @if ($record?->sent_at)
                <li>
                    <span class="k">Verzonden op:</span>
                    <span class="v">{{ $record->sent_at->format('d-m-Y H:i') }}</span>
                </li>
            @endif
        </ul>
    </section>

    @if ($dealer)
        @php
            $fittingNote = $record?->main?->getFittingNote();
            $advisorDealerName = filled(trim((string) data_get($fittingNote, 'advisor_dealer_name', '')))
                ? trim((string) data_get($fittingNote, 'advisor_dealer_name'))
                : null;
            $advisorDealerEmail = filled(trim((string) data_get($fittingNote, 'advisor_dealer_email', '')))
                ? trim((string) data_get($fittingNote, 'advisor_dealer_email'))
                : null;
        @endphp
        <section id="card-dealer" class="card">
            <h3 class="card__title">Dealer</h3>
            <div class="tab-grid last">
                <ul class="kv">
                    <li>
                        <span class="k">Bedrijfsnaam:</span>
                        <span class="v">{{ $dealer->name }}</span>
                    </li>
                    <li style="margin-bottom: 12px">
                        <span class="k">E-mailadres:</span>
                        <span class="v">{{ $dealer->getEmail() ?? '-' }}</span>
                    </li>

                    <li>
                        <span class="k">Adviseur naam:</span>
                        <span class="v">{{ $advisorDealerName !== null ? e($advisorDealerName) : '-' }}</span>
                    </li>
                    <li>
                        <span class="k">Adviseur e-mailadres:</span>
                        <span class="v">{{ $advisorDealerEmail !== null ? e($advisorDealerEmail) : '-' }}</span>
                    </li>
                </ul>
            </div>
        </section>
    @endif

    @php
        use App\Models\Country;

        $additionalRo = $record?->getAdditional() ?? [];
        $deliveryAddressRo = is_array($additionalRo['delivery_address'] ?? null) ? $additionalRo['delivery_address'] : [];
        $deliveryNameRo = trim((string) ($additionalRo['shipping_name'] ?? ''));
        if ($deliveryNameRo === '') {
            if ($dealer) {
                $deliveryNameRo = $dealer->getName() ?? '';
            }
            if ($deliveryNameRo === '' && $record?->order?->customer) {
                $deliveryNameRo = $record->order?->customer?->getName() ?? '';
            }
        }
        $deliveryCountryRo = null;
        if (! empty($deliveryAddressRo['country_id'])) {
            $deliveryCountryRo = Country::find($deliveryAddressRo['country_id'])?->getName();
        }
    @endphp

    @if ($record)
        <livewire:documents-block
            :owner-id="$record->id"
            :owner-class="\App\Models\ReleaseOrder::class"
            collection="documents"
            :allowed-mime-types="config('documents.allowed_mime_types', [])"
            upload-zone-key="release-order-documents"
            section-id="card-docs"
            :key="'documents-release-order-' . $record->id"
        />
    @else
        <section id="card-docs" class="card">
            <h3 class="card__title">Documenten</h3>
        </section>
    @endif

    @include('filament.resources.release-orders.pages.partials.release-order-status-overview', [
        'record' => $record,
        'timeline' => $this->getReleaseOrderStatusTimeline(),
    ])
</main>
