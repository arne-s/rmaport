@php
    $record = $getRecord();
    $isArray = is_array($record);
    $uid = $isArray ? ($record['uid'] ?? '-') : ($record->getUid() ?: '-');
    $isQuoteRow = $isArray
        && ($record['_type'] ?? '') === 'order'
        && ($record['type_value'] ?? '') === 'quote';
    $modelId = $isArray ? ($record['_model_id'] ?? null) : null;
@endphp
<div class="filament-tables-text-column">
    @if ($isQuoteRow && $modelId !== null)
        <button
            type="button"
            class="link !bg-transparent !p-0 !border-0 cursor-pointer font-inherit text-left openDocument"
            x-data
            x-on:click="$dispatch('open-modal', {{ json_encode(['id' => 'open-document', 'orderId' => (string) $modelId, 'quotePreview' => true]) }})"
        >{{ $uid }}</button>
    @else
        {{ $uid }}
    @endif
</div>
