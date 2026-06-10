@php use App\Models\Customer; @endphp
@props(['type' => null])

@php
    /** @var \App\Models\Order\BaseOrder $order */
    /** @var App\Models\Customer $avCustomer */

    $avCustomer = Customer::getAvCustomer();
    $customer = $order->customer;
    $name = $order->getCustomerAddressDisplayName() ?? $customer->getName();
    $customerAddr = $order->getCustomerAddress();
    $address = $customerAddr?->getAddress();
    $postcode = $customerAddr?->getPostcode();
    $city = $customerAddr?->getCity();
@endphp

<div class="recipient-info" style="margin-bottom: 60px">
    @if ($avCustomer)
        @if ($type === 'packing_slip')
            <h3 style="margin-bottom: 3px; text-decoration: underline;">{{ __('orders.documents.delivery_address') }}</h3>
        @endif
        <strong style="display: block; margin-bottom: 5px">
            {{ $name }}
        </strong>

        {{ $avCustomer->getName() }}<br/>
        {{ $address ?? $avCustomer->getAddress() }}<br/>
        {{ $postcode ?? $avCustomer?->billingAddress?->getPostcode() }} {{ $city ?? $avCustomer?->billingAddress?->getCity() }}<br/>

        @if ($type !== 'packing_slip')
            KvK: {{ $avCustomer->getKvk() }}<br/>
            BTW: {{ $avCustomer->getVat() }}<br/>
        @endif
    @else
        <div>T.a.v. {{ $name }}</div>
        {{ $address }}<br/>
        {{ $postcode }} {{ $city }}<br/>
    @endif
</div>
