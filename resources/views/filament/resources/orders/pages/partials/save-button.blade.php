@props(['saveEvent'])

<button
    type="button"
    class="fi-btn filament-button-size-md inline-flex items-center justify-center py-1 gap-1 font-medium rounded-lg border transition-colors outline-hidden focus:ring-offset-2 focus:ring-2 focus:ring-inset min-h-9 px-4 text-sm text-white shadow focus:ring-white border-transparent bg-primary-600 hover:bg-primary-500 focus:bg-primary-700 focus:ring-offset-primary-700 filament-page-button-action"
    wire:loading.attr="disabled"
    wire:loading.class.delay="opacity-70 cursor-wait"
    wire:click="$dispatch('{{ $saveEvent }}')"
    wire:target="$dispatch('{{ $saveEvent }}')"
>
    @svgImg('img/icons/save-icon.svg')
    <span class="flex items-center gap-1">
        <span>Opslaan</span>
    </span>
</button>