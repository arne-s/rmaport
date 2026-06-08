@props(['displayDate' => true, 'showCancelled' => true])
@php
    use App\Enums\OrderGeneralStatus;

    $model = $getModel();
    if ($shouldLeaveEmpty()) return;
@endphp
<div class="numberPlusDate">
    @if (isset($record->source_type) && $record->source_type === 'exact_document')
        @php
            $rawName = $record->file_name ?? '-';
            $label = str_ends_with(mb_strtolower((string) $rawName), '.pdf') ? mb_substr((string) $rawName, 0, -4) : (string) $rawName;
            $downloadUrl = route('documents.exact-document-download', ['id' => $record->source_id]);
        @endphp
        <div class="linksDocuments">
            <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-document', exactDocumentId: '{{ $record->source_id }}' })">{{ $label }}</span>
            <a class="downloadDocument" href="{{ $downloadUrl }}" target="_blank"></a>
        </div>
    @elseif ($showCancelled && ($model?->getIsCancelled() || $model?->order?->getIsCancelled() || $record?->getIsCancelled()))
        <span class="text-gray-400 text-sm"><em>Geannuleerd</em></span>
    @elseif ($model && !empty($model->uid) && $model?->sent_at && ($model->status != OrderGeneralStatus::Draft->value && $model->status != OrderGeneralStatus::Initial->value || isset($record->source_type)))
        @php
            $isPackingSlip = isset($record->source_type) && $record->source_type === 'media';
            // When the record is a CompanyDocumentTableRow (source_id = real numeric PK), use that so
            // the URL becomes /documents/366 instead of /documents/order-366.
            $sourceId = $record->source_id ?? null;
            $id = $sourceId ?? $model->id;
            $displayUid = method_exists($model, 'getUidFormatted') && $model->getUidFormatted() !== ''
                ? $model->getUidFormatted()
                : $model->uid;
        @endphp
        <div class="linksDocuments">
            @if ($isPackingSlip)
                <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-document', mediaId: '{{ $sourceId }}' })">{{ $displayUid }}</span>
                <a class="downloadDocument" href="{{ route('documents.media-download', ['id' => $sourceId]) }}" target="_blank"></a>
            @else
                @php
                    $typeValue = $model->type instanceof \BackedEnum ? $model->type->value : (string) ($model->type ?? '');
                    $isQuotePreview = $model instanceof \App\Models\Order\Quote || $typeValue === 'quote';
                    $isInvoicePreview = in_array($typeValue, ['invoice', 'deposit_invoice', 'credit_invoice'], true);
                @endphp
                <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-document', orderId: '{{ $id }}', quotePreview: @js($isQuotePreview), invoicePreview: @js($isInvoicePreview) })">{{ $displayUid }}</span>
                @if ($isInvoicePreview)
                    <a class="downloadDocument" href="{{ route('documents.invoice-download', ['id' => $id]) }}"></a>
                @else
                    <a class="downloadDocument" href="{{ route('order.manager-export', ['order' => $id]) }}"></a>
                @endif
            @endif
        </div>
        @if ($displayDate && $model->sent_at)
            <span class="text-sm text-gray-500 date">{{ $model->sent_at->translatedFormat('j M Y (H:i)') }}</span>
        @endif
    @else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
    @endif
</div>
