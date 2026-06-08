@php
    $activeTab = request()->query('tab', 'purchase-order');
    if (! in_array($activeTab, ['purchase-order', 'purchase'], true)) {
        $activeTab = 'purchase-order';
    }
@endphp
<x-filament-panels::page>
    <div
        class="w-full"
        x-data="{
            activeTab: '{{ $activeTab }}',
            saving: false,
            setPurchaseOrderViewTab(tab) {
                if (! ['purchase-order', 'purchase'].includes(tab)) {
                    return;
                }
                this.activeTab = tab;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url);
            },
            async runFooterSave($wire) {
                this.saving = true;
                try {
                    await $wire.savePurchaseOrderDetails();
                } finally {
                    this.saving = false;
                }
            },
        }"
    >
        @include('filament.resources.purchase-orders.pages.partials.view-purchase-order-header')

        <div x-show="activeTab === 'purchase-order'">
            @include('filament.resources.purchase-orders.pages.purchase-order-tab')
        </div>

        <div x-show="activeTab === 'purchase'">
            @include('filament.resources.purchase-orders.pages.products-tab')
        </div>

        <div class="fi-sc-component">
            <div class="fi-sc-actions" id="content.form-actions">
                <div class="fi-ac fi-align-start">
                    <div class="editproduct-footer-actions">
                        <div>
                            <button
                                class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action inline-flex items-center justify-center gap-2"
                                type="button"
                                :disabled="saving"
                                x-on:click="runFooterSave($wire)"
                            >
                                <x-filament::loading-indicator
                                    class="fi-icon fi-size-md animate-spin"
                                    x-show="saving"
                                    x-cloak
                                />
                                <span x-show="!saving">Opslaan</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('livewire.modals.mts-purchase-order-delivered-confirm-modal')
    @include('livewire.modals.mto-purchase-order-delivered-confirm-modal')
</x-filament-panels::page>
