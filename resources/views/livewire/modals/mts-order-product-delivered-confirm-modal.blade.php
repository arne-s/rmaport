<x-filament::modal
    id="mts_order_product_delivered_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Product is geleverd
    </x-slot>

    <x-slot name="description">
        Je staat op het punt dit item op Geleverd te zetten. Wanneer je doorgaat, wordt de voorraad van het ingekochte artikel automatisch opgeboekt.
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmMtsOrderProductDelivered', { confirm: false })"
        >
            Annuleren
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmMtsOrderProductDelivered', { confirm: true })"
        >
            Bevestigen
        </x-filament::button>
    </x-slot>
</x-filament::modal>
