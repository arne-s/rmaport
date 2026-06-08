@php
    $activeTab = request()->query('tab', 'purchase-order');
    if (! in_array($activeTab, ['purchase-order', 'purchase'], true)) {
        $activeTab = 'purchase-order';
    }
@endphp
<x-filament-panels::page
    x-data="{
        activeTab: '{{ $activeTab }}',
        setPurchaseOrderViewTab(tab) {
            if (! ['purchase-order', 'purchase'].includes(tab)) {
                return;
            }
            this.activeTab = tab;
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        },
    }"
>
    @include('filament.resources.stock-orders.pages.partials.view-stock-order-header')

    <div x-show="activeTab === 'purchase-order'">
        @include('filament.resources.stock-orders.pages.purchase-order-tab')
    </div>

    <div x-show="activeTab === 'purchase'">
        @include('filament.resources.stock-orders.pages.products-tab')
    </div>

    @include('livewire.modals.mts-purchase-order-delivered-confirm-modal')
    @include('livewire.modals.mts-order-product-delivered-confirm-modal')
    @include('livewire.modals.stock-order-cancel-confirm-modal')
</x-filament-panels::page>
