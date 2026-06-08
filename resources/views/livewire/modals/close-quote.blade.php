@php
    /** @var \App\Http\Livewire\Modals\DeliveryDate $this */
@endphp
<div class="delivery-date bg-white shadow-xl">
    <div class="header">
        @include('components.modal-close-icon', [
            'uid' => 'delivery-date'
        ])

        <h4>Offerte sluiten</h4>

        <p style="padding-top: 20px; font-size: 16px">
            Je hebt een bestaande offerte aangepast. Als je deze offerte sluit,
            worden de gemaakte wijzigingen niet opgeslagen.
        </p>
        <br/>

    </div>
    <div class="buttons">
        <x-primary-button class="xl:mt-8 xl accent-color"
                          wire:click="submit()"
                          wire:loading.class="loading"
                          wire:loading.delay.longest>
           Offerte sluiten
        </x-primary-button>
    </div>
</div>
