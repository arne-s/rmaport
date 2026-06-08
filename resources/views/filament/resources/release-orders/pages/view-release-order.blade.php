@php
    $activeTab = request()->query('tab', 'release-order');
    if (! in_array($activeTab, ['release-order', 'purchase'], true)) {
        $activeTab = 'release-order';
    }
@endphp
<x-filament-panels::page
    x-data="{
        activeTab: '{{ $activeTab }}',
        setReleaseOrderViewTab(tab) {
            if (! ['release-order', 'purchase'].includes(tab)) {
                return;
            }
            this.activeTab = tab;
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        },
    }"
>
    @include('filament.resources.release-orders.pages.partials.view-release-order-header')

    <div x-show="activeTab === 'release-order'">
        @include('filament.resources.release-orders.pages.release-order-tab')
    </div>

    <div x-show="activeTab === 'purchase'">
        @include('filament.resources.release-orders.pages.products-tab')
    </div>

    @include('livewire.modals.release-order-delivered-confirm-modal')
</x-filament-panels::page>
