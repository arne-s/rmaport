@php
    use Filament\Actions\Action;
@endphp
<div
    class="unit-create-quote-or-order-modal"
    x-data="{ initData: null }"
    x-on:open-modal.window="if ($event.detail.id === 'unit_create_quote_or_order_modal') initData = $event.detail.initData"
>
    <x-filament::modal
        id="unit_create_quote_or_order_modal"
        alignment="center"
        footerActionsAlignment="center"
    >
        <x-slot name="heading">
            Product bestellen
        </x-slot>

        <x-slot name="description">
            Wil je een Offerte of een Order maken?
        </x-slot>

        <x-slot name="footerActions">
            <x-filament::button
                type="button"
                class="fi-ac-btn-action"
                x-on:click="$dispatch('create-quote-or-order', { type: 'quote', initData })"
            >
                Offerte maken
            </x-filament::button>
            <x-filament::button
                type="button"
                class="fi-ac-btn-action"
                x-on:click="$dispatch('create-quote-or-order', { type: 'order', initData })"
            >
                Order maken
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>

<style>
.unit-create-quote-or-order-modal {
    .fi-modal-footer-actions {
        flex-direction: row;
        justify-content: center;
    }
}
</style>