
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Document</title>
</head>

<body>
@if($isPreview ?? false)
    @include('order._preview-watermark')
@endif
<div @class([
    'order-wrapper',
    'order-wrapper--order-margins-modal' => $embedInOrderMarginsModal ?? false,
])>
    @yield('content')
</div>
</body>

<style>
    html, body {
        background-color: #fff;
    }

    div, td {
        font-family: Verdana, sans-serif;
        font-size: 15px;
        line-height: 26px;
    }

    div.order-wrapper {
        max-width: 1280px;
        margin: auto;
        height: 90% !important;
        padding: 30px 50px 50px 50px;
        color: #212121;
        overflow-x: hidden;
    }

    div.order-wrapper.order-wrapper--order-margins-modal {
        padding: 0;
    }

    div.order-wrapper > table.main {
        margin-top: 30px;
        page-break-before: avoid;
        page-break-after: avoid;
    }

    div.order-wrapper h2 {
        margin-top: 15px;
        margin-bottom: 10px;
        font-size: 30px;
        font-weight: bold;
        padding-bottom: 0;
    }

    /* Herhaal tabelheader op elke pagina en voorkom dat de header alleen onderaan een pagina komt */
    div.order-wrapper table.products thead {
        display: table-header-group;
    }
    div.order-wrapper table.products thead tr {
        page-break-after: avoid;
    }
    div.order-wrapper table.products thead th {
        padding-top: 7px;
        padding-bottom: 5px;
        padding-right: 0;
        border-bottom: 1px solid #000;
    }

    table.products thead th,table.products tbody td {
        font-size: 13px;
    }

    table.products th.qty {
            min-width: 10px;
        }

    @media print {
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
        }

        @page {
            size: A4;
            margin: 2mm;
        }

        * {
            break-inside: avoid !important;
            page-break-inside: avoid !important;
        }
    }
</style>
