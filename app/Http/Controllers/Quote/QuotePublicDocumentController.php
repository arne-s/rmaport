<?php

namespace App\Http\Controllers\Quote;

use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class QuotePublicDocumentController extends Controller
{
    public function orderConfirmation(string $uuid): SymfonyResponse
    {
        $order = $this->findByPublicUuid($uuid);
        if ($order->getType() !== OrderType::Order) {
            abort(404);
        }
        $this->assertOrderConfirmationPubliclyDownloadable($order);

        return $this->respondWithPdf($order);
    }

    public function invoice(string $uuid): SymfonyResponse
    {
        $order = $this->findByPublicUuid($uuid);
        $type = $order->getType();
        if (! in_array($type, [OrderType::Invoice, OrderType::DepositInvoice, OrderType::CreditInvoice], true)) {
            abort(404);
        }
        $this->assertInvoicePubliclyDownloadable($order);

        return $this->respondWithPdf($order);
    }

    private function findByPublicUuid(string $uuid): BaseOrder
    {
        return BaseOrder::query()
            ->withoutGlobalScopes()
            ->where('public_download_uuid', $uuid)
            ->firstOrFail();
    }

    private function assertOrderConfirmationPubliclyDownloadable(BaseOrder $order): void
    {
        if ($order->getSentAt() === null) {
            abort(404);
        }
        if (((int) $order->getIsCancelled()) === 1) {
            abort(404);
        }
    }

    private function assertInvoicePubliclyDownloadable(BaseOrder $order): void
    {
        if ($order->getSentAt() === null) {
            abort(404);
        }
        if (((int) $order->getIsCancelled()) === 1) {
            abort(404);
        }
    }

    private function respondWithPdf(BaseOrder $order): SymfonyResponse
    {
        $type = $order->getType();
        match ($type) {
            OrderType::Quote,
            OrderType::Order,
            OrderType::DepositInvoice,
            OrderType::CreditInvoice,
            OrderType::Invoice => true,
            default => abort(404, 'Ongeldig documenttype.'),
        };

        $collection = match ($type) {
            OrderType::Quote => 'quote',
            OrderType::Order => 'order',
            OrderType::DepositInvoice => 'deposit_invoice',
            OrderType::CreditInvoice => 'credit_invoice',
            OrderType::Invoice => 'invoice',
        };

        $media = $order->getFirstMedia($collection);

        if ($media === null) {
            try {
                Document::createFromOrder($order);
                $order->refresh();
                $media = $order->getFirstMedia($collection);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($media !== null && is_file($media->getPath())) {
            return response()->download($media->getPath(), $media->file_name);
        }

        [$pdf, $filename] = Document::buildPdfWrapperFromOrder($order);

        return $pdf->download($filename);
    }
}
