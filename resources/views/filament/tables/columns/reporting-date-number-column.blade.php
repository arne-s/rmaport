@props(['displayDate' => true])
@php
    $model = $getModel();
    if ($shouldLeaveEmpty()) {
        return;
    }

    $invoiceDate = $model?->getSentAt() ?? $model?->updated_at;
@endphp
<div class="numberPlusDate">
    @if ($invoiceDate)
        <span class="text-sm text-gray-500 date">{{ $invoiceDate->translatedFormat('j M Y (H:i)') }}</span>
    @endif
</div>
