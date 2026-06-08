@php
    use App\Models\Customer;
    use App\Support\PackingSlipChecklist;

    $avCustomer = Customer::getRdMobilityCustomer();
    $avAddr = $avCustomer?->billingAddress;
    $companyPhone = $avCustomer?->getPhoneNumber();
    $companyVat = $avCustomer?->getVat();
    $companyEmail = $avCustomer?->getEmail();
    $companyKvk = $avCustomer?->getKvk();
    $companyIban = $avCustomer?->getIban();
    $companyBic = $avCustomer?->getBic();
    $companyWebsite = 'https://autovision.nl';

    $recipientAddress = $main->resolvePackingSlipRecipientAddress();

    $billingInvoiceName = $order->getBillingInvoiceDisplayName();
    $packingSlipCustomerName = $billingInvoiceName !== ''
        ? $billingInvoiceName
        : ($order->getCustomerAddressDisplayName() ?: $recipientAddress?->getName() ?: '-');

    $deliveryType = PackingSlipChecklist::resolveType($packingSlip->checklist_type ?? null);
    $deliveryLogoFile = [
        'adl' => 'adl.png',
        'paws' => 'paws.png',
        'apv' => 'apv.png',
        'swiss_trac' => 'swisstrac.png',
    ][$deliveryType] ?? null;
    $deliveryLogoUrl = $deliveryLogoFile !== null
        ? rtrim(config('app.url', 'https://beheer.rdmobility.com'), '/') . '/img/delivery/' . $deliveryLogoFile
        : null;
@endphp

@extends('order.order_layout')
@section('content')
    <div class="wrap packing-slip-document">
        <div style="float: left; width: 50%; padding-bottom: 5px">
            @include('order._rd_logo')

            @if ($recipientAddress)
                <div class="recipient-info" style="margin-bottom: 60px">
                    <strong>{{ $packingSlipCustomerName }}</strong><br>
                    {{ $recipientAddress->getStreet() }} {{ $recipientAddress->getHouseNumber() }}{{ $recipientAddress->getHouseNumberAddition() ? ' ' . $recipientAddress->getHouseNumberAddition() : '' }}
                    <br>
                    {{ $recipientAddress->getPostcode() }} {{ $recipientAddress->getCity() }}<br>
                    @if ($recipientAddress->country?->name)
                        {{ $recipientAddress->country->name }}<br>
                    @endif
                </div>
            @endif

            <h2>Afleverbon {{ $packingSlip->uid }}</h2>

            @php
                $recipientNameForMeta = $billingInvoiceName !== ''
                    ? $billingInvoiceName
                    : ($order->getCustomerAddressDisplayName() ?: $recipientAddress?->getName() ?: null);

                $streetLine = $recipientAddress
                    ? trim(implode(' ', array_filter([
                        $recipientAddress->getStreet(),
                        $recipientAddress->getHouseNumber(),
                        $recipientAddress->getHouseNumberAddition(),
                    ], fn ($value): bool => filled($value))))
                    : '';

                $leveringsadresMeta = $recipientAddress
                    ? implode(', ', array_values(array_filter([
                        $recipientNameForMeta,
                        $streetLine,
                        $recipientAddress->getPostcode(),
                        $recipientAddress->getCity(),
                        $recipientAddress->country?->name,
                    ], fn ($value): bool => filled($value))))
                    : '';

                $leftCols = [
                    'Ordernummer' => $order->getUidFormatted() ?: '-',
                    'Uw referentie' => $packingSlip->reference ?: '-',
                    'Serienummer' => $main->getSerialNumber() ?: '-',
                ];

                if ($leveringsadresMeta !== '') {
                    $leftCols[] = '&nbsp;';
                }
            @endphp

            @include('order._meta_info', [
                'list' => [$leftCols],
                'boldKeys' => [],
            ])

            @if ($leveringsadresMeta !== '')
                <strong style="display: inline-block; white-space: nowrap">Afleveradres:
                    &nbsp;&nbsp;{{ $leveringsadresMeta }}</strong>
            @endif
        </div>

        <div style="float: right">
            <div class="company-info"
                 style="text-align: left; line-height: 28px; display: inline-block; min-width: 260px;">
                <div>
                    <strong style="font-size: 16px;">{{ $avCustomer?->getName() }}</strong>
                </div>
                @if ($avCustomer)
                    <div style="padding: 0; font-size: 16px;">
                        @if ($avAddr)
                            {{ $avAddr->getStreet() }} {{ $avAddr->getHouseNumber() }}{{ $avAddr->getHouseNumberAddition() ? ' ' . $avAddr->getHouseNumberAddition() : '' }}
                            <br/>
                            {{ $avAddr->getPostcode() }} {{ $avAddr->getCity() }}<br/>
                            {{ $avAddr->country?->name ?? '' }}<br/><br/>
                        @endif
                    </div>

                    @php
                        $companyRows = array_filter([
                            ['label' => 'Tel', 'value' => $companyPhone],
                            ['label' => 'Email', 'value' => $companyEmail],
                            ['label' => 'Website', 'value' => $companyWebsite],
                            ['label' => 'BTW', 'value' => $companyVat],
                            ['label' => 'IBAN', 'value' => $companyIban],
                            ['label' => 'BIC', 'value' => $companyBic],
                            ['label' => 'KvK', 'value' => $companyKvk],
                        ], fn (array $row): bool => filled($row['value']));
                    @endphp

                    @if (! empty($companyRows))
                        <table class="company-meta-table">
                            @foreach ($companyRows as $row)
                                <tr>
                                    <td class="label">{{ $row['label'] }}</td>
                                    <td class="separator"></td>
                                    <td class="value">{{ $row['value'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                @endif
            </div>
        </div>
        <div style="clear: both; padding-top: 10px"></div>
    </div>

    {{-- Aparte tabellen: geen thead die per pagina herhaalt (wkhtmltopdf overlap) --}}
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

    @php
        $deliveryProofLines = $deliveryProofLines ?? [];
        $deliveryProofTypeLabel = $deliveryProofTypeLabel ?? '';
        $checklistItems = $checklistItems ?? [];
        $checklistIntro = $checklistIntro ?? '';
        $checklistOutro = $checklistOutro ?? [];
    @endphp

    <div class="packing-slip-delivery-confirmation">
        @if (! empty($deliveryProofLines))
            <div class="packing-slip-delivery-proof-block new-page">
                @if ($deliveryLogoUrl)
                    <div class="packing-slip-type-logo">
                        <img src="{{ $deliveryLogoUrl }}" alt="Type logo {{ $deliveryType }}">
                    </div>
                @endif
                <h3 class="packing-slip-section-title">Bewijs van aflevering</h3>
                @if (! empty($deliveryProofTypeLabel))
                    <p class="packing-slip-delivery-proof-type"><strong>Type:</strong> {{ $deliveryProofTypeLabel }}</p>
                @endif
                <ul class="delivery-proof-items">
                    @foreach ($deliveryProofLines as $line)
                        @php
                            $indentPx = (int) ($line['indent_px'] ?? 0);
                        @endphp
                        @if (($line['kind'] ?? '') === 'group_header')
                            <li @class([
                                'delivery-proof-items__group-header',
                                'delivery-proof-items__spacer-after' => ! empty($line['spacer_after']),
                            ])>
                                <span @if ($indentPx > 0) style="display: block; margin-left: {{ $indentPx }}px;" @endif>{{ $line['label'] }}</span>
                            </li>
                        @else
                            <li @class([
                                'delivery-proof-items__spacer-after' => ! empty($line['spacer_after']),
                            ])>
                                <span @if ($indentPx > 0) style="display: block; margin-left: {{ $indentPx }}px;" @endif>
                                    {!! ($line['checked'] ?? false) ? '&#10003;' : '&#9744;' !!}&nbsp;{{ $line['label'] }}{{ $line['text_separator'] ?? '' }}@if (! empty($line['text']))<span class="delivery-proof-items__text">{{ $line['text'] }}</span>@endif
                                </span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! empty($checklistItems))
            <div @class(['packing-slip-checklist-block', 'new-page' => empty($deliveryProofLines)])>
                @if (empty($deliveryProofLines) && $deliveryLogoUrl)
                    <div class="packing-slip-type-logo">
                        <img src="{{ $deliveryLogoUrl }}" alt="Type logo {{ $deliveryType }}">
                    </div>
                @endif
                <h3 class="packing-slip-section-title">Checklist</h3>
                @if (! empty($checklistIntro))
                    <p><strong>{{ $checklistIntro }}</strong></p>
                @endif
                <ul class="checklist-items">
                    @foreach ($checklistItems as $item)
                        <li>&#10003;&nbsp;{{ $item }}</li>
                    @endforeach
                </ul>

                @if (! empty($checklistOutro))
                    <p class="checklist-outro-intro">Gebruiker en/of diens begeleider verklaart dat hij/zij:</p>
                    <ul class="checklist-items checklist-outro-items">
                        @foreach ($checklistOutro as $outroLine)
                            <li>&#10003;&nbsp;{{ $outroLine }}</li>
                        @endforeach
                    </ul>
                @endif

                <p style="margin-top: 14px;">De door RD Mobility bevoegde functionaris verklaart dat hij/zij de
                    instructies naar beste weten en kunnen heeft gegeven en dat de praktijktest is uitgevoerd door de
                    gebruiker.</p>
            </div>
        @endif

        <div class="packing-slip-signature-block">
            @php
                $advisorName = $main->advisor?->name ?? '';
            @endphp
            <div><strong>Adviseur:</strong> {{ $advisorName }}</div>
            <div><strong>Datum:</strong> {{ $todayDate }}</div>
            <br/><br/>
            <div><strong>Uw naam:</strong> {{ $main->getCustomerAddressDisplayName() }}</div>
            <div><strong>Datum:</strong> {{ $todayDate }}</div>

            <div style="height: 18px;"></div>
            <div><strong>Handtekening:</strong></div>
            @if (! empty($packingSlip->signature))
                <img src="{{ $packingSlip->signature }}" alt="Handtekening" class="signature-image">
            @endif
            @if (! empty($packingSlip->comment))
                <div style="margin-top: 28px;">
                    <strong>Opmerkingen:</strong> {!! nl2br(e($packingSlip->comment)) !!}
                </div>
            @endif
        </div>
    </div>

    <style>
        * {
            overflow: visible !important;
        }

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

        .company-meta-table {
            margin-top: 6px;
            border-collapse: collapse;
        }

        .company-meta-table td {
            padding: 0;
            font-size: 15px;
            line-height: 24px;
            vertical-align: top;
        }

        .company-meta-table td.label {
            width: 68px;
        }

        .company-meta-table td.separator {
            width: 10px;
        }

        @media print {
            table.products-body {
                page-break-after: always !important;
            }

            .packing-slip-delivery-confirmation {
                page-break-before: always !important;
                display: block;
            }
        }

        .packing-slip-delivery-confirmation {
            display: block;
        }

        .packing-slip-delivery-proof-block,
        .packing-slip-checklist-block {
            margin-top: 24px;
            font-size: 13px;
            position: relative;
        }

        .packing-slip-type-logo {
            position: absolute;
            top: -6px;
            right: 0;
            text-align: right;
        }

        .packing-slip-type-logo img {
            max-height: 70px;
            width: auto;
            object-fit: contain;
        }

        .packing-slip-section-title {
            margin: 0 0 10px 0;
            font-size: 15px;
            font-weight: 700;
        }

        .packing-slip-delivery-proof-type {
            margin: 0 0 8px 0;
        }

        .delivery-proof-items {
            list-style: none;
            padding: 0;
            margin: 0 0 0 4px;
        }

        .delivery-proof-items li {
            padding-top: 2px;
            padding-bottom: 2px;
            font-size: 13px;
            line-height: 20.8px;
        }

        .delivery-proof-items__group-header {
            margin-top: 8px;
            font-weight: 400;
        }

        .delivery-proof-items__spacer-after {
            margin-bottom: 10px;
        }

        .delivery-proof-items__text {
            margin-left: 6px;
        }

        .packing-slip-checklist-block p {
            margin: 0 0 6px 0;
        }

        .checklist-items {
            list-style: none;
            padding: 0;
            margin: 0 0 0 4px;
        }

        .packing-slip-checklist-block p.checklist-outro-intro {
            margin: 28px 0 0 0;
        }

        .checklist-outro-items {
            margin-top: 2px;
        }

        .checklist-items li {
            padding: 1px 0;
            font-size: 13px;
            line-height: 20.8px;
        }

        .packing-slip-signature-block {
            margin-top: 24px;
            page-break-inside: avoid;
        }

        .signature-image {
            width: 50%;
            max-height: 35px;
            display: block;
            margin-top: 4px;
            object-fit: contain;
        }

        .new-page {
            page-break-before: always;
        }
    </style>
@endsection
