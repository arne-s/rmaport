@props(['displayDate' => true])
@php
    $record = $getRecord();
    if ($shouldLeaveEmpty()) {
        return;
    }
@endphp
<div class="numberPlusDate">
    @if ($record && filled($record->pdf_path))
        <div class="linksDocuments">
            <span
                class="openDocument"
                x-on:click="$dispatch('open-modal', { id: 'open-purchase-order-confirmation', confirmationId: '{{ $record->id }}' })"
            >{{ $record->purchaseOrder?->reference_number ?? 'Bevestiging inzien' }}</span>
            <a
                class="downloadDocument"
                href="{{ route('documents.purchaseOrderConfirmationDownload', ['id' => $record->id]) }}"
            ></a>
        </div>
        @if ($displayDate)
            @php
                $receivedAt = $record->email_received_at ?? $record->created_at;
            @endphp
            @if ($receivedAt)
                <span class="text-sm text-gray-500 date">{{ $receivedAt->translatedFormat('j M Y (H:i)') }}</span>
            @endif
        @endif
    @else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
    @endif
</div>
