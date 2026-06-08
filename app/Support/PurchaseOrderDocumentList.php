<?php

namespace App\Support;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderConfirmation;
use App\Models\PurchaseOrderInvoice;
use Illuminate\Support\Collection;

class PurchaseOrderDocumentList
{
    /**
     * @return Collection<int, array{
     *     id: int,
     *     type: string,
     *     uid: string,
     *     sent_at: \Illuminate\Support\Carbon|null,
     *     modal?: array{id: string, arg: string},
     *     downloadLink?: string
     * }>
     */
    public static function for(PurchaseOrder $purchaseOrder): Collection
    {
        $purchaseOrder->loadMissing(['purchaseOrderInvoices', 'confirmations.purchaseOrder', 'media']);

        $items = $purchaseOrder->purchaseOrderInvoices
            ->merge($purchaseOrder->confirmations);

        $documents = $items->map(fn ($item) => match (true) {
            $item instanceof PurchaseOrderConfirmation => [
                'id' => $item->id,
                'type' => 'purchase_order_confirmation',
                'uid' => $item->purchaseOrder->reference_number,
                'sent_at' => $item->email_received_at,
                'modal' => [
                    'id' => 'open-purchase-order-confirmation',
                    'arg' => 'confirmationId',
                ],
                'downloadLink' => route('documents.purchaseOrderConfirmationDownload', ['id' => $item->id]),
            ],
            $item instanceof PurchaseOrderInvoice => [
                'id' => $item->id,
                'type' => 'purchase_invoice',
                'uid' => $item->documentListLabel($purchaseOrder),
                'sent_at' => $item->entry_date,
                'modal' => [
                    'id' => 'open-purchase-order-invoice',
                    'arg' => 'invoiceId',
                ],
                'downloadLink' => route('documents.purchaseOrderInvoiceDownload', ['id' => $item->id]),
            ],
            default => null,
        })->filter();

        $uploadedInvoices = $purchaseOrder->getMedia('documents')
            ->filter(fn ($media) => blank($media->getCustomProperty('purchase_order_invoice_id')))
            ->map(fn ($media) => [
                'id' => $media->id,
                'type' => 'purchase_invoice',
                'uid' => PurchaseOrderInvoice::orphanMediaListLabel($media),
                'sent_at' => $media->created_at,
                'modal' => [
                    'id' => 'open-document',
                    'arg' => 'mediaId',
                ],
                'downloadLink' => route('documents.media-download', ['id' => $media->id]),
            ]);

        return $documents
            ->toBase()
            ->concat($uploadedInvoices->toBase())
            ->sortByDesc(fn ($doc) => $doc['sent_at'] ?? null)
            ->values();
    }
}
