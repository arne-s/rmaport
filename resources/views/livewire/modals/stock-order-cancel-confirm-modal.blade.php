<x-filament::modal
    id="stock_order_cancel_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Weet je zeker dat je dit wilt doen?
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmStockOrderCancel', { confirm: false })"
        >
            Annuleren
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmStockOrderCancel', { confirm: true })"
        >
            Bevestigen
        </x-filament::button>
    </x-slot>
</x-filament::modal>
