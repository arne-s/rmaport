@php
    use App\Enums\CustomerType;
    use App\Models\Customer;

    $rdCustomer = Customer::getRdMobilityCustomer();
    $rdAddr = $rdCustomer?->billingAddress;
    $order->loadMissing([
        'dealer.shippingAddress.country',
        'dealer.billingAddress',
        'dealer.address',
        'main.billingCustomer.shippingAddress.country',
        'main.billingCustomer.billingAddress',
        'main.billingCustomer.address',
    ]);

    $mainBillingCustomer = $order->main?->billingCustomer;
    $dealer = ($mainBillingCustomer !== null && $mainBillingCustomer->getType() === CustomerType::Dealer)
        ? $mainBillingCustomer
        : $order->dealer;
    $dealerAddr = $dealer?->shippingAddress ?? $dealer?->billingAddress ?? $dealer?->address;
    $ref = $order->getReferenceNumber();

    $additionalRo = $order->getAdditional() ?? [];
    $advisorDealerAttn = trim((string) ($additionalRo['contactperson'] ?? ''));
    if ($advisorDealerAttn === '' && $order->main !== null) {
        $note = $order->main->getFittingNote();
        if (is_array($note)) {
            $advisorDealerAttn = trim((string) ($note['advisor_dealer_name'] ?? ''));
        }
    }

    $specsFromQuote = $specsFromQuote ?? [];
    $orderDate = $order->getSentAt()?->format('d-m-Y') ?? $order->created_at?->format('d-m-Y') ?? '-';
@endphp

@extends('order.order_layout')
@section('content')

    <div class="wrap">
        <div style="float: left; width: 50%;padding-bottom:5px">
            @include('order._rd_logo')

            @if ($dealer)
                <div class="recipient-info" style="margin-bottom: 60px">
                    <strong>{{ $dealer->getName() }}</strong>
                    <br>
                    @if ($advisorDealerAttn !== '')
                        Ter attentie van: {{ $advisorDealerAttn }}<br>
                    @endif
                    @if ($dealerAddr)
                        {{ $dealerAddr->getStreet() }} {{ $dealerAddr->getHouseNumber() }}{{ $dealerAddr->getHouseNumberAddition() ? ' ' . $dealerAddr->getHouseNumberAddition() : '' }}<br>
                        {{ $dealerAddr->getPostcode() }} {{ $dealerAddr->getCity() }}<br>
                        @if ($dealerAddr->country?->name)
                            {{ $dealerAddr->country->name }}<br>
                        @endif
                    @endif
                </div>
            @endif

            <h2>Afroep</h2>

            @php
                $leveringsadresMeta = $order->getAddressFormatted();

                $leftCols = [
                    'Referentie' => $ref ?: '-',
                    'Orderdatum' => $orderDate,
                ];

                if ($leveringsadresMeta !== '') {
                    $leftCols[] = '&nbsp;';
                }
                $rightCols = [
                    'Bevestiging naar' => 'inkoop@rdmobility.com',
                ];
            @endphp

            @include('order._meta_info', [
                'list' => [$leftCols, $rightCols],
                'boldKeys' => ['Referentie'],
            ])

            @if ($leveringsadresMeta !== '')
                <strong style="display: inline-block; white-space: nowrap">Leveringsadres: &nbsp;&nbsp;{{ $leveringsadresMeta }}</strong>
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
            @php
                $specs = $orderProduct->getAttributeSummaryBasic();
                if ($specs === null || $specs === '') {
                    $summary = $orderProduct->getAttributeSummary();
                    $specs = is_array($summary) ? arrayToTextareaString($summary) : '';
                }
                if ($specs === '' && isset($specsFromQuote[$orderProduct->product_id])) {
                    $specs = $specsFromQuote[$orderProduct->product_id];
                }
            @endphp
            <tr class="line">
                <td>{{ $orderProduct->product?->getSupplierProductUid() }}</td>
                <td>{{ $orderProduct->getQty() }}</td>
                <td>{{ $orderProduct->product?->getUnit()?->getLabel() }}</td>
                <td>{{ $orderProduct->getValue() }}</td>

                <td>
                    @if (!empty($specs))
                        {!! nl2br($specs) !!}
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php
        $commentBlock = 'Indien er vragen zijn over deze afroep, neem dan contact op met <a href="mailto:inkoop@rdmobility.com">inkoop@rdmobility.com</a> <br>Of via tel: <span style="text-decoration: underline; font-weight: normal">'.($rdCustomer->getPhoneNumber() ?? '').'</span>';
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
