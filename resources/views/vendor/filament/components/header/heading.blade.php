<h1 {{ $attributes->class(['filament-header-heading text-2xl font-bold tracking-tight position-relative']) }}>
    {{ $slot }}

    @php
        if (isset($this->record) && isset($this->record->is_active)) {
            $active = $this->record->is_active;
        } elseif(isset($this->data) && isset($this->data['is_active'])) {
            $active = $this->data['is_active'];
        }

        $isSP = isset($this->record)
        && $this->record instanceof \App\Models\Customer
        && $this->record->getKey() === \App\Models\Customer::getRdMobilityCustomer()?->getKey();

    @endphp

    @if (isset($active) && !$isSP)
        <div class="dot-indicator {{ $active ? 'green' : 'orange' }}">
            <div class="dot">⬤</div>
            <label>
                @if(isset($this->record) && $this->record instanceof \App\Models\Customer)
                    {{ $active ? 'Actief' : 'On-hold' }}
                @else
                    {{ $active ? 'Gepubliceerd' : 'Concept' }}
                @endif
            </label>
        </div>
    @endif
</h1>

