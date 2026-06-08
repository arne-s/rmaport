<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            font-family: Verdana, sans-serif;
            font-size: 13px;
            line-height: 20px;
            color: #212121;
        }

        .footer-wrap {
            width: 100%;
            padding: 8px 60px 0 60px;
            box-sizing: border-box;
        }

        .signature-image {
            width: 100%;
            max-height: 70px;
            display: block;
            margin-top: 4px;
            object-fit: contain;
        }
    </style>
</head>
<body>
<div class="footer-wrap">
    @php
        $recipientAddress = $order?->getShippingAddress() ?? $order?->getDeliveryAddress();
            $packingSlipRecipientName = $order->getBillingInvoiceDisplayName();
        if ($packingSlipRecipientName === '') {
            $packingSlipRecipientName = $order->getCustomerAddressDisplayName()
                ?: $recipientAddress?->getName()
                ?: '-';
        }
    @endphp
    <div><strong>Naam ontvanger:</strong> {{ $packingSlipRecipientName }}</div>
    <div><strong>Datum:</strong> {{ $todayDate }}</div>
    <div style="height: 18px;"></div>
    <div><strong>Handtekening:</strong></div>
    @if (!empty($packingSlip->signature))
        <img src="{{ $packingSlip->signature }}" alt="Handtekening" class="signature-image">
    @endif
    @if (!empty($packingSlip->comment))
        <div style="margin-top: 8px;">
            <strong>Opmerkingen:</strong> {!! nl2br(e($packingSlip->comment)) !!}
        </div>
    @endif
</div>
</body>
</html>
