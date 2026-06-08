@extends('emails.layouts.minimal-layout')

@section('content')
    <table>
        <tr>
            <td style="width: 80px; vertical-align: middle">
                <img src="{{ url('/img/mail/triangle.gif') }}"/>
            </td>
            <td>
                <strong style="font-size: 15px">Beste {{ $quote->billingCustomer?->getFirstName() ?? $quote->customer?->getFirstName() }},</strong>
                <p style="margin-top: 5px">Het onderstaande bericht kun je doorsturen naar
                    {{ $quote->customer->getName() }}
                    (<em><a href="mailto:{{ $quote->customer->getEmail() }}&subject=Offerte">{{
                            $quote->customer->getEmail() }}</a></em>).
                    <br/>Zorg ervoor dat je deze zin <strong style="color: #DE0000">verwijdert.</strong></p>
            </td>
        </tr>
    </table>


    <div style="background: #F8F8F8; border-radius: 5px;
    padding: 45px 125px; text-align: center; margin-top: 10px; margin-bottom: 60px">

        <h2 style="padding-top: 0; margin-top: 0; font-size: 24px">Beste {{ $quote->customer->getFirstName() }},</h2>
        <p style="font-size: 16px">Bij deze ontvang je de opgevraagde offerte. Klik op de onderstaande
            button om je offerte in te zien.</p>

        @include('emails.partials.button', [
                 'url' => config('app.documents_url').route('customer-export', [
                     'publicAccessToken' => $quote->getPublicAccessToken(),
                     'filename' => 'offerte_'.$quote->getUidFormatted().'.pdf'
                     ], false),
                 'backgroundColor' => '#333333',
                 'textColor' => '#FFFFFF',
                 'fontWeight' => 'normal',
                 'label' => 'Offerte downloaden'
                 ])
    </div>

@endsection

