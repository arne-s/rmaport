@props(['displayDate' => true])
@php
    use App\Enums\OrderGeneralStatus;

    $model = $getModel();
    if ($shouldLeaveEmpty()) return;
@endphp
<div class="numberPlusDate">
    @if ($model?->getIsCancelled() || $model?->order?->getIsCancelled() || $record?->getIsCancelled())
        <span class="text-gray-400 text-sm"><em>Geannuleerd</em></span>
    @elseif ($model && ! empty($model->uid) && $model->status != OrderGeneralStatus::Draft->value && $model->status != OrderGeneralStatus::Initial->value)
        @php
            $id = $model->id;
            $displayUid = method_exists($model, 'getUidFormatted') && $model->getUidFormatted() !== ''
                ? $model->getUidFormatted()
                : $model->uid;
            $typeVal = $model->type instanceof \BackedEnum ? $model->type->value : (string) $model->type;
            $isInvoiceType = in_array($typeVal, ['invoice', 'deposit_invoice', 'credit_invoice'], true);
            $downloadUrl = $isInvoiceType
                ? route('documents.invoice-download', ['id' => $id])
                : route('order.manager-export', ['order' => $id]);
        @endphp
        <div class="linksDocuments">
            <span class="openDocument"
                  x-on:click="$dispatch('open-modal', { id: 'open-document', orderId: '{{ $id }}', {{ $isInvoiceType ? "invoicePreview: true" : "" }} })">{{ $displayUid }}</span>
            <a class="downloadDocument" href="{{ $downloadUrl }}"></a>
        </div>
    @else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
    @endif
</div>
