@php
    use Filament\Actions\Action;
@endphp
<x-filament::modal
    id="mts_purchase_order_delivered_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Alle producten zijn geleverd.
    </x-slot>

    <x-slot name="description">
        Je staat op het punt de inkooporder op <b>Geleverd</b> te zetten. Wanneer je doorgaat, worden alle items als Geleverd gemarkeerd en de voorraad aantallen van de ingekochte producten automatisch opgeboekt.
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmMtsPurchaseOrderDelivered', { confirm: false, type: 'mts' })"
        >
            Annuleren
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmMtsPurchaseOrderDelivered', { confirm: true, type: 'mts' })"
        >
            Bevestigen
        </x-filament::button>
    </x-slot>
</x-filament::modal>