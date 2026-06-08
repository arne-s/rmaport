@php
    use App\Enums\OrderGeneralStatus;

    $record = $getRecord();
    $isArray = is_array($record);
    $statusValue = $isArray ? ($record['status_value'] ?? '') : ($record->status instanceof \BackedEnum ? $record->status->value : (string) ($record->status ?? ''));
    $typeValue = $isArray ? ($record['type_value'] ?? '') : ($record->type instanceof \BackedEnum ? $record->type->value : (string) ($record->type ?? ''));
    $isConcept = $statusValue === OrderGeneralStatus::Draft->value;
    $isUpload = $isArray && ($record['_type'] ?? '') === 'upload';
@endphp
<div class="filament-tables-text-column px-4 py-3 padding-8 flex">
    @if ($isUpload || $statusValue === '')
        <span class="text-gray-500">-</span>
    @elseif ($isConcept)
        <span>Concept</span>
    @else
        {{ Str::title(__(sprintf('orders.status.%s', $statusValue))) }}
        <span title="{{ Str::title(__(sprintf('orders.status.%s', $statusValue))) }}"
              class="icon-status type-{{ $typeValue }} status-{{ $statusValue }}">⬤</span>
    @endif
</div>
