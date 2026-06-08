<?php

use App\Enums\OrderType;
use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use App\Models\PurchaseOrder;
use App\Models\ReleaseOrder;
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

// Tijdelijk: inkooporder-template bekijken (bijv. /dev/purchase-order/49)
Route::get('/dev/purchase-order/{id}', function ($id) {
    $order = PurchaseOrder::findOrFail($id);
    return view('order.purchase_order', [
        'order' => $order,
        'products' => $order->orderProducts,
    ]);
})->name('dev.purchase_order');

Route::get('/dev/release-order/{id}', function ($id) {
    $order = ReleaseOrder::query()->with(['dealer', 'main'])->findOrFail($id);
    $products = $order->orderProducts()
        ->with(['product'])
        ->get();
    $specsFromQuote = [];
    $quote = $order->main?->getNewestApprovedQuote();
    if ($quote !== null) {
        foreach ($quote->orderProducts()->get() as $op) {
            $spec = $op->getAttributeSummaryBasic();
            if ($spec === null || $spec === '') {
                $summary = $op->getAttributeSummary();
                $spec = is_array($summary) ? arrayToTextareaString($summary) : '';
            }
            $specsFromQuote[$op->product_id] = $spec;
        }
    }

    return view('order.release_order', [
        'order' => $order,
        'products' => $products,
        'specsFromQuote' => $specsFromQuote,
    ]);
})->name('dev.release_order');
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

