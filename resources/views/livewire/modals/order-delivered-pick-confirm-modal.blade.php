@php
    use Filament\Actions\Action;

    $scopeLabel = match ($this->deliveredPickScopeType ?? null) {
        'purchase_order' => 'deze inkooporder',
        'release_order' => 'deze afroeporder',
        default => 'deze aanvraag',
    };
@endphp
<x-filament::modal
    id="order_delivered_pick_confirm"
    icon="heroicon-o-exclamation-triangle"
    alignment="center"
    footerActionsAlignment="center"
>
    <x-slot name="heading">
        Alle artikelen van de inkooporder zijn Geleverd.
    </x-slot>

    <x-slot name="description">
        <p>Wil je dat het systeem automatisch de geleverde items binnen {{ $scopeLabel }} op 'Gepickt' zet?</p>
    </x-slot>

    <x-slot name="footerActions">
        <x-filament::button
            type="button"
            class="fi-ac-btn-action white"
            wire:click="$dispatch('confirmOrderDeliveredPick', { confirm: false })"
        >
            Nee. Status: Geleverd.
        </x-filament::button>

        <x-filament::button
            type="button"
            class="fi-ac-btn-action"
            wire:click="$dispatch('confirmOrderDeliveredPick', { confirm: true })"
        >
            Ja. Status: Gepickt.
        </x-filament::button>
    </x-slot>
</x-filament::modal>
