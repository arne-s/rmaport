@php
    use App\Models\Customer;

    $rdCustomer = Customer::getRdMobilityCustomer();
    $rdAddr = $rdCustomer?->billingAddress;
    $supplier = $order->supplier ?? null;
    $ref = method_exists($order, 'getReferenceNumber')
        ? $order->getReferenceNumber()
        : ($order->reference ?? $order->reference_number ?? $order->getUidFormatted() ?? null);
    $showConfirmationAndInvoice = $showConfirmationAndInvoice ?? true;
    $orderDate = $orderDate ?? ($order->getSentAt()?->format('d-m-Y') ?? '-');
@endphp

@extends('order.order_layout')
@section('content')

    <div class="wrap">
        <div style="float: left; width: 50%;padding-bottom:5px">
            @include('order._rd_logo')

            @if ($supplier)
                <div class="recipient-info" style="margin-bottom: 60px">
                    @php
                        $supplierContactName = trim((string) ($supplier->getFullName() ?? ''));
                    @endphp
                    <strong>{{ $supplier->getAdminField('company') ?: $supplier->name }}</strong>
                    <br>
                    @if ($supplierContactName !== '')
                        T.a.v. {{ $supplierContactName }}<br>
                    @endif
                    {{ $supplier->street }} {{ $supplier->house_number }}<br>
                    {{ $supplier->postcode }} {{ $supplier->city }}<br>
                    @if ($supplier->country?->name)
                        {{ $supplier->country->name }}<br>
                    @endif
                </div>
            @endif

            <h2>Inkooporder</h2>

            @php
                if (method_exists($order, 'getAddressFormatted')) {
                    $leveringsadresMeta = (string) $order->getAddressFormatted();
                } else {
                    $deliveryAddress = data_get($order, 'additional.delivery_address', []);
                    $parts = array_filter([
                        trim((string) data_get($deliveryAddress, 'shipping_name', '')),
                        trim((string) data_get($deliveryAddress, 'street', '')),
                        trim((string) data_get($deliveryAddress, 'house_number', '')),
                        trim((string) data_get($deliveryAddress, 'house_number_addition', '')),
                        trim((string) data_get($deliveryAddress, 'postcode', '')),
                        trim((string) data_get($deliveryAddress, 'city', '')),
                    ], fn ($value): bool => $value !== '');

                    $leveringsadresMeta = implode(' ', $parts);
                }

                $leftCols = [];
                $leftCols['Referentie'] = $ref ?: '-';
                $leftCols['Orderdatum'] = $orderDate;

                if ($leveringsadresMeta !== '') {
                    $leftCols[] = '&nbsp;';
                }
                $rightCols = $showConfirmationAndInvoice ? [
                    'Bevestiging naar' => 'inkoop@rdmobility.com',
                    'Factuur naar' => 'factuur@rdmobility.com',
                ] : [];

            @endphp

            @include('order._meta_info', [
                'list' => $showConfirmationAndInvoice ? [$leftCols, $rightCols] : [$leftCols],
                'boldKeys' => ['Referentie'],
            ])

            @if ($leveringsadresMeta !== '')
                <strong style="display: inline-block; white-space: nowrap">Leveringsadres:
                    &nbsp;&nbsp;{{ $leveringsadresMeta }}</strong>
            @endif
        </div>

        <div style="float: right">
            <div class="company-info"
                 style="text-align: left; line-height: 28px; display: inline-block; min-width: 260px;">
                <div style="padding: 6px 0; font-size: 16px;"><strong
                        style="font-size: 16px;">{{ $rdCustomer?->getName() }}</strong></div>
                @if ($rdCustomer)
                    <div style="padding: 6px 0; font-size: 16px;">
                        @if ($rdAddr)
                            {{ $rdAddr->getStreet() }} {{ $rdAddr->getHouseNumber() }}{{ $rdAddr->getHouseNumberAddition() ? ' ' . $rdAddr->getHouseNumberAddition() : '' }}
                            <br/>
                            {{ $rdAddr->getPostcode() }} {{ $rdAddr->getCity() }}<br/>
                        @endif
                    </div>

                    <div style="padding: 6px 0;">
                        @if ($rdCustomer->getKvk())
                            KvK: {{ $rdCustomer->getKvk() }}<br/>
                        @endif
                        @if ($rdCustomer->getVat())
                            BTW: {{ $rdCustomer->getVat() }}<br/>
                        @endif
                        @if ($rdCustomer->getIban())
                            IBAN: {{ $rdCustomer->getIban() }}<br/>
                        @endif
                    </div>
                @endif
            </div>
        </div>
        <div style="clear: both; padding-top: 10px"></div>
    </div>

    @php
        $hasDeliveryAddresses = $products->contains(fn ($product) => !empty($product->getDeliveryAddress()));
    @endphp
    @if ($hasDeliveryAddresses)
        <span><strong>LET OP:</strong> Afwijkende afleveradressen staan vermeld bij de artikelspecificaties.</span>
    @endif

    <table class="products" style="width: 100%; margin-top: 5px; ">
        <thead>
        <tr>
            <th class="productnr">Artikelnummer</th>
            <th class="qty">Aantal</th>
            <th class="unit">Eenheid</th>
            <th class="product">Artikel</th>
            <th class="specs">Specificaties</th>
        </tr>
        </thead>

        <tbody>
        @foreach ($products as $orderProduct)
            <tr class="line">
                <td>{{ $orderProduct->product->getSupplierProductUid() }}</td>
                <td>{{ $orderProduct->getQty() }}</td>
                <td>{{ $orderProduct->product->getUnit()->getLabel() }}</td>
                <td>{{ $orderProduct->getValue() }}</td>

                <td>
                    @if (!empty($orderProduct->getAttributeSummaryBasic()))
                        {!! nl2br($orderProduct->getAttributeSummaryBasic()) !!}
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php
        $commentBlock = 'Indien er vragen zijn over deze bestelling, neem dan contact op met <a href="mailto:inkoop@rdmobility.com">inkoop@rdmobility.com</a> <br>Of via tel: <span style="text-decoration: underline; font-weight: normal">'.($rdCustomer->getPhoneNumber() ?? '').'</span>';
    @endphp
    @include('order._comment_block', ['content' => $commentBlock])

    <style>
        div.order-wrapper {
            padding: 30px 60px 10px 60px;
        }

        table.products th {
            background: #efefef;
        }

        table.products td, table.products th {
            text-align: left;
            vertical-align: top;
            padding: 10px 5px;
        }

        table.products td div.comment {
            border: 1px dashed #000;
            padding: 5px;
            font-size: 12px;
            margin-top: 10px;
        }

        th.productnr {
            width: 105px;
        }

        th.qty {
            width: 45px;
        }

        th.unit {
            width: 55px;
        }

        th.product {
            min-width: 350px;
        }

        th.specs {
            min-width: 130px;
        }


    </style>
@endsection
