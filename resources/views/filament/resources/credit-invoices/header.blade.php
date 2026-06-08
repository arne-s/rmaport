@php
    $company = App\Models\Customer::getRdMobilityCustomer();
    $invoice = $this->record;
    $parentInvoice = $invoice->invoice;
    $parentOrder = $invoice->order;
@endphp
<section id="invoice-header">
    <div style="float: left; width: 50%;">
        <div>
            @include('order._company_logo', ['company' => $company])
            @include('order._recipient_info', ['order' => $invoice])
        </div>
        <h2 style="margin-top: 15px; margin-bottom: 5px; font-size: 24px; font-weight: bold;">Creditfactuur</h2>

        @if ($parentInvoice)
            <p style="padding-bottom: 15px;">Creditnota bij factuurnummer: #{{ $parentInvoice->getUidFormatted() }}</p>
        @endif

        @php
            $leftCols = [
                'Factuurnummer' => $invoice->uid ? $invoice->getUidFormatted() : '(wordt automatisch gegenereerd)',
                'Factuurdatum' => $invoice->getCreatedAt()?->format('d-m-Y') ?? '-',
            ];

            if ($invoice->billingCustomer) {
                $leftCols['Debiteurnummer'] = $invoice->billingCustomer->getDebtorNumber();
            }

            $rightCols = [];
            if ($parentOrder) {
                $rightCols['Ordernummer'] = $parentOrder->getUidFormatted();
                $rightCols['Orderdatum'] = $parentOrder instanceof \App\Models\Order\Order
                    ? $parentOrder->getOrderDate()->format('d-m-Y')
                    : ($parentOrder->getCreatedAt()?->format('d-m-Y') ?? '-');
            }
        @endphp

        @include('order._meta_info', [
            'list' => !empty($rightCols) ? [$leftCols, $rightCols] : [$leftCols],
            'boldKeys' => [],
        ])
    </div>

    <div style="float: right; margin-top: -20px">
        @include('order._company_info', ['company' => $company])
    </div>
    <div style="clear: both;"></div>
</section>
