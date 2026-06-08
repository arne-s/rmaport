@props([
    'showCompanyQuote' => false,
    'quoteFromMain' => false,
    'emptyWhenMissing' => false,
    /** Quote column (e.g. /quotes): open admin preview with placeholder + approval panel */
    'quote' => false,
])
@php
    $record = $getRecord();

    if ($quoteFromMain) {
        $mainRecord = $record;
        $record = $mainRecord->quotes()
            ->where('status', '!=', \App\Enums\OrderGeneralStatus::Initial)
            ->orderByDesc('rev')
            ->first();

        if (empty($record) && $emptyWhenMissing) {
            return;
        }
    } elseif ($showCompanyQuote) {
        $record = $record->quoteCompany;
        if (empty($record)) {
            return;
        }
    } elseif (!$showCompanyQuote && $record?->getIsAdminGenerated()) {
        return;
    }

    if (empty($record)) {
        return;
    }

    $useQuoteAdminPreview = $quote || $record instanceof \App\Models\Order\Quote;
@endphp
<div class="numberPlusDate">
    <div class="linksDocuments">
        <span class="openDocument" x-on:click="$dispatch('open-modal', { id: 'open-document', orderId: '{{ $record->id }}', quotePreview: @js($useQuoteAdminPreview) })">
            {{ $record->getUidFormatted() }}
        </span>
        @unless($quoteFromMain)
            @php
                $documentDownloadUrl = $record instanceof \App\Models\Order\Quote
                    ? route('documents.order-pdf-download', ['id' => $record->id])
                    : route('order.manager-export', ['order' => $record->id]);
            @endphp
            <a class="downloadDocument" href="{{ $documentDownloadUrl }}"></a>
        @endunless
    </div>
</div>
