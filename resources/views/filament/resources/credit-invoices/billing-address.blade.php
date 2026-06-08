@php
    $record = $getRecord() ?? $this->record ?? null;
    $billingAddress = $record?->billingAddress;
    $name = $billingAddress?->name ?? $record?->customer?->getName() ?? '-';
    $street = $billingAddress?->street ?? '';
    $houseNumber = $billingAddress?->house_number ?? '';
    $addition = $billingAddress?->house_number_addition ?? '';
    $postcode = $billingAddress?->postcode ?? '';
    $city = $billingAddress?->city ?? '';
    $country = $billingAddress?->country?->name ?? '';
@endphp
<div style="max-width: 600px">
    <div class="factuurAdresInner">
        <p>{{ $name }}</p>
        <p>{{ $street }} {{ $houseNumber }}{{ !empty($addition) ? ' ' . $addition : '' }}</p>
        <p>{{ $postcode }}{{ !empty($city) ? ', ' . $city : '' }}</p>
        @if (!empty($country))
            <p>{{ $country }}</p>
        @endif
    </div>
</div>
