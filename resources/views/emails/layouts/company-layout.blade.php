<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif;">

<table width="100%">
    <tr>
        <td align="center">
            <table style="background-color: #FFF; max-width: 768px; width: 100%">
                <tr>
                    <td>
                        @yield('content')
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
        color: #4C4C4C;
        text-decoration: underline;
    }
</style>
