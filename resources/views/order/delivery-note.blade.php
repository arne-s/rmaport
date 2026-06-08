@php
    use App\Models\Customer;

    $rdCustomer = Customer::getRdMobilityCustomer();
    $rdAddr = $rdCustomer?->billingAddress;

    $recipientAddress = $order?->shippingCustomer?->shippingAddress ?? $order?->customer?->shippingAddress;

    $billingInvoiceName = $order->getBillingInvoiceDisplayName();
    $deliveryNoteCustomerName = $billingInvoiceName !== ''
        ? $billingInvoiceName
        : ($order->getCustomerAddressDisplayName() ?: $recipientAddress?->getName() ?: '-');
@endphp

@extends('order.order_layout')
@section('content')
    <div class="wrap delivery-note-document">
        <div style="float: left; width: 50%; padding-bottom: 5px">
            <div style="text-align: left; margin-bottom: 8px;">
                @if (! empty($qrDataUri))
                    <img src="{{ $qrDataUri }}" alt="QR" width="100" height="100" style="display: inline-block;">
                @endif
            </div>

            @if ($recipientAddress)
                <div class="recipient-info" style="margin-bottom: 60px">
                    <strong>{{ $deliveryNoteCustomerName }}</strong><br>
                    {{ $recipientAddress->getStreet() }} {{ $recipientAddress->getHouseNumber() }}{{ $recipientAddress->getHouseNumberAddition() ? ' ' . $recipientAddress->getHouseNumberAddition() : '' }}<br>
                    {{ $recipientAddress->getPostcode() }} {{ $recipientAddress->getCity() }}<br>
                    @if ($recipientAddress->country?->name)
                        {{ $recipientAddress->country->name }}<br>
                    @endif
                </div>
            @endif

            <h2>Pakbon {{ $deliveryNote->uid }}</h2>

            @php
                $leftCols = [
                    'Aanvraagnummer' => $main->getUidFormatted() ?: '-',
                    'Ordernummer' => $order->getUidFormatted() ?: '-',
                    'Orderdatum' => $order->getOrderDate()->format('d-m-Y'),
                    'Adviseur' => $order->advisor?->getName() ?? '—',
                ];
            @endphp

            @include('order._meta_info', [
                'list' => [$leftCols],
                'boldKeys' => [],
            ])
        </div>

        <div style="float: right; width: 50%; padding-bottom: 5px; text-align: right;">
            <div class="company-info" style="text-align: left; line-height: 28px; display: inline-block; min-width: 260px; vertical-align: top;">
                @include('order._rd_logo', ['variant' => 'inline-top-right'])
                <div style="margin-top: 30px;">
                    <div>
                        <strong style="font-size: 16px;">{{ $rdCustomer?->getName() }}</strong>
                    </div>
                    @if ($rdCustomer)
                        <div style="padding: 0; font-size: 16px;">
                            @if ($rdAddr)
                                {{ $rdAddr->getStreet() }} {{ $rdAddr->getHouseNumber() }}{{ $rdAddr->getHouseNumberAddition() ? ' ' . $rdAddr->getHouseNumberAddition() : '' }}<br/>
                                {{ $rdAddr->getPostcode() }} {{ $rdAddr->getCity() }}<br/>
                                {{ $rdAddr->country?->name ?? '' }}<br/><br/>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div style="clear: both; padding-top: 10px"></div>
    </div>

    <table class="products products-header" style="width: 100%; margin-top: 5px; margin-bottom: 0;">
        <colgroup>
            <col class="col-productnr" style="width: 18%;">
            <col class="col-product" style="width: 62%;">
            <col class="col-qty" style="width: 20%;">
        </colgroup>
        <tbody>
        <tr>
            <th class="productnr" scope="col">Artikelnummer</th>
            <th class="product" scope="col">Omschrijving</th>
            <th class="qty" scope="col">Geleverd</th>
        </tr>
        </tbody>
    </table>

    <table class="products products-body" style="width: 100%; margin-top: 0;">
        <colgroup>
            <col class="col-productnr" style="width: 18%;">
            <col class="col-product" style="width: 62%;">
            <col class="col-qty" style="width: 20%;">
        </colgroup>
        <tbody>
        @foreach ($products as $orderProduct)
            @php
                $product = $orderProduct->product;
                $articleNumber = $product && method_exists($product, 'getUidFormatted')
                    ? $product->getUidFormatted()
                    : ($product->uid ?? $orderProduct->product_id);
            @endphp
            <tr class="line">
                <td>{{ $articleNumber }}</td>
                <td>{{ $orderProduct->getValue() ?? ($product?->name ?? '-') }}</td>
                <td>{{ number_format((float) $orderProduct->getQty(), 2, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <style>
        div.order-wrapper {
            padding: 30px 60px 10px 60px;
        }

        table.products {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
        }

        table.products tbody {
            display: table-row-group;
        }

        table.products-header {
            margin-bottom: 0;
        }

        table.products-body {
            margin-top: 0;
        }

        table.products th {
            background: #efefef;
            border-bottom: 1px solid #000;
        }

        table.products td,
        table.products th {
            text-align: left;
            vertical-align: top;
            padding: 6px 5px;
            font-size: 13px;
        }
    </style>
@endsection
