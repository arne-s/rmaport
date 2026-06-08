@props(['order', 'companyView'])
@php
    use App\Models\OrderProduct;

    /** @var \App\Models\Order\BaseOrder $order */
    $order = $order ?? null;
@endphp

<table class="products">
    <thead>
    <tr>
        <th>Aantal</th>
        <th>Product en specificaties</th>
        @if ($companyView)
            <th class="price">Inkoop
                <div class="vat">(excl. BTW)</div>
            </th>
            <th class="price">Verkoop
                <div class="vat">(excl. BTW)</div>
            </th>
            <th class="price">Marge
                <div class="vat">(excl. BTW)</div>
            </th>
        @else
            <th class="price">Inkoop
                <div class="vat">(excl. BTW)</div>
            </th>
            <th class="price">Verkoop
                <div class="vat">(excl. BTW)</div>
            </th>
            <th class="price">Marge
                <div class="vat">(excl. BTW)</div>
            </th>
        @endif
    </tr>
    </thead>

    @foreach ($order->orderProducts as $orderProduct)
        <tbody class="regular-product">
        @php /** @var OrderProduct $orderProduct **/ @endphp
        @include('order._margins_table_row', [
            'type' => 'regular',
            'name' => $orderProduct->getValue(),
            'qty' => $orderProduct->getQty(),
            'companyPurchasePrice' => $orderProduct->getCompanyPurchasePriceTotal(),
            'companySalesPrice' => $orderProduct->getCompanySalesPriceTotal(),
        ])
        </tbody>
    @endforeach
    <tbody class="totals">
    @if (
        $order->getCompanyPurchasePriceDiscount() <> 0 ||
        $order->getCompanySalesPriceDiscount() <> 0
    )
        <tr class="totals">
            <td colspan="2"><strong>Subtotaal:</strong></td>
            @if (!$companyView)
                <td>@money($order->getCompanyPurchasePriceBase())</td>
                <td>@money($order->getCompanySalesPriceBase())</td>
                <td class="spMargin">
                    @money($order->getCompanySalesPriceBase()-$order->getCompanyPurchasePriceBase())
                    {{ formatMarginPercentage($order->getCompanySalesPriceBase(),$order->getCompanyPurchasePriceBase()) }}
                </td>
            @else
                <td>@money($order->getCompanySalesPriceTotal())</td>
            @endif
        </tr>
        <tr>
            <td colspan="2"><strong>Korting:</strong></td>
            @if (!$companyView)
                <td>@money($order->getCompanyPurchasePriceDiscount())</td>
                <td>@money($order->getCompanySalesPriceDiscount())</td>
                <td class="spMargin"></td>
            @else
                <td>@money($order->getCompanySalesPriceTotal())</td>
            @endif
        </tr>
    @endif
    <tr class="totals">
        <td colspan="2"><strong>Totaal:</strong></td>

        @if (!$companyView)
            <td>@money($order->getCompanyPurchasePriceTotal())</td>
            <td>@money($order->getCompanySalesPriceTotal())</td>
            <td class="spMargin">
                @money($order->getCompanySalesPriceTotal()-$order->getCompanyPurchasePriceTotal())
                {{ formatMarginPercentage($order->getCompanySalesPriceTotal(),$order->getCompanyPurchasePriceTotal()) }}
            </td>
        @else
            <td>@money($order->getCompanySalesPriceTotal())</td>
        @endif
    </tr>
    </tbody>

</table>
<style>
    tr {
        page-break-inside: avoid;
        page-break-after: always
    }

    tr.is-parent td.name {
        font-weight: bold;
    }

    table tr {
        page-break-inside: avoid;
        page-break-after: always
    }

    table thead {
        display: table-header-group;
    }

    table thead th.price {
        font-size: 11px;
    }

    table thead th div.vat {
        font-weight: normal;
        font-size: 10px;
    }

    table tfoot {
        display: table-footer-group;
    }

    table.products {
        margin-top: 25px;
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
    }

    table.products th {
        font-size: 12px;
        white-space: nowrap;
        padding: 10px 10px 5px 0;
        text-align: left;
        font-weight: bold;
        border-bottom: 2px solid #212121;
    }

    table.products > tbody > tr > td {
        vertical-align: top;
        padding: 8px 8px 8px 0;
        line-height: 16px;
        font-size: 11px;
    }

    table.products > tbody > tr.child > td {
        padding: 5px 0;
    }

    table.products > tbody > tr.line > td {
        border-bottom: 2px solid #D5D5D5;
    }

    table.products tr td:last-child {
        text-align: right;
        padding-right: 20px;
        padding-left: 0;
        white-space: nowrap;
    }

    table.products tr > th:last-child,
    table.products tr > td:last-child {
        padding-right: 0;
    }

    table.products th:last-child {
        text-align: right;
    }

    tr.type-main td.name {
        font-weight: bold;
    }

    tr.type-regular td.name {
        font-weight: bold;
    }

    div.indent {
        padding-left: 15px;
        line-height: 22px;
        font-size: 11px;
    }

    tr.type-main {
        border-top: 2px solid #D5D5D5;
    }

    tr.totals {
        border-top: 2px solid #D5D5D5;
    }

    tbody.regular-product {
        border-top: 2px solid #D5D5D5;
    }

    tbody.totals td strong {
        font-size: 12px;
    }
</style>
