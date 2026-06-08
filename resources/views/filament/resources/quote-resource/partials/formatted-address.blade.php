@php
    /** @var \App\Models\Address|null $address */
    $address = $address ?? null;
    $emptyLabel = $emptyLabel ?? '(Geen adresgegevens ingevuld)';
@endphp
@if ($address)
    {!! $address->getAddressTemplateIncNameFormatted() !!}
@else
    <span class="text-sm text-gray-500 italic">{{ $emptyLabel }}</span>
@endif
