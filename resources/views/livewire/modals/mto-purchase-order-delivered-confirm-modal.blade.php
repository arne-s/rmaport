@php
    use Filament\Actions\Action;
@endphp
<x-filament::modal
    id="mto_purchase_order_delivered_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Alle artikelen van de inkooporder zijn Geleverd.
    </x-slot>

    <x-slot name="description">
        <p>Wil je dat het systeem automatisch alle items vanuit deze Inkooporder op ‘Gepickt’ zet in de Aanvraag?</p>
        <br/>

        <p>Pick deze artikelen en plaats fysiek in het kratje.</p>
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmMtoPurchaseOrderDelivered', { confirm: false, type: 'mto' })"
        >
            Nee. Status: Geleverd.
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmMtoPurchaseOrderDelivered', { confirm: true, type: 'mto' })"
        >
            Ja. Status: Gepickt.
        </x-filament::button>
    </x-slot>
</x-filament::modal>
