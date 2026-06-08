@props(['hideLogo' => false])
<!DOCTYPE html>
<html>
<head>
    <title>@yield('title', 'RD Mobility')</title>
</head>
<body style="font-family: Arial, sans-serif;">

<table width="100%">
    <tr>
        <td align="center">
            <table style="background-color: #FFF; max-width: 768px; width: 100%">
                @if (!$hideLogo)
                    <tr>
                        <td align="center" height="100">
                            <img src="{{ url('img/logo-small.png') }}" alt="Autovision" width="280">
                        </td>
                    </tr>
                @endif
                <tr>
                    <td>
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td align="center" style="color: #C1C1C1; font-size: 11px; padding-top: 30px">
                        &copy; {{ date('Y') }} autovision.nl
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

<style>
    * {
        font-family: Helvetica, Arial, sans-serif;
    }

    table {
        border-spacing: 0;
    }

    p, h1, h2, h3 {
        color: #333333;
    }

    /*h2 {*/
    /*    font-size: 26px;*/
    /*    line-height: 24px;*/
    /*    margin-bottom: 10px;*/
    /*    font-weight: 600;*/
    /*}*/

    h3 {
        font-size: 22px;
        line-height: 20px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    p {
        font-size: 14px;
        line-height: 24px;
    }

    a {
        color: #0296cb;
        text-decoration: underline;
    }
</style>
