@php
    $livewire = $getLivewire();
    $typeKey = (string) ($livewire->data['shipping_address_type'] ?? 'rd');
    $markup = $livewire->getStockOrderLeveradresPreviewHtml($typeKey);
@endphp
<div wire:key="stock-order-leveradres-preview-{{ $livewire->record->getId() }}-{{ $typeKey }}" style="max-width: 600px">
    <div class="verzendAdresContainer">
        <div class="verzendAdresInner">
            {!! $markup !!}
        </div>
    </div>
</div>
