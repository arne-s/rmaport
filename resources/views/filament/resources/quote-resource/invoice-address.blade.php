@php
    use App\Models\Customer;

    $livewire = $getLivewire();
    $state = $livewire->data ?? [];
    $record = $getRecord() ?? $livewire->record ?? null;

    $billingCustomerId = $state['billing_customer_id'] ?? null;
    $billingCustomer = $billingCustomerId
        ? Customer::with(['billingAddress', 'address'])->find($billingCustomerId)
        : null;

    $address = $billingCustomer?->billingAddress ?? $billingCustomer?->address;
@endphp
<div wire:key="invoice-address-{{ $record?->id }}-{{ $billingCustomerId }}" style="max-width: 600px">
    <div class="factuurAdresContainer">
        <div class="factuurAdresInner">
            @include('filament.resources.quote-resource.partials.formatted-address', ['address' => $address])
        </div>
    </div>
</div>
