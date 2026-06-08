@props(['type' => null, 'useCompanyPrices' => false])

@php
    use App\Models\Order\BaseOrder;
    use App\Models\Order\Order;
    use App\Models\OrderProduct;

    global $total;

    /** @var BaseOrder $order */
    $order = $order ?? null;

    $isPackingSlip = $type === 'packing_slip';
    $isCreditInvoice = $order?->getType() === 'credit_invoice';
    $showThumb = ($order->getType() === 'order' ||  $order->getType() === 'quote') && !$isPackingSlip;

    $taxSummary = [];
    $subtotal = 0;
@endphp
<table class="products">
    <thead>
    <tr>
        <th>Aantal</th>
        <th>Product en specificaties</th>
        @if ($showThumb)
            <th class="product-image" style="width: 120px"></th>
        @endif
        @if ($isPackingSlip)
            <th>Leverwijze</th>
        @else
            <th>Prijs <span style="font-weight: normal; font-size: 10px;">(excl. BTW)</span></th>
            <th>BTW</th>
            <th style="text-align: right">Totaal</th>
        @endif
    </tr>
    </thead>

    <tbody>
    @foreach ($order->orderProducts as $orderProduct)
        @if ($isCreditInvoice && !$orderProduct->getHasCredit())
        @else
            <tr class="line">

                <td>{{ $orderProduct->getQty() }}</td>
                <td class="product"><strong>{{ $orderProduct->getValue() }}</strong><br/>
                    <div style="
                            padding-left: 15px;
                            line-height: 22px;
                            font-size: 11px;
                        ">
                        @php /* @var OrderProduct $orderProduct */ @endphp

                        @if ($orderProduct->order->getIsAdminGenerated())
                            {!! nl2br($orderProduct->getAttributeSummaryBasic()) !!}
                        @else
                            @php
                                $attributeSummary = $useCompanyPrices
                                    ? $orderProduct->getAttributeSummaryCompany()
                                    : $orderProduct->attributeSummary();
                            @endphp
                            @foreach ($attributeSummary ?? [] as $attr => $val)
                                @php
                                    if ($isPackingSlip) {
                                        if ($attr === 'Basisprijs product') {
                                            continue;
                                        }
                                        // Remove the price from the attribute value
                                        $val = preg_replace('/ \(\+€.+?\)/', '', $val);
                                    }
                                @endphp

                                {{ $attr }}: {!! htmlentities($val) !!}<br/>
                            @endforeach

                        @endif
                    </div>
                </td>

                @if ($showThumb)
                    <td class="product-image">
                        @php
                            $thumbnail = $orderProduct->getImage('medium-large');
                        @endphp
                        @if (!empty($thumbnail))
                            <img src="{{ $thumbnail }}"
                                 style="max-width: 100px; margin: 5px; border: 1px solid #c0c0c0" alt=""/>
                        @endif
                    </td>
                @endif

                @if ($isPackingSlip)
                    <td>{{ $orderProduct->getFulfillmentType()?->getLabel() ?? '' }}</td>
                @else
                    <td>

                        @if ($isCreditInvoice)
                            @money(-1 * $orderProduct->getPriceIncludedProducts())
                        @elseif ($useCompanyPrices)
                            @money($orderProduct->getCompanyPriceIncludedProducts())
                        @else
                            @money($orderProduct->getPriceIncludedProducts())
                        @endif
                    </td>

                    @php
                        $vatAmount = $useCompanyPrices
                            ? $orderProduct->getCompanyPriceIncludedProducts() * $orderProduct->getVat() / 100
                            : $orderProduct->getPriceIncludedProducts() * $orderProduct->getVat() / 100;
                    @endphp

                    <td style="white-space: nowrap">
                        @if ($orderProduct->getVat() > 0)
                            @if ($isCreditInvoice)
                                @money(-1 * $vatAmount)
                            @else
                                @money($vatAmount)
                            @endif
                        @else
                            BTW verlegd
                        @endif
                    </td>

                    <td class="last">
                        @if ($isCreditInvoice)
                            @money(-1 * Order::incVat($orderProduct->getPriceIncludedProducts(), $orderProduct->getVat()))
                        @elseif ($useCompanyPrices)
                            @money(Order::incVat($orderProduct->getCompanyPriceIncludedProducts(), $orderProduct->getVat()))
                        @else
                            @money(Order::incVat($orderProduct->getPriceIncludedProducts(), $orderProduct->getVat()))
                        @endif
                    </td>

                    @php
                        if (!isset($taxSummary[$orderProduct->getVat()])) {
                            $taxSummary[$orderProduct->getVat()] = 0;
                        }

                        $taxSummary[(string) $orderProduct->getVat()] += ($isCreditInvoice ? -1 : 1)
                            * ($useCompanyPrices && !$isCreditInvoice
                                ? $orderProduct->getCompanyPriceIncludedProducts()
                                : $orderProduct->getPriceIncludedProducts()
                            )
                            * $orderProduct->getVat()/100;

                        $subtotal += ($isCreditInvoice ? -1 : 1)
                            * ($useCompanyPrices && !$isCreditInvoice
                                ? $orderProduct->getCompanyPriceIncludedProducts()
                                : $orderProduct->getPriceIncludedProducts()
                            );
                    @endphp
                @endif
            </tr>


        @endif
    @endforeach

    @if (!$isPackingSlip)
        <tr>
            <td colspan="2"></td>
            <td colspan="5" align="right">
                <table class="totals">
                    <tr>
                        <td>Subtotaal <span style="font-weight: normal; font-size: 10px;">(incl. BTW)</span></td>
                        <td>
                            @if ($isCreditInvoice)
                                @money(Order::incVat($subtotal))
                            @else
                                @php
                                    $companyDiscountIncSubtotal = abs($order->getCompanySalesPriceDiscount()) > 0.00001
                                        ? Order::incVat($order->getCompanySalesPriceDiscount())
                                        : 0.0;
                                @endphp
                                @money($order->getCompanySalesPriceTotalIncVat() - $companyDiscountIncSubtotal)
                            @endif
                        </td>
                    </tr>
                    @if ($order->getCompanySalesPriceDiscount() <> 0)
                        @php
                            $taxSummary[21] += $order->getCompanySalesPriceDiscount() * 0.21;
                        @endphp
                        <tr class="line">
                            <td>Korting <span style="font-weight: normal; font-size: 10px;">(incl. BTW)</span><br/>
                                @if ($order->getDiscountComment())
                                    <span class="sub">Reden: <em>{{ $order->getDiscountComment() }}</em></span>
                                @endif
                            </td>
                            <td>
                                @money(Order::incVat($order->getCompanySalesPriceDiscount()))
                            </td>
                        </tr>
                    @endif
                    <tr class="line">
                        <td><strong>Totaalbedrag <span style="font-weight: normal; font-size: 10px;">(incl. BTW)</span></strong></td>
                        <td>
                            @if ($isCreditInvoice)
                                @money($order->getPaymentAmount())
                            @else
                                @money($order->getCompanySalesPriceTotalIncVat())
                            @endif
                        </td>
                    </tr>

                    @foreach ($taxSummary as $vat => $amount)
                        @if ($amount <> 0)
                            <tr>
                                <td>{{$vat}}% BTW</td>
                                <td>@if ($vat > 0)
                                        @money($amount)
                                    @else
                                        BTW verlegd
                                    @endif</td>
                            </tr>
                        @endif
                    @endforeach

                    @if ($order->getType() === 'deposit_invoice')
                        <tr class="line">
                            <td><strong>Aanbetaling (50%):</strong></td>
                            <td>
                                @if ($isCreditInvoice)
                                    @money(-1 * $order->getPaymentAmount())
                                @else
                                    @money($order->getPaymentAmount())
                                @endif
                            </td>
                        </tr>
                    @endif

                    @if ($order->getType() === 'invoice' && $order->getDepositAmount() > 0)
                        <tr class="line">
                            <td>Aanbetaald (50%):</td>
                            <td>
                                @if ($isCreditInvoice)
                                    @money(-1 * $order->getDepositAmount())
                                @else
                                    @money($order->getDepositAmount())
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Te betalen:</strong></td>
                            <td>@money($order->getPaymentAmount())</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    @endif
    </tbody>
</table>


<style>
    @if (count($taxSummary) >  1)
        #vat-header {
        display: none;
    }
    @endif

    tr {
        page-break-inside: avoid;
        page-break-after: always
    }

    table tr {
        page-break-inside: avoid;
        page-break-after: always
    }

    table thead {
        display: table-header-group;
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
        padding: 10px 0;
        line-height: 22px;
        font-size: 11px;
    }

    table.products > tbody > tr.line > td {
        border-bottom: 2px solid #D5D5D5;
    }

    table.products td.last {
        text-align: right;
        padding-right: 20px;
        padding-left: 0;
        white-space: nowrap;
    }

    table.products tr > th:last-child,
    table.products tr > td:last-child {
        padding-right: 0;
    }

    @if (!$isPackingSlip)
        table.products th:last-child {
        text-align: right;
    }
    @endif

    table.totals {
        border-collapse: collapse;
        width: 300px;
    }

    table.totals td {
        font-size: 12px;
    }

    table.totals span.sub {
        font-size: 10px;
    }

    table.totals tr.line td {
        border-top: 2px solid #D5D5D5;
        padding-top: 5px;
    }

    table.totals tr td:last-child {
        text-align: right;
    }
</style>
