@php
    /** @var App\Models\Customer $company */

$type = '';
if (isset($order)) {
    $type = $order->type;
}
@endphp

<div class="company-info" style="text-align: left; line-height: 28px; display: inline-block; min-width: 260px;">
    <div style="padding: 6px 0; font-size: 16px"><strong style="font-size: 16px"> {{ $company->getName() }}</strong>
    </div>
    <div style="padding: 6px 0; font-size: 16px">
        {{ $company->getFirstName()}} {{ $company->getLastName() }}<br/>
        {{ $company?->billing_address?->getStreetTemplate() }}<br/>
        {{ $company?->billing_address?->getPostcode() }} {{ $company?->billing_address?->getCity() }}<br/>
    </div>

    <div style="padding: 6px 0">
        KvK: {{ $company->getKvk() }}<br/>
        BTW: {{ $company->getVat() }}<br/>
    </div>

    <div style="padding: 6px 0">
        IBAN: {{ $company->getIban() }}<br/>
    </div>

    @php
        /** @var App\Models\Order\BaseOrder $order */
        $link = isset($order) ? $order->getPaymentLink() : null;
    @endphp
    @if($link)
        <a href="{{ $link }}" target="_blank"
           style="display: block; color: blue; text-decoration: underline; font-size: 13px"><img
                style="width: 250px; margin-left: -9px; margin-top: 0;"
                src="{{url('/img/invoice/ideal-link.png')}}"/></a>
    @endif
</div>


