@php
    use App\Models\Customer;

    $rdCustomer = Customer::getRdMobilityCustomer();
    $rdAddr = $rdCustomer?->billingAddress;
    $customer = $order->customer;
    $parentOrder = $order->order ?? null;
    $main = $order->main ?? $parentOrder?->main;

    $quoteDate = ($order->getSentAt() ?? $order->updated_at)?->format('d-m-Y') ?? '-';
    $expiresAt = $order->getExpiresAt()?->format('d-m-Y') ?? '-';
@endphp

@extends('order.order_layout')
@section('content')

    <div class="wrap">
        <div style="float: left; width: 50%; padding-bottom: 5px">
            <div style="margin-bottom: -20px; margin-top: -15px">
                @include('order._rd_logo')
            </div>

            <div class="recipient-info customer" style="margin-bottom: 35px; line-height: 8px;">
                <strong>Klant</strong><br>
                {!! $order->getCustomerAddress()?->getAddressTemplateIncNameFormatted() ?? '' !!}
            </div>

            <table style="width: 100%; margin-bottom: 15px; border-collapse: collapse;">
                <tr>
                    <td class="recipient-info billing"
                        style="width: 60%; vertical-align: top; padding: 0 15px 0 0; font-size: 13px; line-height: 8px;">
                        <strong>Factuuradres</strong><br>
                        {!! $order->billingCustomer?->billingAddress?->getAddressTemplateIncNameFormatted() ?? '' !!}
                    </td>

                    <td class="recipient-info shipping"
                        style="width: 50%; vertical-align: top; padding: 0; font-size: 13px;">
                        <strong>Leveradres</strong><br>
                        {!! $order->shippingAddress?->getAddressTemplateIncNameFormatted() ?? '' !!}
                    </td>
                </tr>
            </table>

            <h2 style="font-size: 25px; margin-bottom: 5px; margin-top: 30px">
                Offerte{{ $order->getUid() ? ' ' . $order->getUid() . ' / ' . $order->rev : '' }}</h2>

            @php
                $sourceOrder = $parentOrder ?? $order;
                $additional = $sourceOrder->getAdditional() ?? [];
                $deliveryTimeRaw = $additional['delivery_time'] ?? null;
                $deliveryTime = $deliveryTimeRaw
                    ? (\App\Enums\DeliveryTime::tryFrom($deliveryTimeRaw)?->getLabel() ?? $deliveryTimeRaw)
                    : null;
                $orderComment = $sourceOrder->getOrderComment();

                $paymentConditionCode = $additional['exact_payment_condition']
                    ?? ($parentOrder?->getAdditional() ?? [])['exact_payment_condition']
                    ?? ($main?->getAdditional() ?? [])['exact_payment_condition']
                    ?? null;
                $paymentCondition = $paymentConditionCode
                    ? \App\Models\ExactPaymentCondition::where('code', $paymentConditionCode)->first()
                    : null;
                $paymentConditionLabel = $paymentCondition?->name;

                $advisorName = $main?->advisor?->getName();
                $authorName = $sourceOrder->resolveSellerDisplayName() ?? '—';

                $paymentTermsLabel = $sourceOrder->payment_terms instanceof \App\Enums\PaymentTerms
                    ? $sourceOrder->payment_terms->getLabel()
                    : null;

                $baseFields = collect([
                    'Offertedatum' => $quoteDate,
                    'Geldigheid offerte' => $order->getValidityPeriod()?->label(),
                    'Uw referentie' => $main?->getReference(),
                    'Adviseur' => $advisorName,
                    'Verkoper' => $authorName,
                    'Opmerkingen' => $orderComment,
                ])->filter(fn ($v) => $v !== null && $v !== '');

                $trailingFields = collect([
                    'Levertijd' => $deliveryTime,
                    'Betalingsvoorwaarden' => $paymentTermsLabel,
                    'Betalingsconditie' => $paymentConditionLabel,
                ])->filter(fn ($v) => $v !== null && $v !== '');

                $allFields = $baseFields->merge($trailingFields);
                $half = (int) ceil($allFields->count() / 2);
                $leftCols = $allFields->take($half)->all();
                $rightCols = $allFields->skip($half)->all();
            @endphp

        </div>
        <div style="float: right">
            <div class="company-info"
                 style="text-align: left; line-height: 22px; display: inline-block; min-width: 260px; font-size: 12px; line-height: 20px">
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
                    </table>
                @endif
            </div>
        </div>
        <div style="clear: both; padding-top: 10px"></div>
    </div>

    @include('order._meta_infov2', [
      'list' => !empty($rightCols) ? [$leftCols, $rightCols] : [$leftCols],
      'boldKeys' => [],
  ])


    @php
        $hasDiscount = $products->contains(fn ($p) => $p->getCompanySalesPriceDiscountPercentage() != 0);
    @endphp
    <table class="products" style="width: 100%; margin-top: 5px;">
        <thead>
        <tr>
            <th class="productnr">Artikelnummer</th>
            <th class="product">Omschrijving</th>
            <th class="qty">Aantal</th>
            <th class="price">Eenheidsprijs €</th>
            @if ($hasDiscount)
                <th class="discount">Korting</th>
            @endif
            <th class="netto" style="text-align: right">Nettoprijs €&nbsp;</th>
        </tr>
        </thead>

        <tbody>
        @foreach ($products as $orderProduct)
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
                <td style="white-space: nowrap">{{ number_format($orderProduct->getCompanySalesPriceBase(), 2, ',', '.') }}</td>
                @if ($hasDiscount)
                    <td>{{ number_format($discountPct, 2, ',', '.') }}%</td>
                @endif
                <td style="text-align: right; white-space: nowrap">{{ number_format($orderProduct->getCompanySalesPriceTotal(), 2, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php
        $totalExVat = $order->getCompanySalesPriceTotal();
        $totalIncVat = $order->getCompanySalesPriceTotalIncVat();

        $vatByPercentage = [];
        foreach ($products as $op) {
            $pct = $op->getVat();
            $key = number_format($pct, 2);
            $vatByPercentage[$key] = ($vatByPercentage[$key] ?? 0) + ($op->getCompanySalesPriceTotal() * ($pct / 100));
        }
        ksort($vatByPercentage);
    @endphp

    <div style="page-break-inside: avoid;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 0;" align="right">
                    <table class="totals" style="width: 350px; margin-top: 10px; margin-bottom: 35px;">
                        <tr class="subtotal">
                            <td>Subtotaal <span style="font-weight: normal; font-size: 10px;">(excl. BTW)</span></td>
                            <td style="text-align: right; white-space: nowrap">{{ number_format($totalExVat, 2, ',', '.') }}</td>
                        </tr>
                        @foreach ($vatByPercentage as $pctKey => $vatSum)
                            <tr>
                                <td>BTW {{ number_format((float) $pctKey, 0) }}%</td>
                                <td style="text-align: right; white-space: nowrap">{{ number_format($vatSum, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        <tr class="grand-total">
                            <td style="font-size: 14px"><strong>Totaalbedrag</strong> <span
                                    style="font-weight: normal; font-size: 10px;">(incl. BTW)</span></td>
                            <td style="text-align: right; white-space: nowrap">
                                <strong>{{ number_format($totalIncVat, 2, ',', '.') }}</strong></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

    </div>

    @php
        $validityDays = $order->getValidityPeriod()?->value ?? 60;
        $sellerName = $authorName ?? '-';
        $rdPhone = $rdCustomer?->getPhoneNumber() ?? '';
        $commentBlock = 'De offerte is ' . $validityDays . ' dagen geldig na offertedatum. Voor vragen over de offerte kunt u contact opnemen met ' . e($sellerName) . ' via ' . e($rdPhone) . ' of info@rdmobility.com.';
    @endphp
    @include('order._comment_block', ['content' => $commentBlock])

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

        .line-specs {
            font-size: 10px;
            color: #555;
            line-height: 1.4;
            margin-top: 3px;
        }

    </style>
@endsection
