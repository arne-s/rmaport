@php
    use Filament\Actions\Action;
@endphp
<x-filament::modal
    id="mto_product_delivered_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Producten ontvangen.
    </x-slot>

    <x-slot name="description">
        De voorraad zal worden opgeboekt.
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmMtoProductDelivered', { confirm: false })"
        >
            Annuleren
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmMtoProductDelivered', { confirm: true })"
        >
            Bevestigen
        </x-filament::button>
    </x-slot>
</x-filament::modal>