@extends('emails.layouts.default-layout')

@section('content')
    <div style="background: #F8F8F8; border: 2px solid #D8D8D8;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 60px">

        <h2 style="padding-top: 0; margin-top: 0; margin-bottom: 30px">Beste {{ $order->billingCustomer?->getFirstName() ?? $order->customer?->getFirstName() }},</h2>
        <p style="font-size: 16px">Je bestelling met ordernummer #{{ $order->getUidFormatted() }},
            voor klant {{ $order->customer->getName() }}, ligt klaar in
            ons magazijn bij de showroom in {{ $order->showroom->getName() }}.</p>
        <p style="font-size: 16px">Je kunt deze nu afhalen. Wij wensen je succes bij het installeren!</p>

        <p style="font-size: 16px">Met vriendelijke groeten,<br/>
            RD Mobility
        </p>

    </div>

    @include('emails.partials.need-support')

@endsection

