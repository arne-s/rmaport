@php
    /**
     * @var \App\Models\Order\Invoice $invoice
     * @var App\Models\Customer $avMobility
     */
    $avMobility = \App\Models\Customer::getRdMobilityCustomer();
@endphp
@extends('emails.layouts.default-layout')

@section('content')
    <div style="background: #F8F8F8; border: 2px solid #D8D8D8;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 60px">
        <h2 style="padding-top: 0; margin-top: 0">Factuur #{{ $invoice->getUidFormatted() }} staat klaar.</h2>
        <p style="font-size: 16px; margin-top: 30px;">Beste {{ $invoice->billingCustomer?->getFirstName() ?? $invoice->customer?->getFirstName() }},</p>

        <p style="font-size: 16px">Via de onderstaande link kun je de factuur downloaden. Wij willen je vriendelijk
            verzoeken het verschuldigde bedrag binnen 14 dagen naar ons over te maken.</p>

        @include('emails.partials.button', [
                 'url' => config('app.documents_url').route('customer-export', [
                     'publicAccessToken' => $invoice->getPublicAccessToken(),
                     'filename' => 'factuur_'.$invoice->getUidFormatted().'.pdf'
                     ], false),
                 'label' => 'Factuur downloaden'
                 ])

        <br/>

        @if ($invoice->getPaymentLink())
            <p style="font-size: 16px">Direct online veilig betalen is mogelijk via onderstaande link:</p>

            @include('emails.partials.button', [
              'url' => $invoice->getPaymentLink(),
              'label' => 'Direct online betalen'
              ])
        @endif

        <p style="font-size: 16px">Met vriendelijke groeten,<br/>
            RD Mobility
        </p>
    </div>

    @include('emails.partials.need-support')

@endsection

