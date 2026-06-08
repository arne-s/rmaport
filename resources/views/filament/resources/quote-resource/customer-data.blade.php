@php
    $record = $getRecord() ?? $this->record ?? null;
    $address = $record?->getCustomerAddress();
@endphp
<div class="invoice-customer-data" style="margin: 0 -10px;">
    @include('filament.resources.quote-resource.partials.formatted-address', ['address' => $address])
</div>
