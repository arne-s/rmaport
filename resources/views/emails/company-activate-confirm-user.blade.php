@extends('emails.layouts.default-layout')
@section('title', 'Je omgeving is klaar voor gebruik')

@section('content')
    <div style="text-align: left; padding-bottom: 20px;">
        <div style="max-width: 460px; margin: auto; padding-top: 30px">
            {!! $content !!}
        </div>

        <div class="block">
            <table class="steps">
                <tr>
                    <td width="45" class="num" align="left" style="padding-top: 20px;"><img src="{{ url('img/mail/num1.png') }}"
                                                                               alt="1" width="31" height="31">
                    </td>
                    <td width="260">
                        <div style="padding: 10px 0">
                            <h4>Inloggen account</h4>
                            <span>Bekijk alle producten en bestellen</span>
                        </div>
                    </td>
                    <td class="last-td" width="125"><a href="{{ route('login') }}">Klik hier</a></td>
                </tr>
                <tr>
                    <td class="num" style="padding-top: 25px"><img src="{{ url('img/mail/num2.png') }}" alt="1"
                                                                   width="31" height="31"></td>
                    <td>
                        <div style="padding: 10px 0">
                            <h4>Vul marges in</h4>
                            <span>Je marges zijn standaard 30%. Ga naar 'mijn account' om deze te wijzigen.
                               <br/><br/> De marge is van toepassing op de basisprijs van het product.</span>
                        </div>
                    <td class="last-td">
                        <a href="{{ route('account.margins') }}">Klik hier</a></td>
                </tr>
                <tr>
                    <td class="num" style="padding-top: 25px"><img src="{{ url('img/mail/num3.png') }}" alt="1"
                                                                   width="31" height="31"></td>
                    <td>
                        <div style="padding: 10px 0">
                            <h4>Stel huisstijl in</h4>
                            <span>Ga naar 'mijn account'</span>
                        </div>
                    </td>
                    <td class="last-td"><a href="{{ route('account.design') }}">Klik hier</a></td>
                </tr>
                <tr class="last-tr">
                    <td class="num" style="padding-top: 25px"><img src="{{ url('img/mail/num4.png') }}" alt="1"
                                                                   width="31" height="31"></td>
                    <td>
                        <div style="padding: 10px 0">
                            <h4>Bestellen</h4>
                            <span>Bestellen met klanten</span>
                        </div>
                    </td>
                    <td class="last-td"><a href="{{ url('/media/tutorial.mp4') }}">Bekijk tutorial</a></td>
                </tr>
            </table>
        </div>

        <div style="max-width: 460px; text-align: left; margin-left: auto; margin-right: auto;  margin-top: -40px; margin-bottom: 50px">
            <p style="font-size: 16px">Met vriendelijke groeten,<br/>
                RD Mobility
            </p>
        </div>

        @include('emails.partials.need-support')
    </div>
@endsection

<style>
    div.block {
        background: #F8F8F8;
        border: 1px solid #B9B9B9;
        max-width: 410px;
        padding: 5px 30px 10px 30px;
        text-align: left;
        margin: 25px auto 80px auto
    }

    table.steps td {
        color: #333333;
        border-bottom: 1px solid #D3D3D3;
        padding: 15px 5px;
        margin: 0;
        vertical-align: bottom;
    }

    table.steps td.num {
        vertical-align: top;
    }

    table.steps td h4 {
        margin: 0 0 7px 0;
    }

    table.steps td span, table.steps td a {
        font-size: 13px;
        color: #4C4C4C;
    }

    table.steps td a {
        font-weight: 600;
    }

    table.steps td.last-td {
        text-align: right;
        vertical-align: top;
        padding-top: 20px;
    }

    table.steps tr.last-tr td {
        border: none;
    }
</style>
