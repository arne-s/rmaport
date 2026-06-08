@php
    $record = $getRecord();
@endphp

<div class="flex items-center justify-between gap-2">
    <span>{{ $record?->value ?? 'Geen product geselecteerd' }}</span>
    <button
        type="button"
        class="text-sm text-primary-600 hover:underline"
        wire:click="$set('{{ $getStatePath('edit_mode_product') }}', true)"
    >
        Wijzig
    </button>
</div>


