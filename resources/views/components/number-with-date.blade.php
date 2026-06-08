<div class="numberPlusDate">
    <div class="linksDocuments">
        <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-document', orderId: '{{ $getRecord()->id }}' })">{{ $getRecord()->getUidFormatted() }}</span>
        <a class="downloadDocument" href="{{ route('order.manager-export', ['order' => $getRecord()->id]) }}"></a>
    </div>
    <span class="text-sm text-gray-500 date">{{ $getRecord()->sent_at?->translatedFormat('j M Y (H:i)') }}</span>
</div>
