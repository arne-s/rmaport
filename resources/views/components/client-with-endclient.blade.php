@php
    $record = $getRecord();
    $billingCustomer = $record->billingCustomer;
    $isBusiness = $billingCustomer?->getType()?->isBusiness() ?? false;
    $customer = $isBusiness ? ($billingCustomer?->getName() ?? '') : '';
    $client = $isBusiness ? ($record->customer?->getName() ?? '') : '';
@endphp
<div class="numberPlusDate">
    <span class="titleHead">{{ $customer }}</span>
    @if ($client)
      <span class="text-sm text-gray-500 date">
          Klant: {{ $client }}
      </span>
    @endif
</div>
