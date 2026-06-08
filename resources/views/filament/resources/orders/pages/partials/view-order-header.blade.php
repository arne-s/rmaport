@php
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Models\Order\Main;

/** @var \App\Filament\Resources\OrderResource\Pages\ViewOrder $this */
/** @var Main $order */
$order = $this->record instanceof Main ? $this->record : ($this->record->getMain() ?? $this->record);
$isPart = $order?->getSubtype() === OrderSubtype::Part;
$usesUnitSimplifiedSalesFlow = $order instanceof Main && $order->usesUnitSimplifiedSalesFlow();
@endphp
<div class="header view-order-header">
    <div class="breadcrumb">
        <div class="backTo">
            <a href="{{ route('filament.app.resources.production.index') }}">
                @svgImg('img/icons/chevron-left.svg')
                <span>Terug naar Verkoopproces</span>
            </a>
        </div>
    </div>

    <div class="title-container view-event">
        <div class="title-container__title-group view-order-event">
            <h2 class="title" style="position: relative; top: 5px"><span style="font-size: 16px;">Aanvraag:</span> {{ $order->getDescriptor() }}</h2>
            <button
                type="button"
                class="order-events-link order-events-link--icon"
                title="Historie"
                aria-label="Historie"
                x-on:click="$dispatch('open-modal', { id: 'order-events' })">
                <svg class="order-events-link__icon h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                    fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd"
                        d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM12.75 6a.75.75 0 0 0-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-3.75V6Z"
                        clip-rule="evenodd"></path>
                </svg>
            </button>

            @if (count($this->getOrderStatusDropdownOptions()) > 0)
            <div class="fi-input-wrp fi-fo-select fi-fo-select-native order-status-select-wrp">
                <div class="fi-input-wrp-content-ctn">
                    <select
                        wire:model.live="orderStatus"
                        class="fi-select-input"
                        id="order-status-select"
                        aria-label="Orderstatus">
                        @foreach ($this->getOrderStatusDropdownOptions() as $option)
                        <option
                            value="{{ $option['value'] }}" @disabled(!$option['selectable'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @endif
        </div>
        <div class="title-container__right">
            @foreach($this->getHeaderActionsForView() as $action)
            {{ $action }}
            @endforeach
            @include('filament.resources.orders.pages.partials.view-order-save-button')
        </div>
    </div>


    <x-filament::tabs>
        <x-filament::tabs.item
            alpine-active="activeTab === 'order'"
            x-on:click="activeTab = 'order'">
            Gegevens
        </x-filament::tabs.item>
        @if ($order && !$isPart && $order->getSubtype() !== \App\Enums\OrderSubtype::Service && ! $usesUnitSimplifiedSalesFlow)
        <x-filament::tabs.item
            alpine-active="activeTab === 'fitting'"
            x-on:click="activeTab = 'fitting'">
            Passing
        </x-filament::tabs.item>
        @endif
        @if ($order && $order->getSubtype() === \App\Enums\OrderSubtype::Service)
        <x-filament::tabs.item
            alpine-active="activeTab === 'service'"
            x-on:click="activeTab = 'service'">
            Service
        </x-filament::tabs.item>
        @endif
        @php
        $orderStatusRaw = $order?->order_status;
        $currentStatus = $orderStatusRaw instanceof OrderStatus ? $orderStatusRaw : ($orderStatusRaw !== null ? OrderStatus::tryFrom($orderStatusRaw) : null);
        $showAssemblyTab = OrderStatus::shouldShowOrderViewAssemblyTab($currentStatus);
        $showDeliveryTab = OrderStatus::shouldShowOrderViewDeliveryTab($currentStatus);
        $showProductsTab = OrderStatus::shouldShowOrderViewProductsTab($currentStatus);
        @endphp
        @if ($order && $showProductsTab)
        <x-filament::tabs.item
            alpine-active="activeTab === 'purchase'"
            x-on:click="activeTab = 'purchase'">
            Artikelen/bucket
        </x-filament::tabs.item>
        @endif
        @if ($order && $showAssemblyTab && ! $isPart && $order->getSubtype() !== OrderSubtype::Service && ! $usesUnitSimplifiedSalesFlow)
        <x-filament::tabs.item
            alpine-active="activeTab === 'assembly'"
            x-on:click="activeTab = 'assembly'">
            Montage
        </x-filament::tabs.item>
        @endif
        @if ($order && $showDeliveryTab && ! $isPart && $order->getSubtype() !== OrderSubtype::Service)
        <x-filament::tabs.item
            alpine-active="activeTab === 'delivery'"
            x-on:click="activeTab = 'delivery'">
            Levering
        </x-filament::tabs.item>
        @endif
        <x-filament::tabs.item
            alpine-active="activeTab === 'notes'"
            x-on:click="activeTab = 'notes'">
            Notities
        </x-filament::tabs.item>

        @if (! $isPart && $order && $order->getSubtype() !== OrderSubtype::Service && ! $usesUnitSimplifiedSalesFlow)
        <x-filament::tabs.item
            alpine-active="activeTab === 'checklist'"
            x-on:click="activeTab = 'checklist'">
            Controlelijst
        </x-filament::tabs.item>
        @endif

        @php $showShippingTab = $isPart && OrderStatus::shouldShowOrderViewShippingTab($currentStatus); @endphp
        @if ($order && $showShippingTab)
        <x-filament::tabs.item
            alpine-active="activeTab === 'shipping'"
            x-on:click="activeTab = 'shipping'">
            Verzending
        </x-filament::tabs.item>
        @endif

    </x-filament::tabs>

    <x-filament-actions::modals />
</div>
