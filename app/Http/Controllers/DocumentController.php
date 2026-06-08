<?php

namespace App\Http\Controllers;

use App\Support\DocumentPreviewWatermark;
use App\Services\MailPreview\MsgPreviewCacheService;
use App\Models\DeliveryNote;
use App\Models\Document;
use App\Models\ExactDocument;
use App\Models\Note;
use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Models\Order\CreditInvoice;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Invoice;
use App\Models\Order\Order;
use App\Models\Order\Quote;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderConfirmation;
use App\Models\PurchaseOrderInvoice;
use App\Models\ReleaseOrder;
use App\Models\Product;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Exception;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class DocumentController extends Controller
{
    public function __construct(
        protected MsgPreviewCacheService $msgPreviewCacheService,
    ) {
    }

    /**
     * Sales quote/order/invoice HTML for iframe modals.
     * Quotes always render live; orders/invoices use stored snapshot when present.
     */
    public function showSalesDocumentHtml(int|string $orderId): ViewContract|\Illuminate\Http\Response
    {
        abort_unless(Gate::allows('export-order'), 403);

        $isPreview = request()->boolean('preview');

        // Strip synthetic CompanyDocumentTableRow prefix (e.g. 'order-366' → 366).
        if (is_string($orderId) && str_starts_with($orderId, 'order-')) {
            $orderId = (int) substr($orderId, 6);
        }

        $order = $this->resolveTypedOrderForDocumentLookup($orderId);
        $typeValue = $order->type instanceof \BackedEnum ? $order->type->value : (string) $order->type;

        $viewMap = [
            'quote' => 'order.quote',
            'order' => 'order.order',
            'invoice' => 'order.invoice',
            'deposit_invoice' => 'order.invoice',
            'credit_invoice' => 'order.invoice',
        ];

        if (isset($viewMap[$typeValue])) {
            // Quotes and admin preview (?preview=1): live customer/order data; stored snapshot is for send/history.
            if ($typeValue !== OrderType::Quote->value && ! $isPreview) {
                $snapshot = Document::query()
                    ->where('documentable_id', $order->getKey())
                    ->where('documentable_type', $order->getMorphClass())
                    ->whereNotNull('content')
                    ->where('content', '!=', '')
                    ->latest('id')
                    ->first();

                if ($snapshot !== null) {
                    $html = $this->normalizeStoredDocumentHtml($snapshot->getContent());

                    if ($isPreview) {
                        $html = DocumentPreviewWatermark::appendToHtml($html);
                    }

                    return response(
                        $html,
                        200,
                        ['Content-Type' => 'text/html; charset=UTF-8'],
                    );
                }
            }

            $html = Document::buildHtmlSnapshotForOrder($order, $isPreview);

            return response(
                $this->normalizeStoredDocumentHtml($html),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        if (empty($order->getDoc())) {
            $order->generateDoc();
            $order->save();
        }

        return response((string) $order->getDoc(), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Ensure stored HTML is a parseable document for iframe src (adds a minimal shell if needed).
     */
    private function normalizeStoredDocumentHtml(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '<!DOCTYPE html><html><head><meta charset="utf-8"><title></title></head><body></body></html>';
        }

        if (preg_match('/<\s*html[\s>]/i', $trimmed) === 1) {
            return $content;
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$content.'</body></html>';
    }

    /**
     * {@see BaseOrder} loaded via the base table is not the same class as STI subclasses used when saving
     * {@see Document} morph rows (e.g. {@see Quote}), so morph_class must match the concrete model.
     */
    private function resolveTypedOrderForDocumentLookup(int|string $orderId): BaseOrder
    {
        return BaseOrder::findOrFailTypedWithoutScopes($orderId);
    }

    public function customerExport(string $publicAccessToken): Response
    {
        $order = BaseOrder::where('public_access_token', $publicAccessToken)
            ->firstOrFail();

        return $this->download($order, 'inline');
    }

    /**
     * @throws Throwable
     */
    protected function download(BaseOrder $order, string $method = 'download')
    {
        if (empty($order->getDoc())) {
            $order->generateDoc();
            $order->save();
        }

        throw_if(empty($order->getDoc()),
            new Exception('Er ging iets mis bij het genereren van het document'));

        $pdf = PDF::loadHTML($order->getDoc())
            ->setOption('margin-top', $order->getPdfSettings('margin-top', 'packing_slip'))
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0)
            ->setOption('header-html', $order->getPdfSettings('header-html', 'packing_slip'))
            ->setOption('header-spacing', $order->getPdfSettings('header-spacing', 'packing_slip'));

        return $pdf->$method($order->getUidFormatted() . '.pdf');
    }

    /**
     * Admin iframe: placeholder PDF + customer approval panel.
     */
    public function quoteAdminPreview(int|string $quoteId): ViewContract
    {
        abort_unless(Gate::allows('export-order'), 403);

        $quote = Quote::withoutGlobalScopes()->findOrFail($quoteId);
        $typeValue = $quote->type instanceof \BackedEnum ? $quote->type->value : (string) ($quote->type ?? '');
        abort_unless($typeValue === OrderType::Quote->value, 404);

        try {
            Document::regenerateQuotePdfFromLiveOrder($quote);
        } catch (\Throwable $e) {
            report($e);
        }

        $quote->loadMissing(['quoteApproval.approvedByUser']);

        return view('order.quote-admin-preview', ['quote' => $quote]);
    }

    public function packingSlip(int|string $orderId)
    {
        $order = BaseOrder::findOrFail($orderId);
        if (empty($order->uid))
            return abort(404);
        return view('order.packing-slip', ['order' => $order])->render();
    }

    public function packingSlipDownload(int|string $orderId): Response
    {
        abort_unless(Gate::allows('export-order'),
            403, 'Unauthorized action.'
        );

        $order = BaseOrder::findOrFail($orderId);
        if (empty($order->uid)) {
            return abort(404);
        }

        $html = view('order.packing-slip', ['order' => $order])->render();
        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', $order->getPdfSettings('margin-top', 'packing_slip'))
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0)
            ->setOption('header-html', $order->getPdfSettings('header-html', 'packing_slip'))
            ->setOption('header-spacing', $order->getPdfSettings('header-spacing', 'packing_slip'));

        return $pdf->download('Pakbon ' . $order->getUidFormatted() . '.pdf');
    }

    public function deliveryNote(int|string $orderId): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(Gate::allows('export-order'),
            403, 'Unauthorized action.'
        );

        $order = Order::query()->findOrFail($orderId);
        if (empty($order->getUid())) {
            abort(404);
        }

        $order->loadMissing('deliveryNote');
        $media = $order->deliveryNote?->getFirstMedia('pdf');
        abort_unless($media, 404, 'Pakbon niet gevonden. Sla de order opnieuw op om te genereren.');

        $path = $media->getPath();
        if (! file_exists($path)) {
            abort(404);
        }

        $sequenceUid = $order->deliveryNote?->uid;
        $filename = $media->file_name ?? ('delivery-note-' . ($sequenceUid ?? $order->getUidFormatted()) . '.pdf');

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
        ]);
    }

    public function deliveryNoteDownload(int|string $orderId): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(Gate::allows('export-order'),
            403, 'Unauthorized action.'
        );

        $order = Order::query()->findOrFail($orderId);
        if (empty($order->getUid())) {
            abort(404);
        }

        $order->loadMissing('deliveryNote');
        $media = $order->deliveryNote?->getFirstMedia('pdf');
        abort_unless($media, 404, 'Pakbon niet gevonden. Sla de order opnieuw op om te genereren.');

        $sequenceUid = $order->deliveryNote?->uid;

        return response()->download(
            $media->getPath(),
            $media->file_name ?? ('delivery-note-' . ($sequenceUid ?? $order->getUidFormatted()) . '.pdf')
        );
    }

    public function orderMargins(int|string $orderId)
    {
        $order = BaseOrder::findOrFail($orderId);
        if (empty($order->uid))
            return abort(404);
        return view('order.order-margins', [
            'order' => $order,
            'embedInOrderMarginsModal' => true,
        ])->render();
    }

    // Route for Portal account orders
    public function companyOrderMargins(int|string $orderId)
    {
        $order = BaseOrder::findOrFail($orderId);
        if (empty($order->uid) || $order->billing_customer_id !== (auth()->user()->customer_id ?? null))
            return abort(404);

        return view('order.order-margins', [
            'order' => $order,
            'companyView' => true,
        ])
            ->render();
    }

    public function orderMarginsDownload(int|string $orderId): Response
    {
        abort_unless(Gate::allows('export-order'),
            403, 'Unauthorized action.'
        );

        $order = BaseOrder::findOrFail($orderId);
        if (empty($order->uid)) {
            return abort(404);
        }

        $html = view('order.order-margins', ['order' => $order])->render();
        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', $order->getPdfSettings('margin-top', 'packing_slip'))
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0)
            ->setOption('header-html', $order->getPdfSettings('header-html', 'packing_slip'))
            ->setOption('header-spacing', $order->getPdfSettings('header-spacing', 'packing_slip'));

        return $pdf->download('Marges ' . $order->getUidFormatted() . '.pdf');
    }

    public function purchaseOrderConfirmation(int|string $id)
    {
        $this->ensurePanelUserCanAccessDocuments();

        $confirmation = PurchaseOrderConfirmation::findOrFail($id);
        if (empty($confirmation->id) || empty($confirmation?->pdf_path))
            return abort(404);

        $filePath = storage_path('app/public/' . $confirmation->pdf_path);

        if (!file_exists($filePath)) {
            return abort(404, 'File not found.');
        }

        return response()->file($filePath);
    }

    public function purchaseOrderConfirmationDownload(int|string $orderId)
    {
        $this->ensurePanelUserCanAccessDocuments();

        $confirmation = PurchaseOrderConfirmation::findOrFail($orderId);
        if (empty($confirmation->id) || empty($confirmation?->pdf_path)) {
            return abort(404);
        }

        $filePath = storage_path('app/public/' . $confirmation->pdf_path);
        if (!file_exists($filePath)) {
            return abort(404, 'File not found.');
        }
        $fileName = pathinfo($confirmation->pdf_path, PATHINFO_BASENAME);

        return response()->download($filePath, $fileName);
    }

    public function purchaseOrderConfirmationModal(int|string $id)
    {
        $this->ensurePanelUserCanAccessDocuments();

        $purchaseOrder = PurchaseOrder::findOrFail($id);
        if (empty($purchaseOrder->id)) {
            return abort(404);
        }

        return view('filament.components.purchase-order-confirmation-modal', [
            'id' => $purchaseOrder->id,
        ]);
    }

    /**
     * HTML document for iframe preview (same template as PDF).
     */
    public function purchaseOrderDocumentPreview(PurchaseOrder $purchaseOrder): ViewContract
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $purchaseOrder->loadMissing(['orderProducts', 'supplier']);

        return view('order.purchase_order', [
            'order' => $purchaseOrder,
            'products' => $purchaseOrder->orderProducts,
            'isPreview' => true,
        ]);
    }

    /**
     * Generate purchase order PDF on the fly and return as download (for preview modal).
     */
    public function purchaseOrderPreviewDownload(PurchaseOrder $purchaseOrder)
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $purchaseOrder->loadMissing(['orderProducts', 'supplier']);
        $html = view('order.purchase_order', [
            'order' => $purchaseOrder,
            'products' => $purchaseOrder->orderProducts,
            'isPreview' => true,
        ])->render();

        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0);

        $pdfOutput = $pdf->output();
        if ($pdfOutput === null || $pdfOutput === '') {
            abort(500, 'Kon PDF niet genereren.');
        }

        $filename = 'Inkooporder_' . $purchaseOrder->getReferenceNumber() . '.pdf';

        return response()->streamDownload(
            fn () => print($pdfOutput),
            $filename,
            [
                'Content-Type' => 'application/pdf',
            ],
            'attachment'
        );
    }

    /**
     * Generate stock order PDF on the fly and return as download (for preview modal).
     */
    public function stockOrderPreviewDownload(StockOrder $stockOrder)
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $stockOrder->loadMissing(['supplier']);
        $html = view('order.stock_order', [
            'order' => $stockOrder,
            'products' => $stockOrder->getDocumentOrderProducts(),
            'isPreview' => true,
        ])->render();

        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0);

        $pdfOutput = $pdf->output();
        if ($pdfOutput === null || $pdfOutput === '') {
            abort(500, 'Kon PDF niet genereren.');
        }

        $ref = $stockOrder->getUidFormatted() ?: (string) $stockOrder->getId();
        $filename = 'Voorraadorder_' . $ref . '.pdf';

        return response()->streamDownload(
            fn () => print($pdfOutput),
            $filename,
            [
                'Content-Type' => 'application/pdf',
            ],
            'attachment'
        );
    }

    /**
     * Generate release order (afroepbon) PDF on the fly (preview modal download).
     */
    public function releaseOrderPreviewDownload(ReleaseOrder $releaseOrder)
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $html = view('order.release_order', [
            ...$releaseOrder->getDocumentViewData(),
            'isPreview' => true,
        ])->render();

        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0);

        $pdfOutput = $pdf->output();
        if ($pdfOutput === null || $pdfOutput === '') {
            abort(500, 'Kon PDF niet genereren.');
        }

        $filename = 'Afroepbon_' . $releaseOrder->getReferenceNumber() . '.pdf';

        return response()->streamDownload(
            fn () => print($pdfOutput),
            $filename,
            [
                'Content-Type' => 'application/pdf',
            ],
            'attachment'
        );
    }

    public function purchaseOrderInvoice(int|string $id)
    {
        abort_unless(auth()->user()?->can('manage financials') ?? false, 403, 'Unauthorized action.');

        $invoice = PurchaseOrderInvoice::findOrFail($id);
        $media = $this->resolveMediaForPurchaseOrderInvoice($invoice);

        if (! PurchaseOrderInvoice::isPreviewableDocumentMedia($media)) {
            abort(404, 'PDF niet gevonden of ongeldig voor deze inkoopfactuur.');
        }

        $path = $media->getPath();

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    public function purchaseOrderInvoiceDownload(int|string $invoiceId)
    {
        abort_unless(auth()->user()?->can('manage financials') ?? false, 403, 'Unauthorized action.');

        $invoice = PurchaseOrderInvoice::findOrFail($invoiceId);
        $media = $this->resolveMediaForPurchaseOrderInvoice($invoice);

        if (! PurchaseOrderInvoice::isPreviewableDocumentMedia($media)) {
            abort(404, 'PDF niet gevonden of ongeldig voor deze inkoopfactuur.');
        }

        return response()->download($media->getPath(), $media->file_name);
    }

    /**
     * PDF bij een inkoopfactuur: voorkeur media met custom property {@see purchase_order_invoice_id},
     * anders match op factuurnummer of inkooporder-referentie in bestandsnaam.
     */
    private function resolveMediaForPurchaseOrderInvoice(PurchaseOrderInvoice $invoice): ?Media
    {
        return $invoice->resolveLinkedMedia();
    }

    /**
     * Stream a media file for inline preview (e.g. images in modal).
     * Allowed: media on {@see BaseOrder} (orders/fittings) or {@see Note} (note attachments).
     *
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function mediaPreview(int $id)
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $media = Media::find($id);
        if ($media === null) {
            abort(404);
        }

        $modelType = $media->model_type;
        $collectionName = $media->collection_name;

        $isOrderMedia = is_string($modelType) && is_a($modelType, BaseOrder::class, true);
        $isPurchaseOrderDocument = $modelType === PurchaseOrder::class && $collectionName === 'documents';
        $isReleaseOrderDocument = $modelType === ReleaseOrder::class && $collectionName === 'documents';
        $isProductDocument = $modelType === Product::class && $collectionName === 'documents';
        $isNoteAttachment = $modelType === Note::class && $collectionName === 'attachments';
        $isPackingSlip = is_string($modelType) && is_a($modelType, BaseOrder::class, true) && $collectionName === 'delivery_documents';
        $isDeliveryNotePdf = $modelType === DeliveryNote::class && $collectionName === 'pdf';

        if (! $isOrderMedia && ! $isPurchaseOrderDocument && ! $isReleaseOrderDocument && ! $isProductDocument && ! $isNoteAttachment && ! $isPackingSlip && ! $isDeliveryNotePdf) {
            abort(404);
        }

        if ($isOrderMedia && $collectionName === 'quote') {
            $order = $media->model;
            if ($order instanceof BaseOrder && $order->getType() === OrderType::Quote) {
                try {
                    Document::regenerateQuotePdfFromLiveOrder($order);
                    $order->refresh();
                    $media = $order->getFirstMedia('quote') ?? $media;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $path = $media->getPath();
        if (! file_exists($path)) {
            abort(404);
        }

        $filename = $media->file_name ?? ('file.' . $media->extension);

        if ($this->msgPreviewCacheService->isOutlookMsgMedia($media)) {
            $preview = $this->msgPreviewCacheService->getPreview($media);

            return response()->view('documents.msg-preview', [
                'filename' => $filename,
                'preview' => $preview,
            ]);
        }

        return response()->file($path, [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
        ]);
    }

    public function exactDocumentDownload(int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $document = ExactDocument::findOrFail($id);

        $media = $document->getFirstMedia('pdf');

        abort_unless($media !== null, 404, 'PDF niet gevonden voor dit document.');

        $path = $media->getPath();
        abort_unless(file_exists($path), 404, 'PDF bestand niet gevonden op de server.');

        return response()->download($path, $media->file_name);
    }

    public function exactDocumentPreview(int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $document = ExactDocument::findOrFail($id);

        $media = $document->getFirstMedia('pdf');

        abort_unless($media !== null, 404, 'PDF niet gevonden voor dit document.');

        $path = $media->getPath();
        abort_unless(file_exists($path), 404, 'PDF bestand niet gevonden op de server.');

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    public function mediaDownload(int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(Gate::allows('export-order'), 403, 'Unauthorized action.');

        $media = Media::findOrFail($id);

        $modelType = $media->model_type;
        $collectionName = $media->collection_name;

        $isPackingSlip = is_string($modelType) && is_a($modelType, BaseOrder::class, true) && $collectionName === 'delivery_documents';
        $isDeliveryNotePdf = $modelType === DeliveryNote::class && $collectionName === 'pdf';
        $isPurchaseOrderDocument = $modelType === PurchaseOrder::class && $collectionName === 'documents';

        abort_unless($isPackingSlip || $isDeliveryNotePdf || $isPurchaseOrderDocument, 404);

        $path = $media->getPath();
        abort_unless(file_exists($path), 404);

        return response()->download($path, $media->file_name);
    }

    public function invoiceDownload(int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $base = BaseOrder::withoutGlobalScopes()->findOrFail($id);
        $typeValue = $base->type instanceof \BackedEnum ? $base->type->value : (string) $base->type;

        $modelClass = match ($typeValue) {
            'credit_invoice' => CreditInvoice::class,
            'deposit_invoice' => DepositInvoice::class,
            'invoice' => Invoice::class,
            default => BaseOrder::class,
        };
        $invoice = $modelClass::withoutGlobalScopes()->findOrFail($id);

        $collection = match ($typeValue) {
            'deposit_invoice' => 'deposit_invoice',
            'credit_invoice' => 'credit_invoice',
            default => 'invoice',
        };

        $media = $invoice->getFirstMedia($collection);

        abort_unless($media, 404, 'PDF niet gevonden voor deze factuur.');

        return response()->download($media->getPath(), $media->file_name);
    }

    protected function ensurePanelUserCanAccessDocuments(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('access filament panel'),
            403,
            'Unauthorized action.',
        );
    }
}
