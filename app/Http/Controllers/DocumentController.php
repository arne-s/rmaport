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
        $isProductDocument = $modelType === Product::class && $collectionName === 'documents';
        $isNoteAttachment = $modelType === Note::class && $collectionName === 'attachments';
        $isPackingSlip = is_string($modelType) && is_a($modelType, BaseOrder::class, true) && $collectionName === 'delivery_documents';
        $isDeliveryNotePdf = $modelType === DeliveryNote::class && $collectionName === 'pdf';

        if (! $isOrderMedia && ! $isProductDocument && ! $isNoteAttachment && ! $isPackingSlip && ! $isDeliveryNotePdf) {
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

        abort_unless($isPackingSlip || $isDeliveryNotePdf, 404);

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
}
