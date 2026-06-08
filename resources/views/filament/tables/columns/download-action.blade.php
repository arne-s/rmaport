@props(['label', 'modalId', 'downloadLink', 'orderId' => null])
@php
    $record = $getRecord();
    if ($shouldLeaveEmpty()) return;
    $resolvedOrderId = $orderId ?? $record?->id;
@endphp
<div class="numberPlusDate">
    @if ($record && filled($resolvedOrderId))
        <div class="linksDocuments">
            <span class="openDocument" x-on:click="$dispatch('open-modal', { id: '{{ $modalId }}', orderId: '{{ $resolvedOrderId }}' })">
                {{ $label }}
            </span>
            <a
                class="downloadDocument"
                href="{{ $downloadLink() }}"
                style="border: none; padding: 5px !important;"
            ></a>
        </div>
    @endif
</div>
