@php
    $livewire = $getLivewire();
    $state = $livewire->data ?? [];
    $record = $getRecord() ?? $livewire->record ?? null;

    $shippingCustomerId = $state['shipping_customer_id'] ?? null;
    $shippingCustomer = $shippingCustomerId
        ? \App\Models\Customer::with(['shippingAddress', 'billingAddress', 'address'])->find($shippingCustomerId)
        : null;

    $address = $shippingCustomer?->getPhysicalDeliveryAddress();
@endphp
<div wire:key="quote-delivery-{{ $record?->id }}-{{ $shippingCustomerId }}" style="max-width: 600px">
    <div class="verzendAdresContainer">
        <div class="verzendAdresInner">
            @include('filament.resources.quote-resource.partials.formatted-address', ['address' => $address])
        </div>
    </div>
</div>
