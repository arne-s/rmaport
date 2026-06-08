@php
    $rdCustomer = \App\Models\Customer::getRdMobilityCustomer();
    $addr = $rdCustomer->billingAddress;
    $street = $addr?->street ?? '';
    $house_number = $addr?->house_number ?? '';
    $house_number_addition = $addr?->house_number_addition ?? '';
    $postcode = $addr?->postcode ?? '';
    $city = $addr?->city ?? '';
    $nameLine = $rdCustomer->getName() ?? '';
@endphp
<div wire:key="purchase-order-delivery-address" style="max-width: 600px">
    <div class="verzendAdresContainer">
        <div class="verzendAdresInner">
            @if (filled($nameLine))
                <p>{{ $nameLine }}</p>
            @endif
            <p>{{ trim($street . ' ' . $house_number) }}{{ filled($house_number_addition) ? ', ' . $house_number_addition : '' }}</p>
            <p>{{ $postcode }}{{ filled($postcode) && filled($city) ? ', ' : '' }}{{ $city }}</p>
        </div>
    </div>
</div>
