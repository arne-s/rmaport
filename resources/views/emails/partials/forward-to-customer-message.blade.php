@props(['order', 'hideForwardMessage'])

@if (!$hideForwardMessage)
    <table>
        <tr>
            <td style="width: 80px; vertical-align: middle">
                <img src="{{ url('/img/mail/triangle.gif') }}"/>
            </td>
            <td>
                <strong style="font-size: 15px">Beste {{ $order->billingCustomer?->getFirstName() ?? $order->customer?->getFirstName() }},</strong>
                <p style="margin-top: 5px">Het onderstaande bericht kun je doorsturen naar
                    {{ $order->customer->getName() }}
                    (<em><a href="mailto:{{ $order->customer->getEmail() }}&subject=Offerte">{{
                            $order->customer->getEmail() }}</a></em>).
                    <br/>Zorg ervoor dat je deze zin <strong style="color: #DE0000">verwijdert.</strong></p>
            </td>
        </tr>
    </table>
@endif
