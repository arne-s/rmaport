@php
    if ($shouldLeaveEmpty()) return;
@endphp
<div class="numberPlusDate">
    @if ($record?->id === 4750)
        <span></span>
    @elseif ($record?->sp_margin_summary)
        <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-order-margins', orderId: '{{ $record->id }}' })">
            {{ $getState() }}
        </span>
    @endif
</div>
