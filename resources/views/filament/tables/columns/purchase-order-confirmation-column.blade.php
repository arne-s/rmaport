@props(['displayRefNumber' => false, 'displayDate' => true, 'showMultiple' => true])
@php
    $record = $getModel();
    if ($shouldLeaveEmpty()) return;
    $recordExists = $record && !empty($record->id);
    $hasMultipleConfirmations = $record?->confirmations?->count() > 1;
    $hasLatestConfirmation = $record?->latestConfirmation?->pdf_path && $record?->latestConfirmedAt;
@endphp

<div class="numberPlusDate">
    @if ($record?->is_cancelled || isset($order) && $order->is_cancelled || $record?->order?->getIsCancelled())
        <span class="text-gray-400 text-sm"><em>Geannuleerd</em></span>
    @elseif ($recordExists)
        @if ($hasMultipleConfirmations && $showMultiple)
            <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-purchase-order-confirmation-modal', purchaseOrderId: '{{ $record->id }}' })">
                Bekijk alle
            </span>
        @elseif ($hasLatestConfirmation)
            <div class="linksDocuments">
                <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-purchase-order-confirmation', confirmationId: '{{ $record->latestConfirmation->id }}' })">
                    {{
                        $displayRefNumber
                            ? $record->reference_number
                            : 'Bevestiging inzien'
                    }}
                </span>
                <a class="downloadDocument" href="{{ route('documents.purchaseOrderConfirmationDownload', ['id' => $record->latestConfirmation->id]) }}"></a>
            </div>

            @if ($displayDate && $record->latestConfirmedAt)
                <span class="text-sm text-gray-500 date">{{ $record->latestConfirmedAt->translatedFormat('j M Y (H:i)') }}</span>
            @endif
        @else
            @if ($displayRefNumber && $record?->reference_number)
                <span>{{ $record?->reference_number }}</span>
            @else
                <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
            @endif
        @endif
    @else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
    @endif
</div>
