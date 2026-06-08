@php
    /**
     * @var \App\Models\Order\DepositInvoice $depositInvoice
     */
@endphp
@extends('emails.layouts.default-layout')

@section('content')
    <div style="background: #F8F8F8; border: 2px solid #D8D8D8;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 60px">

        <h2 style="padding-top: 0; margin-top: 0">Factuur #{{ $depositInvoice->getUidFormatted() }} staat klaar.</h2>
        <p style="font-size: 16px; margin-top: 30px;">Beste {{ $depositInvoice->billingCustomer?->getFirstName() ?? $depositInvoice->customer?->getFirstName() }},</p>

        <p style="font-size: 16px">
            Bedankt voor je bestelling. <br/><br/>
            <strong>Je bestelling (order nr.: #{{ $depositInvoice->order->getUidFormatted() }}) wordt in behandeling
                genomen nadat de aanbetaling is voldaan.</strong>
            <br/><br/>
            Via onderstaande link kun je de aanbetaling-factuur downloaden en direct online
            betalen. Wij willen je vriendelijk verzoeken het verschuldigde bedrag naar ons over te
            maken.
        </p>

        @include('emails.partials.button', [
                 'url' => config('app.documents_url').route('customer-export', [
                     'publicAccessToken' => $depositInvoice->getPublicAccessToken(),
                     'filename' => 'factuur_'.$depositInvoice->getUidFormatted().'.pdf'
                     ], false),
                 'label' => 'Factuur downloaden'
                 ])
        <br/>

        @if ($depositInvoice->getPaymentLink())
            <p style="font-size: 16px">Direct online veilig betalen is mogelijk via onderstaande link:</p>

            @include('emails.partials.button', [
              'url' => $depositInvoice->getPaymentLink(),
              'label' => 'Direct online betalen'
              ])
        @endif

        <p style="font-size: 16px">Met vriendelijke groeten,<br/>
            RD Mobility
        </p>

    </div>

    @include('emails.partials.need-support')

@endsection

