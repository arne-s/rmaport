@props(['displayDate' => true])
@php
    $record = $getRecord();
    if ($shouldLeaveEmpty()) return;
    $id = $record->_rowType === \App\Enums\PurchaseInvoiceRowType::InvoiceRow ? $record->purchase_invoice_id : $record->id;
@endphp
<div class="numberPlusDate">
    @if ($record?->is_cancelled)
        <span class="text-gray-400 text-sm"><em>Geannuleerd</em></span>
    @elseif ($record && filled($record->invoice_number))
        <div class="linksDocuments">
            <span class="openDocument" x-on:click.stop="$dispatch('open-modal', { id: 'open-purchase-order-invoice', invoiceId: '{{ $id }}' })">{{ $record->invoice_number }}</span>
            <a class="downloadDocument" href="{{ route('documents.purchaseOrderInvoiceDownload', ['id' => $id]) }}"></a>
        </div>
        @if ($displayDate && $record->email_received_at)
            <span class="text-sm text-gray-500 date">{{ $record->email_received_at->translatedFormat('j M Y (H:i)') }}</span>
        @endif
    @else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
    @endif
</div>
