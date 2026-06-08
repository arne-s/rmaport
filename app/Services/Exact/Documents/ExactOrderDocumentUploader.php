<?php

namespace App\Services\Exact\Documents;

use App\Enums\OrderType;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Services\Exact\ExactAccountGuidForOrder;
use App\Services\ExactOnlineService;
use Exception;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExactOrderDocumentUploader
{
    /** Same as {@see \App\Services\Exact\Invoices\ExactSalesEntry::DOCUMENT_TYPE_SALES_INVOICE} (Exact Documents API Type). */
    private const DOCUMENT_TYPE_SALES_INVOICE = 10;

    public function __construct(
        private ExactOnlineService $exact,
    ) {}

    /**
     * Upload the order PDF linked to this document row to Exact CRM (Documents on Account).
     */
    public function uploadToCustomer(Document $document): ?string
    {
        if (! config('exact.enabled')) {
            return null;
        }

        return DB::transaction(function () use ($document): ?string {
            $row = Document::query()->whereKey($document->getKey())->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }

            if ($row->exact_id !== null && $row->exact_id !== '') {
                return $row->exact_id;
            }

            $row->loadMissing('documentable');
            $order = $row->documentable;
            if (! $order instanceof BaseOrder) {
                return null;
            }

            $type = $order->getType();
            if ($type === null) {
                return null;
            }

            $collection = Document::mediaCollectionForOrderType($type);
            if ($collection === null) {
                return null;
            }

            if ($order->getIsTest() || $this->exact->testmode) {
                $guid = 'TEST-DOC-' . now()->format('YmdHis') . '-' . $row->getKey();
                $row->exact_id = $guid;
                $row->exact_synced_at = now();
                $row->exact_error_at = null;
                $row->save();

                return $guid;
            }

            if (! $this->exact->ensureAccessTokenForApi()) {
                $this->exact->log('ExactOrderDocumentUploader: no access token for document ' . $row->getKey(), 'error');
                $row->exact_error_at = now();
                $row->save();

                return null;
            }

            try {
                $account = ExactAccountGuidForOrder::resolve($order, $this->exact);
            } catch (Exception $e) {
                $this->exact->log('ExactOrderDocumentUploader: ' . $e->getMessage(), 'error');
                $row->exact_error_at = now();
                $row->save();

                return null;
            }

            $order->loadMissing('media');
            $media = $order->getFirstMedia($collection);
            if (! $media) {
                $this->exact->log("ExactOrderDocumentUploader: no PDF media for order {$order->getId()} in collection {$collection}", 'warning');
                $row->exact_error_at = now();
                $row->save();

                return null;
            }

            $pdfPath = $media->getPath();
            if (! is_file($pdfPath)) {
                $this->exact->log("ExactOrderDocumentUploader: PDF missing at {$pdfPath}", 'error');
                $row->exact_error_at = now();
                $row->save();

                return null;
            }

            try {
                $exactDocumentId = $this->exact->createDocument([
                    'Type' => self::DOCUMENT_TYPE_SALES_INVOICE,
                    'Subject' => $this->documentSubject($order, $type),
                    'Account' => $account,
                    'DocumentDate' => now()->format('Y-m-d'),
                ]);

                if (! $exactDocumentId) {
                    $row->exact_error_at = now();
                    $row->save();

                    return null;
                }

                $attachmentId = $this->exact->uploadDocumentAttachment($exactDocumentId, $pdfPath);
                if (! $attachmentId) {
                    $this->exact->deleteResource('documents/Documents', $exactDocumentId);
                    $row->exact_error_at = now();
                    $row->save();

                    return null;
                }

                $row->exact_id = $exactDocumentId;
                $row->exact_synced_at = now();
                $row->exact_error_at = null;
                $row->save();

                return $exactDocumentId;
            } catch (Throwable $e) {
                $this->exact->log('ExactOrderDocumentUploader: ' . $e->getMessage(), 'error');
                $row->exact_error_at = now();
                $row->save();

                return null;
            }
        });
    }

    private function documentSubject(BaseOrder $order, OrderType $type): string
    {
        $uid = $order->getUidFormatted();

        return match ($type) {
            OrderType::Quote => 'Offerte ' . $uid,
            OrderType::Order => 'Orderbevestiging ' . $uid,
            OrderType::DepositInvoice => 'Aanbetalingsfactuur ' . $uid,
            OrderType::CreditInvoice => 'Creditfactuur ' . $uid,
            OrderType::Invoice => 'Factuur ' . $uid,
            default => 'Document ' . $uid,
        };
    }
}
