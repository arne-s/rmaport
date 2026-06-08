@extends('emails.layouts.default-layout')
@section('title', 'Nieuwe demo aanvraag')

@section('content')
    <div style="background: #F8F8F8; border: 2px solid #D8D8D8;
    padding: 45px; text-align: center; margin-top: 10px; margin-bottom: 60px">

        <h2 style="padding-top: 0; margin-top: 0">Nieuwe demo aanvraag</h2>

        <p style="font-size: 16px">Er is een nieuwe demo aanvraag binnengekomen op de website.</p>

        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Bedrijf</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->company }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Voornaam</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->first_name }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Tussenvoegsel</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->infix }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Achternaam</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->last_name }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">E-mail</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->email }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Telefoon</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->phone }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Ingeschreven voor nieuwsbrief</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->signed_up_for_newsletter ? 'Ja' : 'Nee' }}</td>
            </tr>
            <tr>
                <th style="border: 1px solid #D8D8D8; padding: 8px; text-align: left;">Inzenddatum</th>
                <td style="border: 1px solid #D8D8D8; padding: 8px;">{{ $entry->created_at->format('d-m-Y H:i:s') }}</td>
            </tr>
        </table>
    </div>
@endsection

