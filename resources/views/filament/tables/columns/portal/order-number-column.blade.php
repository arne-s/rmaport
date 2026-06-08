@php
    $model = $getModel();
    if ($shouldLeaveEmpty()) return;

    $displayDate = $getDisplayDate();
    $hideHash = $getHideHash();
    $showCancelled = $getShowCancelled();
    $isCancelled = $isCancelled();

    $id = $model?->id;
@endphp
<div class="numberPlusDate">
    @if ($model?->id === 4751)
        <span>{{ $model->uid }}</span>
    @elseif (str_contains($getName(), 'credit_invoice') && !$model)
    {{-- @if (str_contains($getName(), 'credit_invoice') && !$model) --}}
        <span></span>
    @elseif ($showCancelled && $isCancelled)
        <span class="text-gray-400 text-sm"><em>Geannuleerd</em></span>
    @elseif ($model && !empty($model->uid) && $model?->sent_at)
        <div class="linksDocuments">
            <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-document-portal', orderId: '{{ $id }}' })">
                {{ $hideHash ? '' : '#' }}{{ $model->getUidFormatted() }}
            </span>
            <a class="downloadDocument" href="{{ route('order.company-export', ['baseOrder' => $id]) }}"></a>
        </div>
        @if ($displayDate && $model->sent_at)
            <span class="text-sm text-gray-500 date">{{ $model->sent_at->translatedFormat('d-m-Y (H:i)') }}</span>
        @endif
@else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
        @endif
</div>
