@php
    use App\Enums\OrderStatus;
    use App\Filament\Resources\OrderResource\Pages\ViewOrder;
    use App\Filament\Resources\OrderResource\Widgets\MainNotesWidget;
    use App\Models\Order\Main;

    /** @var ViewOrder $this */
    /** @var Main $record */
    $record = $this->record;

    $orderStatusRaw = $record->order_status;
    $currentStatus = $orderStatusRaw instanceof OrderStatus ? $orderStatusRaw : ($orderStatusRaw !== null ? OrderStatus::tryFrom($orderStatusRaw) : null);

    $showAssemblyTab = OrderStatus::shouldShowOrderViewAssemblyTab($currentStatus);
    $showDeliveryTab = OrderStatus::shouldShowOrderViewDeliveryTab($currentStatus);
    $showProductsTab = OrderStatus::shouldShowOrderViewProductsTab($currentStatus);
    $isPart = $record->getSubtype() === \App\Enums\OrderSubtype::Part;
    $isServiceMain = $record->getSubtype() === \App\Enums\OrderSubtype::Service;
    $showShippingTab = $isPart && OrderStatus::shouldShowOrderViewShippingTab($currentStatus);
@endphp

{{-- @entangle must not live on <x-filament-panels::page>: Blade passes attrs as strings, so @entangle is never compiled and tabs stay empty. --}}
<x-filament-panels::page>
    {{-- Footer Save: nested Livewire tables own measurement/checklist state; call the child first so it dispatches to the parent, otherwise call save on the page directly. A plain parent wire:click cannot read child state without moving that data onto ViewOrder. --}}
    <div
        class="w-full order-view-unsaved-root"
        x-data="{
            activeTab: @entangle('orderViewTab').live,
            saving: false,
            childSyncByTab: {
                fitting: { root: '#fitting-measurement-table', method: 'emitFittingMeasurementsToParent' },
                assembly: { root: '#checklist-table', method: 'emitChecklistToParent' },
            },
            init() {
                const markMeasurementsDirty = (event) => {
                    if (! event.target?.closest?.('#fitting-measurement-table')) {
                        return;
                    }

                    this.markMeasurementTableDirty();
                };

                this.$el.addEventListener('input', markMeasurementsDirty, true);
                this.$el.addEventListener('change', markMeasurementsDirty, true);
                this.$el.addEventListener('click', (event) => {
                    if (event.target?.closest?.('#fitting-measurement-table button')) {
                        this.markMeasurementTableDirty();
                    }
                }, true);
            },
            markMeasurementTableDirty() {
                const mountedActions = $wire.mountedActions;
                if (Array.isArray(mountedActions) && mountedActions.length > 0) {
                    return;
                }

                document.dispatchEvent(new CustomEvent('order-view-measurement-dirty'));
                $wire.markOrderViewDirty();
            },
            markDirtyFromUserInput(event) {
                const mountedActions = $wire.mountedActions;
                if (Array.isArray(mountedActions) && mountedActions.length > 0) {
                    return;
                }
                const target = event?.target;
                if (! target || ! (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
                    return;
                }
                if (target.matches('[type=hidden],[type=file],[type=button],[type=submit],[type=reset],[readonly],[disabled]')) {
                    return;
                }
                if (target.matches('[type=checkbox]') && target.closest('.fi-ta')) {
                    return;
                }
                if (target.closest('#checklist-table, .fi-modal, .acp-grid-body, .fi-dropdown-panel, .order-status-select-wrp, #order-status-select, [wire\\:ignore]')) {
                    return;
                }
                $wire.markOrderViewDirty();
            },
            async runFooterSave($wire) {
                this.saving = true;
                try {
                    const spec = this.childSyncByTab[this.activeTab];
                    if (spec) {
                        const el = document.querySelector(spec.root);
                        const wireId = el?.getAttribute?.('wire:id');
                        const child = wireId && window.Livewire ? window.Livewire.find(wireId) : null;
                        if (child) {
                            await child.call(spec.method);
                        }
                    }
                    await $wire.call('saveOrderDetails', true);
                } finally {
                    this.saving = false;
                }
            },
        }"
        @input.debounce.400ms="markDirtyFromUserInput($event)"
        @change="markDirtyFromUserInput($event)"
    >
    {{-- Avoid display:contents here: Livewire morph can duplicate tab panels / break x-show. --}}
    <div
        @if ($this->shouldPollOrderStatus())
            wire:poll.10s="checkOrderStatusChanged"
        @endif
        class="w-full"
        wire:key="view-order-tab-region-{{ $currentStatus?->value ?? 'none' }}"
    >
        @include('filament.resources.orders.pages.partials.view-order-header')

        <div x-show="activeTab === 'order'" wire:key="order-tab-{{ $this->orderDocsVersion }}">
            @include('filament.resources.orders.pages.order-tab', [
                'record' => $record,
                'mainOrderDraft' => $record->draftOrder(),
                'quoteDraft' => $record->draftQuote(),
                'orderDocsVersion' => $this->orderDocsVersion
                ])
        </div>

        <div x-show="activeTab === 'fitting'">
            @include('filament.resources.orders.pages.fitting-tab', [
                'record' => $record,
            ])
        </div>

        <div x-show="activeTab === 'service'">
            @include('filament.resources.orders.pages.service-tab', [
                'record' => $record,
            ])
        </div>

        <div x-show="activeTab === 'notes'">
            <main class="notesTab">
                @livewire(MainNotesWidget::class, ['record' => $record], key('main-notes-' . $record->getId()))
            </main>
        </div>

        <div class="fi-sc-component">
            <div class="fi-sc-actions" id="content.form-actions">
                <div class="fi-ac fi-align-start">
                    <div class="editproduct-footer-actions">
                        <div>
                            @include('filament.resources.orders.pages.partials.view-order-save-button')
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($showProductsTab)
            <div x-show="activeTab === 'purchase'">
                @include('filament.resources.orders.pages.products-tab', ['record' => $record])
            </div>
        @endif

        @if ($showAssemblyTab && ! $isServiceMain)
            <div x-show="activeTab === 'assembly'">
                @include('filament.resources.orders.pages.assembly-tab', ['record' => $record])
            </div>
        @endif

        @if ($showDeliveryTab && ! $isServiceMain && ! $isPart)
            <div x-show="activeTab === 'delivery'">
                @include('filament.resources.orders.pages.delivery-tab', ['record' => $record])
            </div>
        @endif

        @if ($showShippingTab)
            <div x-show="activeTab === 'shipping'">
                @include('filament.resources.orders.pages.shipping-tab', ['record' => $record])
            </div>
        @endif

        @if (! $isServiceMain)
            <div x-show="activeTab === 'checklist'">
                @include('filament.resources.orders.pages.checklist-tab', ['record' => $record])
            </div>
        @endif
    </div>
    </div>

    <x-filament::modal class="openDocumentModal" id="quote-preview">
        <div
            class="contentContainer"
            x-data="{ isOpen: false, title: '' }"
            x-on:open-modal.window="if ($event.detail.id === 'quote-preview') { title = $event.detail.title ?? ''; isOpen = true }"
            x-on:close="isOpen = false"
            x-show="isOpen"
            x-cloak
        >
            <p class="text-lg" x-text="title"></p>
        </div>
    </x-filament::modal>

    <x-filament::modal class="openDocumentModal" id="order-preview">
        <div
            class="contentContainer"
            x-data="{ isOpen: false, title: '' }"
            x-on:open-modal.window="if ($event.detail.id === 'order-preview') { title = $event.detail.title ?? ''; isOpen = true }"
            x-on:close="isOpen = false"
            x-show="isOpen"
            x-cloak
        >
            <p class="text-lg" x-text="title"></p>
        </div>
    </x-filament::modal>

    <x-filament::modal class="openDocumentModal order-events-modal" id="order-events">
        <div
            class="contentContainer order-events-modal__content"
            x-data="{ isOpen: false, activeTab: 'orderstatus' }"
            x-on:open-modal.window="if ($event.detail.id === 'order-events') { isOpen = true; activeTab = $event.detail.tab || 'orderstatus' }"
            x-on:close="isOpen = false"
            x-show="isOpen"
            x-cloak
        >
            <div class="tabs order-events-modal__tabs" role="tablist" aria-label="Orderstatus, Historie">
                <button
                    class="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'orderstatus' ? 'true' : 'false'"
                    x-on:click="activeTab = 'orderstatus'"
                >
                    Orderstatus
                </button>
                <button
                    class="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'historie' ? 'true' : 'false'"
                    x-on:click="activeTab = 'historie'"
                >
                    Historie
                </button>
            </div>

            <div x-show="activeTab === 'orderstatus'" class="order-events-modal__panel">
                @include('filament.resources.orders.order-status-overview', [
                    'order' => $record,
                    'timeline' => $this->getOrderStatusTimeline(),
                ])
            </div>

            <div x-show="activeTab === 'historie'" class="order-events-modal__panel">
                @include('filament.resources.orders.partials.order-events-table', [
                    'orderEvents' => $this->getOrderEventsForHistory(),
                ])
            </div>
        </div>
    </x-filament::modal>

    @if($showPassingCompleteConfirm)
        <div
            class="status-confirm-modal fi-fo-field-wrp"
            role="dialog" aria-modal="true" aria-labelledby="passing-complete-confirm-title">
            <div
                class="fi-modal-window fi-modal-window-has-close-btn fi-modal-window-has-footer fi-modal-window-has-icon fi-align-center fi-width-md rounded-xl bg-white shadow-xl dark:bg-white/5 dark:shadow-none">
                <div class="fi-modal-header">
                    <button class="fi-icon-btn fi-size-md fi-modal-close-btn" title="Sluiten" aria-label="Sluiten"
                            type="button" wire:click="cancelPassingCompleteConfirm" tabindex="-1">
                        <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                    <div class="fi-modal-icon-ctn">
                        <div class="fi-modal-icon-bg fi-color fi-color-primary">
                            <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h2 id="passing-complete-confirm-title" class="fi-modal-heading">Statuswijziging</h2>
                        <p class="fi-modal-description">Passing afgerond. De status wordt gewijzigd naar "Offerte: Op te
                            stellen".</p>
                    </div>
                </div>
                <div class="fi-modal-footer fi-align-center">
                    <div class="fi-modal-footer-actions">
                        <x-filament::button color="gray" wire:click="cancelPassingCompleteConfirm">
                            Annuleren
                        </x-filament::button>
                        <x-filament::button color="success" wire:click="confirmPassingCompleteAndSave"
                                            class="status-confirm-modal__btn-success">
                            Bevestigen
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($showOrderApprovedConfirm)
        <div
            class="status-confirm-modal fi-fo-field-wrp"
            role="dialog" aria-modal="true" aria-labelledby="order-approved-confirm-title">
            <div
                class="fi-modal-window fi-modal-window-has-close-btn fi-modal-window-has-footer fi-modal-window-has-icon fi-align-center fi-width-md rounded-xl bg-white shadow-xl dark:bg-white/5 dark:shadow-none">
                <div class="fi-modal-header">
                    <button class="fi-icon-btn fi-size-md fi-modal-close-btn" title="Sluiten" aria-label="Sluiten"
                            type="button" wire:click="cancelOrderApprovedConfirm" tabindex="-1">
                        <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                    <div class="fi-modal-icon-ctn">
                        <div class="fi-modal-icon-bg fi-color fi-color-primary">
                            <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h2 id="order-approved-confirm-title" class="fi-modal-heading">Statuswijziging</h2>
                        <p class="fi-modal-description">De status gaat naar Inkoop. <br/><br/> Heb je de order nagelopen, alle laatste tekeningen en aanverwante documenten geupload in de artikelen/bucket-tab?</p>
                    </div>
                </div>
                <div class="fi-modal-footer fi-align-center">
                    <div class="fi-modal-footer-actions">
                        <x-filament::button color="gray" wire:click="cancelOrderApprovedConfirm">
                            Annuleren
                        </x-filament::button>
                        <x-filament::button color="success" wire:click="confirmOrderApprovedAndSave"
                                            class="status-confirm-modal__btn-success">
                            Bevestigen
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($showFittingCancelledConfirm)
        <div
            class="status-confirm-modal fi-fo-field-wrp"
            role="dialog" aria-modal="true" aria-labelledby="fitting-cancelled-confirm-title">
            <div
                class="fi-modal-window fi-modal-window-has-close-btn fi-modal-window-has-content fi-modal-window-has-footer fi-modal-window-has-icon fi-align-center fi-width-md modalForm rounded-xl bg-white shadow-xl dark:bg-white/5 dark:shadow-none">
                <div class="fi-modal-header">
                    <button class="fi-icon-btn fi-size-md fi-modal-close-btn" title="Sluiten" aria-label="Sluiten"
                            type="button" wire:click="cancelFittingCancelledConfirm" tabindex="-1">
                        <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                    <div class="fi-modal-icon-ctn">
                        <div class="fi-modal-icon-bg fi-color fi-color-primary">
                            <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h2 id="fitting-cancelled-confirm-title" class="fi-modal-heading">Aanvraag annuleren</h2>
                        <p class="fi-modal-description"></p>
                        <div style="margin-top: .5rem; font-size: 13px; font-weight: 450; color: #000;">
                            De passing wordt geannuleerd. De aanvraag gaat naar status Geannuleerd. De klant en de
                            dealer (indien van toepassing) worden geïnformeerd.
                        </div>
                    </div>
                </div>
                <div class="fi-modal-content">
                    <div class="fi-sc fi-sc-has-gap fi-grid" style="--cols-default: repeat(1, minmax(0, 1fr));">
                        <div class="fi-grid-col w-full" style="--col-span-default: span 1 / span 1;">
                            <div class="fi-fo-field w-full">
                                <div class="fi-fo-field-label-col">
                                    <div class="fi-fo-field-label-ctn">
                                        <label for="fittingCancelledReason" class="fi-fo-field-label">
                                            <span class="fi-fo-field-label-content">Reden van annulering<sup
                                                    class="fi-fo-field-label-required-mark">*</sup></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="fi-fo-field-content-col w-full">
                                    <div class="fi-input-wrp fi-fo-text-input w-full" style="margin-bottom: 25px;">
                                        <div class="fi-input-wrp-content-ctn w-full">
                                            <input
                                                id="fittingCancelledReason"
                                                type="text"
                                                wire:model="fittingCancelledReason"
                                                maxlength="255"
                                                required
                                                class="fi-input block w-full"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fi-modal-footer fi-align-center">
                    <div class="fi-modal-footer-actions">
                        <button type="button" class="fi-btn fi-size-md fi-ac-btn-action white"
                                wire:click="cancelFittingCancelledConfirm">
                            Annuleren
                        </button>
                        <button type="button"
                                class="fi-btn fi-size-md fi-ac-btn-action fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-700 fi-text-color-950 hover:fi-text-color-800 dark:fi-text-color-0 dark:hover:fi-text-color-0"
                                wire:click="confirmFittingCancelledAndSave">
                            Bevestigen
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showPickCompleteReadyForAssemblyModal)
        @php
            $pickCompleteIsSimplifiedDelivery = $record instanceof Main && $record->usesUnitSimplifiedSalesFlow();
            $pickCompleteIsPartShipping = $record instanceof Main && $record->getSubtype() === \App\Enums\OrderSubtype::Part;
            $pickCompleteDeliveryLabel = OrderStatus::getCategory(OrderStatus::ReadyForPickup) . ': ' . (OrderStatus::ReadyForPickup->getLabel() ?? '');
        @endphp
        <div
            class="status-confirm-modal fi-fo-field-wrp"
            role="dialog" aria-modal="true" aria-labelledby="pick-complete-ready-assembly-title">
            <div
                class="fi-modal-window fi-modal-window-has-close-btn fi-modal-window-has-footer fi-modal-window-has-icon fi-align-center fi-width-md rounded-xl bg-white shadow-xl dark:bg-white/5 dark:shadow-none">
                <div class="fi-modal-header">
                    <div class="fi-modal-icon-ctn">
                        <div class="fi-modal-icon-bg fi-color fi-color-primary">
                            <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h2 id="pick-complete-ready-assembly-title" class="fi-modal-heading">Status gewijzigd</h2>
                        <p class="fi-modal-description">
                            @if ($pickCompleteIsSimplifiedDelivery)
                                Alles is gepickt. Zorg dat alles in het kratje zit voor de levering.
                            @elseif ($pickCompleteIsPartShipping)
                                Alles is gepickt. Klaar voor verzending
                            @else
                                Alles is gepickt. Zorg dat alles in het kratje zit voor de montage.
                            @endif
                        </p>
                    </div>
                </div>
                <div class="fi-modal-footer fi-align-center">
                    <div class="fi-modal-footer-actions">
                        <x-filament::button color="success" wire:click="confirmPickCompleteReadyForAssemblyNavigation"
                                            class="status-confirm-modal__btn-success">
                            Bevestigen
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (filament()->hasUnsavedChangesAlerts())
        @script
            <script>
                (() => {
                    const body = @js(__('filament-panels::unsaved-changes-alert.body'));
                    let measurementTableDirty = false;

                    document.addEventListener('order-view-measurement-dirty', () => {
                        measurementTableDirty = true;
                    });

                    document.addEventListener('order-view-dirty-cleared', () => {
                        measurementTableDirty = false;
                    });

                    const shouldWarn = () =>
                        Boolean($wire?.isOrderViewDirty || measurementTableDirty)
                        && ! $wire?.__instance?.effects?.redirect;

                    window.addEventListener('beforeunload', (event) => {
                        if (!shouldWarn()) {
                            return;
                        }

                        event.preventDefault();
                        event.returnValue = true;
                    });

                    document.addEventListener('livewire:navigate', (event) => {
                        if (!shouldWarn()) {
                            return;
                        }

                        if (!confirm(body)) {
                            event.preventDefault();
                        }
                    });
                })();
            </script>
        @endscript
    @endif
</x-filament-panels::page>
