@php
    use Filament\Actions\Action;
@endphp
<x-filament::modal
    id="release_order_delivered_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Alle artikelen zijn Geleverd.
    </x-slot>

    <x-slot name="description">
        <p>Wil je dat het systeem automatisch alle items vanuit deze Afroep op ‘Gepickt’ zet in de Aanvraag?</p>
        <br/>

        <p>Pick deze artikelen en plaats fysiek in het kratje.</p>
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmReleaseOrderDelivered', { confirm: false })"
        >
            Nee. Status: Geleverd.
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmReleaseOrderDelivered', { confirm: true })"
        >
            Ja. Status: Gepickt.
        </x-filament::button>
    </x-slot>
</x-filament::modal>
