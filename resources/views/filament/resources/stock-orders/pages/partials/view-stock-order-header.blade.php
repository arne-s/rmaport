@php
    $order = $this->record;
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

    <div class="title-container">
        <div class="title-container__title-group">
            <h2 class="title">Inkooporder: {{ $order?->getUidFormatted() }}</h2>

            @if (count($this->getPurchaseOrderStatusDropdownOptions()) > 0)
                <div class="fi-input-wrp fi-fo-select fi-fo-select-native order-status-select-wrp">
                    <div class="fi-input-wrp-content-ctn">
                        <select
                            wire:model.live="purchaseOrderStatus"
                            class="fi-select-input"
                            id="purchase-order-status-select"
                            aria-label="Inkooporderstatus"
                        >
                            @foreach ($this->getPurchaseOrderStatusDropdownOptions() as $option)
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
            alpine-active="activeTab === 'purchase-order'"
            x-on:click="setPurchaseOrderViewTab('purchase-order')"
        >
            Gegevens
        </x-filament::tabs.item>

        <x-filament::tabs.item
            alpine-active="activeTab === 'purchase'"
            x-on:click="setPurchaseOrderViewTab('purchase')"
        >
            Artikelen
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>
