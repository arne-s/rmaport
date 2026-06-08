@php
    use Filament\Actions\Action;
@endphp
<x-filament::modal
    id="order_picked_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Alle producten zijn gepickt.
    </x-slot>

    <x-slot name="description">
        De orderstatus wordt bijgewerkt naar Gefactureerd / Klaar voor afhalen en de order zal gefactureerd worden.
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmOrderPicked', { confirm: false })"
        >
            Annuleren
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmOrderPicked', { confirm: true })"
        >
            Bevestigen
        </x-filament::button>
    </x-slot>
</x-filament::modal>