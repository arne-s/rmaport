@php
    use App\Enums\OrderType;
    use App\Filament\Resources\OrderResource\Widgets\OrderDocsTableWidget;
    use Filament\Support\ArrayRecord;

    $record = $getRecord();
    $isArray = is_array($record);
    $isUpload = $isArray && ($record['_type'] ?? '') === 'upload';
    $description = $isArray ? ($record['description'] ?? '-') : '-';
    $previewKey = $isArray ? (string) ($record[ArrayRecord::getKeyName()] ?? '') : '';

    $livewire = $getLivewire();
    $previewItems = $livewire instanceof OrderDocsTableWidget
        ? $livewire->getFinancialDocumentPreviewItems()
        : [];

    $modalId = 'open-document';
    $modalPayload = [];

    if (! $isUpload) {
        $typeValue = $isArray ? ($record['type_value'] ?? '') : ($record->type instanceof \BackedEnum ? $record->type->value : (string) ($record->type ?? ''));
        $uid = $isArray ? ($record['uid'] ?? '-') : ($record->getUidFormatted() ?: '-');
        $modelId = $isArray ? ($record['_model_id'] ?? 0) : $record->id;

        $isQuote = ($typeValue === 'quote');
        $isOrder = ($typeValue === 'order');
        $isInvoice = in_array($typeValue, ['invoice', 'deposit_invoice', 'credit_invoice'], true);
        if ($isQuote) {
            $modalPayload = ['orderId' => (string) $modelId, 'quotePreview' => true];
        } elseif ($isOrder || $isInvoice) {
            $modalPayload = ['orderId' => (string) $modelId, 'invoicePreview' => true];
        } else {
            $modalId = 'order-preview';
            $modalPayload = ['title' => (OrderType::tryFrom($typeValue)?->getLabel() ?? $typeValue) . ' #' . $uid];
        }
    } else {
        $modalPayload = ['mediaId' => (string) ($record['media_id'] ?? '')];
    }

    if ($previewKey !== '' && $previewItems !== []) {
        $modalId = 'financial-documents-preview';
        $modalPayload['previewItems'] = $previewItems;
        $modalPayload['previewKey'] = $previewKey;
    }

    $modalPayload['id'] = $modalId;
@endphp
<div class="description filament-tables-text-column flex items-center gap-2 min-w-0">
    <span class="icon-file flex-shrink-0" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="16.763" height="20.504" viewBox="0 0 16.763 20.504" class="">
            <g id="Group_8550" data-name="Group 8550" transform="translate(-754.209 -1011.048)">
                <path id="Path_5000" data-name="Path 5000" d="M15.352,3H7.87A1.87,1.87,0,0,0,6,4.87V19.833A1.87,1.87,0,0,0,7.87,21.7H19.092a1.87,1.87,0,0,0,1.87-1.87V8.611Z" transform="translate(749.109 1008.948)" fill="none" stroke="#333" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                <path id="Path_5001" data-name="Path 5001" d="M21,3V8.611h5.611" transform="translate(743.461 1008.948)" fill="none" stroke="#333" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                <path id="Path_5002" data-name="Path 5002" d="M19.481,19.5H12" transform="translate(746.85 1002.735)" fill="none" stroke="#333" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                <path id="Path_5003" data-name="Path 5003" d="M19.481,25.5H12" transform="translate(746.85 1000.476)" fill="none" stroke="#333" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                <path id="Path_5004" data-name="Path 5004" d="M13.87,13.5H12" transform="translate(746.85 1004.994)" fill="none" stroke="#333" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
            </g>
        </svg>
    </span>
    @if ($isUpload && empty($record['media_id']))
        <span class="description__name min-w-0 truncate" title="{{ $description }}">{{ $description }}</span>
    @else
        <button
            type="button"
            class="description__name link flex-1 min-w-0 !bg-transparent !p-0 !border-0 cursor-pointer text-left font-inherit truncate"
            x-data
            x-on:click="$dispatch('open-modal', {{ json_encode($modalPayload) }})"
            title="{{ $description }}"
        >{{ $description }}</button>
    @endif
</div>
