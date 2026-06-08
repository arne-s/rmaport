@php
    /**
     * @var \App\Models\Order\Order $order
     * @var App\Models\Customer $avMobility
     */
    $avMobility = \App\Models\Customer::getRdMobilityCustomer();
@endphp
@extends('emails.layouts.default-layout')

@section('content')
    <div style="background: #F8F8F8; border: 2px solid #D8D8D8;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 60px">
        <h2 style="padding-top: 0; margin-top: 0">Bestelling #{{ $order->getUidFormatted() }}.</h2>
        <p style="font-size: 16px; margin-top: 30px;">Beste {{ $order->billingCustomer?->getFirstName() ?? $order->customer?->getFirstName() }},</p>

        <p style="font-size: 16px">De aanbetaling is succesvol ontvangen en de bestelling is in behandeling genomen.</p>

        <p style="font-size: 16px">Met vriendelijke groeten,<br/>
            RD Mobility
        </p>
    </div>

    @include('emails.partials.need-support')
@endsection
