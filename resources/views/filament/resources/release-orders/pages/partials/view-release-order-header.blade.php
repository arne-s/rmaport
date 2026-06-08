@php
    $record = $this->record;
@endphp
<div class="header">
    <div class="breadcrumb">
        <div class="backTo">
            <a href="{{ $this->getBackToUrl() }}">
                @svgImg('img/icons/chevron-left.svg')
                <span>Terug naar {{ $this->getBackToTitle() }}</span>
            </a>
        </div>
    </div>

    @if ($releaseOrderIsOrphaned ?? false)
        @include('filament.resources.release-orders.partials.orphaned-release-order-notice', [
            'backUrl' => $this->getBackToUrl(),
        ])
    @endif

    <div class="title-container">
        <div class="title-container__title-group">
            <h2 class="title">Afroepverzoek: {{ $record?->getReferenceNumber() }}</h2>

            @if (count($this->getReleaseOrderStatusDropdownOptions()) > 0)
                <div class="fi-input-wrp fi-fo-select fi-fo-select-native order-status-select-wrp">
                    <div class="fi-input-wrp-content-ctn">
                        <select
                            wire:model.live="releaseOrderStatus"
                            class="fi-select-input"
                            id="release-order-status-select"
                            aria-label="Afroepstatus"
                        >
                            @foreach ($this->getReleaseOrderStatusDropdownOptions() as $option)
                                <option
                                    value="{{ $option['value'] }}"
                                    @disabled(! $option['selectable'])
                                >{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <x-filament::tabs>
        <x-filament::tabs.item
            alpine-active="activeTab === 'release-order'"
            x-on:click="setReleaseOrderViewTab('release-order')"
        >
            Gegevens
        </x-filament::tabs.item>

        <x-filament::tabs.item
            alpine-active="activeTab === 'purchase'"
            x-on:click="setReleaseOrderViewTab('purchase')"
        >
            Artikelen
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>
