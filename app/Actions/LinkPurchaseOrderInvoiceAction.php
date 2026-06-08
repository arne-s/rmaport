<?php

namespace App\Actions;

use App\Models\Order\Main;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class LinkPurchaseOrderInvoiceAction
{
    public function execute(PurchaseOrderInvoice $invoice, PurchaseOrder $purchaseOrder): PurchaseOrderInvoice
    {
        if ($invoice->isLinkedToPurchaseOrder()) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Deze factuur is al gekoppeld aan een inkooporder.',
            ]);
        }

        if ($purchaseOrder->is_cancelled === true) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Deze inkooporder is geannuleerd en kan niet worden gekoppeld.',
            ]);
        }

        if (! $purchaseOrder->isLinkable()) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Concept-inkooporders kunnen niet worden gekoppeld.',
            ]);
        }

        $invoice->update([
            'orderable_type' => PurchaseOrder::class,
            'orderable_id' => $purchaseOrder->id,
            'main_id' => $purchaseOrder->main_id,
        ]);

        $invoice = $invoice->fresh();

        $ownMedia = $invoice->getMedia('documents')->first();

        if ($ownMedia instanceof Media) {
            $this->copyMediaToPurchaseOrder($ownMedia, $purchaseOrder, $invoice);
            $ownMedia->delete();
        }

        return $invoice;
    }

    private function copyMediaToPurchaseOrder(Media $sourceMedia, PurchaseOrder $purchaseOrder, PurchaseOrderInvoice $invoice): void
    {
        $media = $purchaseOrder
            ->addMediaFromString($sourceMedia->get())
            ->usingFileName($sourceMedia->file_name)
            ->usingName($sourceMedia->name)
            ->withCustomProperties([
                'purchase_order_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'entry_date' => $invoice->entry_date?->format('Y-m-d'),
                'readonly' => true,
            ])
            ->toMediaCollection('documents');

        $this->copyPurchaseOrderMediaToRelatedOrders($media, $purchaseOrder);
    }

    private function copyPurchaseOrderMediaToRelatedOrders(Media $media, PurchaseOrder $purchaseOrder): void
    {
        $baseOrder = $purchaseOrder->order;

        if ($baseOrder && $baseOrder->type === 'stock_order') {
            $stockOrder = StockOrder::withoutGlobalScopes()->find($baseOrder->id);

            if ($stockOrder) {
                $media->copy($stockOrder, 'documents');
            }
        }

        $main = $purchaseOrder->main_id
            ? Main::find($purchaseOrder->main_id)
            : null;

        if ($main) {
            $media->copy($main, 'financial_documents');
        }
    }
}
