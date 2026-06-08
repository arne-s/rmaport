@php
    $record = $getRecord() ?? $this->record ?? null;
    $parentInvoice = $record?->invoice;
    $parentOrder = $record?->order;
    $main = $record?->main ?? $parentOrder?->main;
@endphp
<div class="meta-info-grid" style="max-width: 600px">
    <table class="text-sm w-full">
        <tbody>
            <tr>
                <td class="py-0.5 font-semibold" style="white-space: nowrap; padding-right: 1rem;">Soort</td>
                <td class="py-0.5">{{ $parentInvoice?->getType()?->getLabel() ?? '-' }}</td>
            </tr>
            @if ($record?->uid)
                <tr>
                    <td class="py-0.5 font-semibold" style="white-space: nowrap; padding-right: 1rem;">Creditnota</td>
                    <td class="py-0.5">{{ $record->getUidFormatted() }}</td>
                </tr>
            @endif
            <tr>
                <td class="py-0.5 font-semibold" style="white-space: nowrap; padding-right: 1rem;">Datum</td>
                <td class="py-0.5">{{ $record?->getCreatedAt()?->format('d-m-Y') ?? '-' }}</td>
            </tr>
            @if ($main)
                <tr>
                    <td class="py-0.5 font-semibold" style="white-space: nowrap; padding-right: 1rem;">Aanvraag</td>
                    <td class="py-0.5">{{ $main->getUidFormatted() }}</td>
                </tr>
            @endif
            @if ($record?->billingCustomer)
                <tr>
                    <td class="py-0.5 font-semibold" style="white-space: nowrap; padding-right: 1rem;">Debiteurnr.</td>
                    <td class="py-0.5">{{ $record->billingCustomer->getDebtorNumber() }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
