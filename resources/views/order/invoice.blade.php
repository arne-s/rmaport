@php
    use App\Enums\OrderType;
    use App\Models\Customer;
    use App\Models\ExactPaymentCondition;

    $rdCustomer = Customer::getRdMobilityCustomer();
    $rdAddr = $rdCustomer?->billingAddress;
    $customer = $order->customer;
    $parentOrder = $order->order;
    $main = $order->main ?? $parentOrder?->main;
    $isDeposit = $order->getType() === OrderType::DepositInvoice;
    $isCredit = $order->getType() === OrderType::CreditInvoice;
    $invoiceTitle = $isCredit
        ? 'Creditfactuur'
        : ($order->caption instanceof \App\Enums\InvoiceCaption
            ? $order->caption->getLabel()
            : ($isDeposit ? 'Aanbetalingsfactuur' : 'Factuur'));
    $invoiceDateCarbon = $order->getSentAt() ?? $order->updated_at;
    $invoiceDate = $invoiceDateCarbon?->format('d-m-Y') ?? '-';
    $paymentPercentage = $order->getPaymentPercentage() ?? 100;
    $paymentConditionCode = ($order->getAdditional() ?? [])['exact_payment_condition']
        ?? ($parentOrder?->getAdditional() ?? [])['exact_payment_condition']
        ?? null;
    $paymentCondition = $paymentConditionCode
        ? \App\Models\ExactPaymentCondition::where('code', $paymentConditionCode)->first()
        : null;
    $conditionTableDays = $paymentCondition?->payment_days;
    $conditionTableDaysInt = $conditionTableDays !== null ? (int) $conditionTableDays : null;
    $paymentConditionApplies = $paymentConditionCode !== ExactPaymentCondition::NOT_APPLICABLE_CODE
        && $conditionTableDaysInt !== null
        && $conditionTableDaysInt > 0;

    if ($order->getExpiresAt()) {
        $expiresAtDisplay = $order->getExpiresAt()->format('d-m-Y');
        $billingTermDaysForLabel = $invoiceDateCarbon
            ? (int) $invoiceDateCarbon->copy()->startOfDay()->diffInDays($order->getExpiresAt()->copy()->startOfDay())
            : ($conditionTableDaysInt !== null && $conditionTableDaysInt > 0 ? $conditionTableDaysInt : null);
    } elseif ($conditionTableDaysInt !== null && $conditionTableDaysInt > 0 && $invoiceDateCarbon) {
        $expiresAtDisplay = $invoiceDateCarbon->copy()->addDays($conditionTableDaysInt)->format('d-m-Y');
        $billingTermDaysForLabel = $conditionTableDaysInt;
    } else {
        $expiresAtDisplay = '-';
        $billingTermDaysForLabel = null;
    }

    $invoicePaymentLinkUrl = $order->getPaymentLink();
@endphp

@extends('order.order_layout')
@section('content')

    <div class="wrap">
        <div style="float: left; width: 60%; padding-bottom: 5px">
            <div style="margin-bottom: -20px; margin-top: -15px">
                @include('order._rd_logo')
            </div>

            <div class="recipient-info" style="margin-bottom: 35px; line-height: 8px;">
                <strong>Factuuradres</strong><br>
                {!! $order->billingCustomer?->billingAddress?->getAddressTemplateIncNameFormatted() ?? '' !!}
            </div>

            <table style="width: 100%; margin-bottom: 15px; border-collapse: collapse;">
                <tr>
                    <td class="recipient-info customer"
                        style="width: 60%; vertical-align: top; padding: 0 15px 0 0; font-size: 13px; line-height: 8px;">
                        <strong>Klant</strong><br>
                        {!! $order->getCustomerAddress()?->getAddressTemplateIncNameFormatted() ?? '' !!}
                    </td>

                    <td class="recipient-info shipping"
                        style="width: 50%; vertical-align: top; padding: 0; font-size: 13px;">
                        <strong>Leveradres</strong><br>
                        {!! $order->shippingAddress?->getAddressTemplateIncNameFormatted() ?? '' !!}
                    </td>
                </tr>
            </table>

            <h2 style="font-size: 25px; margin-bottom: 5px; margin-top: 30px">{{ $invoiceTitle }}</h2>
            @if ($isCredit && $order->invoice)
                <p style="font-size: 13px; margin-bottom: 10px;">Creditnota bij factuurnummer: #{{ $order->invoice->getUidFormatted() }}</p>
            @endif
            @php
                $sourceOrder = $parentOrder ?? $order;
                $additional = $sourceOrder->getAdditional() ?? [];
                $deliveryTimeRaw = $additional['delivery_time'] ?? null;
                $deliveryTime = $deliveryTimeRaw
                    ? (\App\Enums\DeliveryTime::tryFrom($deliveryTimeRaw)?->getLabel() ?? $deliveryTimeRaw)
                    : null;

                $invoiceReference = trim((string) ($order->order_reference ?? ''));
                $invoiceComment = trim((string) ($order->getOrderComment() ?? ''));

                $uwReferentie = null;
                if ($invoiceReference !== '') {
                    $uwReferentie = $invoiceReference;
                } elseif ($main && filled(trim((string) ($main->getReference() ?? '')))) {
                    $uwReferentie = trim((string) $main->getReference());
                }

                $leftCols = ['Factuurnummer' => $order->getUidFormatted() ?: '-'];
                if ($uwReferentie !== null) {
                    $leftCols['Uw referentie'] = $uwReferentie;
                }
                $leftCols['Factuurdatum'] = $invoiceDate;
                if (! $isCredit && $paymentConditionApplies) {
                    $leftCols['Vervaldatum'] = $expiresAtDisplay;
                }

                $rightCols = [];

                if (! $isCredit) {
                    if ($invoiceComment !== '') {
                        $rightCols['Opmerking'] = $invoiceComment;
                    }

                    $rightCols['Verkoper'] = $sourceOrder->resolveSellerDisplayName() ?? '—';
                }

                if ($main !== null) {
                    $advisorName = $main->advisor?->getName();
                    if ($advisorName) {
                        $rightCols['Adviseur'] = $advisorName;
                    }
                }

                if ($parentOrder) {
                    $rightCols['Ordernummer'] = $parentOrder->getUidFormatted();
                }

                if (! $isCredit) {
                    $paymentTermsLabel = $sourceOrder->payment_terms instanceof \App\Enums\PaymentTerms
                        ? $sourceOrder->payment_terms->getLabel()
                        : null;
                    $paymentConditionLabel = $paymentCondition?->name;

                    if ($deliveryTime) {
                        $rightCols['Levertijd'] = $deliveryTime;
                    }
                    if ($paymentTermsLabel) {
                        $rightCols['Betalingsvoorwaarden'] = $paymentTermsLabel;
                    }
                    if ($paymentConditionApplies && $paymentConditionLabel) {
                        $rightCols['Betalingsconditie'] = $paymentConditionLabel;
                    }
                }
            @endphp
        </div>

        <div style="float: right">
            <div class="company-info"
                 style="text-align: left; line-height: 22px; display: inline-block; min-width: 260px; font-size: 12px; line-height: 20px;">
                <div><strong style="font-size: 13px; line-height: 20px">{{ $rdCustomer?->getName() }}</strong></div>
                @if ($rdAddr)
                    <div style="padding-bottom: 6px; font-size: 13px; line-height: 20px;">
                        {{ $rdAddr->getStreet() }} {{ $rdAddr->getHouseNumber() }}{{ $rdAddr->getHouseNumberAddition() ? ' ' . $rdAddr->getHouseNumberAddition() : '' }}
                        <br/>
                        {{ $rdAddr->getPostcode() }} {{ $rdAddr->getCity() }}<br/>
                        {{ $rdAddr->country?->name }}
                    </div>
                @endif
                @if ($rdCustomer)
                    <table style="border-collapse: collapse; font-size: 13px" class="company-info">
                        @if ($rdCustomer->getPhoneNumber())
                            <tr>
                                <td style="padding: 0 8px 0 0; white-space: nowrap;">Tel:</td>
                                <td style="padding: 0;">{{ $rdCustomer->getPhoneNumber() }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td style="padding: 0 8px 0 0; white-space: nowrap;">Email:</td>
                            <td style="padding: 0;">info@rdmobility.com</td>
                        </tr>
                        <tr>
                            <td style="padding: 0 8px 6px 0; white-space: nowrap;">Website:</td>
                            <td style="padding: 0 0 6px 0;">www.rdmobility.com</td>
                        </tr>
                        @if ($rdCustomer->getVat())
                            <tr>
                                <td style="padding: 0 8px 0 0; white-space: nowrap;">BTW:</td>
                                <td style="padding: 0;">{{ $rdCustomer->getVat() }}</td>
                            </tr>
                        @endif
                        @if ($rdCustomer->getIban())
                            <tr>
                                <td style="padding: 0 8px 0 0; white-space: nowrap;">IBAN:</td>
                                <td style="padding: 0;">{{ $rdCustomer->getIban() }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td style="padding: 0 8px 0 0; white-space: nowrap;">BIC:</td>
                            <td style="padding: 0;">INGBNL2A</td>
                        </tr>
                        @if ($rdCustomer->getKvk())
                            <tr>
                                <td style="padding: 0 8px 0 0; white-space: nowrap;">KvK:</td>
                                <td style="padding: 0;">{{ $rdCustomer->getKvk() }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td colspan="2" style="padding: 8px 0 0 0;">

                            </td>
                        </tr>
                    </table>
                @endif
            </div>
        </div>
        <div style="clear: both; padding-top: 10px"></div>
    </div>


    @include('order._meta_infov2', [
        'list' => ! empty($rightCols) ? [$leftCols, $rightCols] : [$leftCols],
        'boldKeys' => [],
    ])


    @php
        $showDiscountColumn = ! $isCredit && $products->contains(
            fn ($op) => (float) $op->getCompanySalesPriceDiscountPercentage() !== 0.0
        );
    @endphp

    <table class="products" style="width: 100%; margin-top: 5px;">
        <thead>
        <tr>
            <th class="productnr">Artikelnummer</th>
            <th class="product">Omschrijving</th>
            <th class="qty">Aantal</th>
            @if ($isCredit)
                <th class="price">Eenheidsprijs €</th>
                <th class="netto" style="text-align: right">Nettoprijs €&nbsp;</th>
            @else
                <th class="price">Eenheidsprijs €</th>
                @if ($showDiscountColumn)
                    <th class="discount">Korting</th>
                @endif
                <th class="netto" style="text-align: right">Nettoprijs €&nbsp;</th>
            @endif
        </tr>
        </thead>

        <tbody>
        @foreach ($products as $orderProduct)
            @if ($isCredit && !$orderProduct->getHasCredit())
                @continue
            @endif
            @php
                $discountPct = $orderProduct->getCompanySalesPriceDiscountPercentage();
                $specs = trim((string) ($orderProduct->getAttributeSummaryBasic() ?? ''));
                if ($specs === '') {
                    $summary = $orderProduct->getAttributeSummary();
                    if (is_array($summary)) {
                        $specs = trim(arrayToTextareaString($summary));
                    } elseif (is_string($summary)) {
                        $specs = trim($summary);
                    }
                }
                if ($specs === '') {
                    $specs = trim((string) ($orderProduct->product?->getDescription() ?? ''));
                }
            @endphp
            <tr class="line">
                <td>{{ $orderProduct->product?->getUid() }}</td>
                <td>
                    {{ $orderProduct->getValue() }}
                    @if (!empty($specs))
                        <div class="line-specs">{!! nl2br(e($specs)) !!}</div>
                    @endif
                </td>
                <td>{{ number_format($orderProduct->getQty(), 2, ',', '.') }}</td>
                @if ($isCredit)
                    <td style="white-space: nowrap">{{ number_format($orderProduct->getCompanySalesPriceBase(), 2, ',', '.') }}</td>
                    <td style="text-align: right; white-space: nowrap">- {{ number_format(abs($orderProduct->getCompanySalesPriceCredited()), 2, ',', '.') }}</td>
                @else
                    <td style="white-space: nowrap">{{ number_format($orderProduct->getCompanySalesPriceBase(), 2, ',', '.') }}</td>
                    @if ($showDiscountColumn)
                        <td>{{ number_format($discountPct, 2, ',', '.') }}%</td>
                    @endif
                    <td style="text-align: right; white-space: nowrap">{{ number_format($orderProduct->getCompanySalesPriceTotal(), 2, ',', '.') }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>

    @php
        if ($isCredit) {
            $creditedProducts = $products->filter(fn ($op) => $op->getHasCredit());
            $creditedExVat = $creditedProducts->sum(fn ($op) => abs($op->getCompanySalesPriceCredited()));
            $creditDiscount = abs($order->getCompanySalesPriceDiscount());

            $vatByPercentage = [];
            foreach ($creditedProducts as $op) {
                $pct = $op->getVat();
                $key = number_format($pct, 2);
                $vatByPercentage[$key] = ($vatByPercentage[$key] ?? 0) + (abs($op->getCompanySalesPriceCredited()) * ($pct / 100));
            }
            ksort($vatByPercentage);

            $creditedExVatAfterDiscount = $creditedExVat - $creditDiscount;
            $creditVat = collect($vatByPercentage)->sum();
            $creditedIncVat = $creditedExVatAfterDiscount + $creditVat;
        } else {
            $totalExVatFull = $order->getCompanySalesPriceTotal();
            $totalIncVatFull = $order->getCompanySalesPriceTotalIncVat();
            $invoiceAmount = $order->getPaymentAmount();

            $vatByPercentage = [];
            foreach ($products as $op) {
                $pct = $op->getVat();
                $key = number_format($pct, 2);
                $vatByPercentage[$key] = ($vatByPercentage[$key] ?? 0) + ($op->getCompanySalesPriceTotal() * ($pct / 100));
            }
            ksort($vatByPercentage);
        }
    @endphp

    <div style="page-break-inside: avoid;">

        <table class="payment-wrap" style="width: 100%; border-collapse: collapse;">
            <tr>
                @if (!$isCredit && $invoicePaymentLinkUrl)
                    {{-- wkhtmltopdf: anchor+img needs inline-block/block or only part of the image is clickable in PDF --}}
                    <td style="vertical-align: top;padding: 10px 15px 0 0;text-align: left; vertical-align: bottom; position: relative;">
                        <a href="{{ $invoicePaymentLinkUrl }}" target="_blank" rel="noopener noreferrer"
                           class="invoice-payment-link"
                           style="display: inline-block; line-height: 0;">
                            <img src="{{ rtrim(config('app.url'), '/') }}/img/icons/wero7.jpg" alt="iDEAL"
                                 style="display: block; height: 50px; margin-bottom: 42px; border-radius: 5px;">
                        </a>
                    </td>
                @endif
                <td style="width: 350px; vertical-align: top; padding: 0;" align="right">
                    @if ($isCredit)
                        <table class="totals" style="width: 350px; margin-top: 10px; margin-bottom: 35px;">
                            <tr class="subtotal">
                                <td>Subtotaal <span style="font-weight: normal; font-size: 10px;">(excl. BTW)</span></td>
                                <td style="text-align: right; white-space: nowrap">- {{ number_format($creditedExVat, 2, ',', '.') }}</td>
                            </tr>
                            @if ($creditDiscount > 0)
                                <tr>
                                    <td>Korting <span style="font-weight: normal; font-size: 10px;">(excl. BTW)</span></td>
                                    <td style="text-align: right; white-space: nowrap">+ {{ number_format($creditDiscount, 2, ',', '.') }}</td>
                                </tr>
                            @endif
                            @foreach ($vatByPercentage as $pctKey => $vatSum)
                                <tr>
                                    <td>BTW {{ number_format((float) $pctKey, 0) }}%</td>
                                    <td style="text-align: right; white-space: nowrap">- {{ number_format($vatSum, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                            <tr class="grand-total">
                                <td style="font-size: 14px"><strong>Totaal</strong> <span style="font-weight: normal; font-size: 10px;">(incl. BTW)</span></td>
                                <td style="text-align: right; white-space: nowrap"><strong>- {{ number_format($creditedIncVat, 2, ',', '.') }}</strong></td>
                            </tr>
                        </table>
                    @else
                        <table class="totals" style="width: 350px; margin-top: 10px; margin-bottom: 35px;">
                            <tr class="subtotal">
                                <td>Subtotaal <span style="font-weight: normal; font-size: 10px;">(excl. BTW)</span></td>
                                <td style="text-align: right; white-space: nowrap">{{ number_format($totalExVatFull, 2, ',', '.') }}</td>
                            </tr>
                            @foreach ($vatByPercentage as $pctKey => $vatSum)
                                <tr>
                                    <td>BTW {{ number_format((float) $pctKey, 0) }}%</td>
                                    <td style="text-align: right; white-space: nowrap">{{ number_format($vatSum, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="font-size: 14px" class="total-amount"><strong>Totaalbedrag</strong> <span
                                        style="font-weight: normal; font-size: 10px;">(incl. BTW)</span></td>
                                <td style="text-align: right; white-space: nowrap"
                                    class="total-amount">{{ number_format($totalIncVatFull, 2, ',', '.') }}</td>
                            </tr>

                            @if ($isDeposit)
                                <tr class="grand-total">
                                    <td><strong>Aanbetaling ({{ (int) $paymentPercentage }}%)</strong></td>
                                    <td style="text-align: right; white-space: nowrap; margin-top: 0">
                                        <strong>{{ number_format($invoiceAmount, 2, ',', '.') }}</strong></td>
                                </tr>
                            @elseif ($order->getDepositAmount() > 0)
                                <tr>
                                    <td>Aanbetaling</td>
                                    <td style="text-align: right; white-space: nowrap">
                                        - {{ number_format($order->getDepositAmount(), 2, ',', '.') }}</td>
                                </tr>
                                <tr class="grand-total">
                                    <td style="font-size: 14px"><strong>Te betalen</strong></td>
                                    <td style="text-align: right; white-space: nowrap">
                                        <strong>{{ number_format($invoiceAmount, 2, ',', '.') }}</strong></td>
                                </tr>
                            @endif
                        </table>
                    @endif
                </td>
            </tr>
        </table>

        @if (! $isCredit)
            <div style="text-align: center; font-size: 12px; margin-top: 10px;">
                @php
                    $invoiceNumberForPayment = '<span style="text-decoration: underline; font-weight: normal">' . ($order->getUidFormatted() ?: '-') . '</span>';
                    $ibanBlock = ' op: <br/>IBAN '
                        . ($rdCustomer?->getIban() ?? '-') . ' ten name van ' . ($rdCustomer?->getName() ?? 'RD Mobility B.V.') . ' te ' . ($rdAddr?->getCity() ?? 'Delft') . '.';

                    if ($paymentConditionApplies) {
                        $commentBlock = 'Gelieve het factuurbedrag met Wero online te betalen of over te maken vóór <strong style="display: inline">' . $expiresAtDisplay . '</strong> o.v.v. van factuurnummer ' . $invoiceNumberForPayment . $ibanBlock
                            . '<br/><br/>Betalingsconditie: <strong>' . $billingTermDaysForLabel . '</strong> dagen na factuurdatum.';
                    } else {
                        $commentBlock = 'Gelieve het factuurbedrag met Wero online te betalen of over te maken o.v.v. van factuurnummer ' . $invoiceNumberForPayment . $ibanBlock;
                    }
                @endphp
                @include('order._comment_block', ['content' => $commentBlock])
            </div>
        @endif
    </div>
    <style>
        div.order-wrapper {
            padding: 0 20px !important;
        }

        table.products th {
            background: #efefef;
            white-space: nowrap;
        }

        table.products td, table.products th {
            text-align: left;
            vertical-align: top;
            padding: 3px 5px;
        }

        th.productnr {
            width: 125px;
        }

        th.qty {
            width: 55px;
        }

        th.product {
            min-width: 200px;
        }

        th.price, th.netto {
            text-align: right;
            min-width: 90px;
        }

        th.discount {
            text-align: left;
            width: 75px;
        }

        table.totals {
            border-collapse: collapse;
        }

        table.totals td {
            padding: 6px 10px;
            font-size: 14px;
        }

        table.totals tr.subtotal td {
            border-top: 1px solid #000;
            padding-bottom: 0;
        }

        table.totals tr.grand-total td {
            border-top: 2px solid #000;
            padding-top: 10px;
        }

        table.totals td.total-amount {
            border-top: 1px solid #000;
            padding-top: 7px;
        }

        table.company-info td {
            font-size: 13px;
            line-height: 20px;
        }

        td.recipient-info > p {
            line-height: 18px;
            padding: 0;
            margin-top: 2px;
            margin-bottom: 0;
        }

        td.recipient-info > strong {
            line-height: 20px;
        }

        td.recipient-info.customer,
        td.recipient-info.shipping {
            font-size: 13px;
            line-height: 8px;
        }

        div.comments {
            margin-top: 0 !important;
            padding-left: 0;
            padding-right: 0;
        }

        div.comments div.inner {
            font-size: 13px;
        }

        table.payment-wrap a.invoice-payment-link {
            display: inline-block;
            line-height: 0;
        }

        table.payment-wrap a.invoice-payment-link img {
            display: block;
        }

        .line-specs {
            font-size: 10px;
            color: #555;
            line-height: 1.4;
            margin-top: 3px;
        }
    </style>
@endsection
