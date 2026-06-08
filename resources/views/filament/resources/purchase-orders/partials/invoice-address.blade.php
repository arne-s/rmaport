@php
    $rdCustomer = \App\Models\Customer::getRdMobilityCustomer();
    $addr = $rdCustomer->billingAddress;
    $street = $addr?->street ?? '';
    $house_number = $addr?->house_number ?? '';
    $house_number_addition = $addr?->house_number_addition ?? '';
    $postcode = $addr?->postcode ?? '';
    $city = $addr?->city ?? '';
    $country = $addr?->country?->name ?? '';
    $nameLine = $rdCustomer->getName() ?? '';
@endphp
<div wire:key="purchase-order-invoice-address" style="max-width: 600px">
    <div class="factuurAdresContainer">
        <div class="factuurAdresInner">
            <p>{{ $nameLine }}</p>
            <p>{{ $street }} {{ $house_number }}{{ !empty($house_number_addition) ? ' ' . $house_number_addition : '' }}</p>
            <p>{{ $postcode }}{{ $postcode && $city ? ', ' : '' }}{{ $city }}</p>
            @if (!empty($country))
                <p>{{ $country }}</p>
            @endif
        </div>
    </div>
</div>
