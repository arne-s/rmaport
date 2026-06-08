@extends('emails.layouts.default-layout')
@section('title', 'Activeer direct je Shadow Point account')

@section('content')
    <div style="background: #F8F8F8; border: 1px solid #B9B9B9;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 30px">
        <h2 style="padding-top: 0; margin-top: 0">Account geactiveerd</h2>
        <p style="font-size: 16px; margin-bottom: 25px; margin-top: 20px;">Goed nieuws! <br/><br/><em>'{{ $newUser->getName() }}'</em>
            heeft zojuist zijn account succesvol
            geactiveerd.<br/> Dit betekent dat hij nu officieel deel uitmaakt van het netwerk van Shadow Point.</p>

        @include('emails.partials.button', [
                 'url' => route('filament.app.resources.customers.index'),
                 'label' => 'Bekijk klanten in de beheerdersomgeving'
                 ])
    </div>
@endsection
