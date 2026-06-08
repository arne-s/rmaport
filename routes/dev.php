<?php

use App\Enums\OrderType;
use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Illuminate\Support\Facades\Route;

$resolveDevInvoice = function (int|string $id): BaseOrder {
    $invoice = BaseOrder::findOrFailTypedWithoutScopes($id);

    abort_unless(
        in_array($invoice->getType(), [OrderType::Invoice, OrderType::DepositInvoice, OrderType::CreditInvoice], true),
        404,
        'Geen factuur gevonden voor dit id.',
    );

    return $invoice;
};

Route::get('/ordertest-dealer/{id}', function ($id) {
    $order = Order::find($id);
    return view('order.company-order', [
        'order' => $order,
        'products' => $order->orderProducts,
    ]);
});

Route::get('/ordertest-dealer/{id}', function ($id) {
    $order = Order::find($id);
    return view('order.company-order', [
        'order' => $order,
        'products' => $order->orderProducts,
    ]);
});

Route::get('/ordertest-quote/{id}', function ($id) {
    $order = \App\Models\Order\Quote::find($id);
    return view('order.quote', [
        'order' => $order,
        'products' => $order->orderProducts,
    ]);
});



Route::get('/invoice/{id}', function (int|string $id) use ($resolveDevInvoice) {
    $invoice = $resolveDevInvoice($id);

    return response(Document::buildHtmlSnapshotForOrder($invoice))
        ->header('Content-Type', 'text/html; charset=UTF-8');
})->whereNumber('id')->name('dev.invoice');

Route::get('/dev/invoice/{id}', function (int|string $id) use ($resolveDevInvoice) {
    $invoice = $resolveDevInvoice($id);

    return response(Document::buildHtmlSnapshotForOrder($invoice))
        ->header('Content-Type', 'text/html; charset=UTF-8');
})->whereNumber('id')->name('dev.invoice.alias');

Route::get('/dev/invoice-pdf/{id}', function (int|string $id) use ($resolveDevInvoice) {
    $invoice = $resolveDevInvoice($id);
    $html = Document::buildHtmlSnapshotForOrder($invoice);

    $pdf = PDF::loadHTML($html)
        ->setOption('margin-top', $invoice->getPdfSettings('margin-top', 'invoice'))
        ->setOption('margin-left', 0)
        ->setOption('margin-right', 0)
        ->setOption('header-html', $invoice->getPdfSettings('header-html', 'invoice'))
        ->setOption('header-spacing', $invoice->getPdfSettings('header-spacing', 'invoice'));

    return $pdf->inline($invoice->getUidFormatted().'.pdf');
})->whereNumber('id')->name('dev.invoice_pdf');

Route::get('/order-margins2/{orderId}', [DocumentController::class, 'orderMargins']);

