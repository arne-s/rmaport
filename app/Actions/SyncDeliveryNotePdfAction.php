<?php

namespace App\Actions;

use App\Models\DeliveryNote;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Support\PackingSlipDocumentSequence;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use QR_Code\QR_Code;
use Throwable;

class SyncDeliveryNotePdfAction
{
    public function execute(Order $order): void
    {
        if ($order->main_id === null) {
            return;
        }

        $uid = $order->getUid();
        if ($uid === null || $uid === '') {
            return;
        }

        $order->loadMissing([
            'main',
            'advisor',
            'customer.shippingAddress',
            'customer.billingAddress',
            'customer.address',
            'shippingCustomer.shippingAddress',
            'shippingCustomer.billingAddress',
            'shippingCustomer.address',
            'billingCustomer.billingAddress',
        ]);

        $main = $order->main;
        if (! $main instanceof Main) {
            return;
        }

        $purchaseTabUrl = URL::route('filament.app.resources.mains.view', ['record' => $main->getId()]) . '?tab=purchase';

        $qrDataUri = $this->buildQrDataUri($purchaseTabUrl);

        $products = $order->packingSlipEligibleOrderProducts()
            ->orderBy('order_products.sort')
            ->with('product')
            ->get();

        $this->deleteDeliveryNotesForSiblingOrders($order);

        $deliveryNote = DeliveryNote::query()->firstOrNew(['order_id' => $order->getId()]);
        if (blank($deliveryNote->uid)) {
            $deliveryNote->uid = PackingSlipDocumentSequence::next();
            $deliveryNote->save();
        }

        try {
            $html = view('order.delivery-note', [
                'main' => $main,
                'order' => $order,
                'deliveryNote' => $deliveryNote,
                'products' => $products,
                'qrDataUri' => $qrDataUri,
            ])->render();

            $pdf = PDF::loadHTML($html)
                ->setOption('margin-top', $order->getPdfSettings('margin-top', 'packing_slip'))
                ->setOption('margin-bottom', 12)
                ->setOption('margin-left', 0)
                ->setOption('margin-right', 0)
                ->setOption('header-html', $order->getPdfSettings('header-html', 'packing_slip'))
                ->setOption('header-spacing', $order->getPdfSettings('header-spacing', 'packing_slip'));

            $tempPath = tempnam(sys_get_temp_dir(), 'delivery-note-');
            if (! is_string($tempPath)) {
                return;
            }
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            $pdf->save($tempPath);

            $deliveryNote->clearMediaCollection('pdf');
            $deliveryNote->addMedia($tempPath)
                ->usingFileName('pakbon-' . $deliveryNote->uid . '.pdf')
                ->withCustomProperties([
                    'readonly' => true,
                ])
                ->toMediaCollection('pdf');

            @unlink($tempPath);

            $deliveryNote->unsetRelation('media');
            $this->syncDeliveryNoteMediaToMainShippingDocuments($main, $deliveryNote);
        } catch (Throwable $e) {
            Log::warning('Delivery note PDF sync failed', [
                'order_id' => $order->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Mirror the delivery note PDF onto the main's "delivery_documents" collection so the shipping tab
     * {@see \App\Http\Livewire\DocumentsBlock} lists it with uploads and PostNL labels.
     */
    private function syncDeliveryNoteMediaToMainShippingDocuments(Main $main, DeliveryNote $deliveryNote): void
    {
        $deliveryNote->load('media');
        $pdfMedia = $deliveryNote->getFirstMedia('pdf');
        if ($pdfMedia === null) {
            return;
        }

        $path = $pdfMedia->getPath();
        if (! is_file($path)) {
            return;
        }

        $main->unsetRelation('media');
        foreach ($main->getMedia('delivery_documents') as $existing) {
            if ((bool) ($existing->getCustomProperty('delivery_note_pdf') ?? false)) {
                $existing->delete();
            }
        }

        $main->addMedia($path)
            ->usingFileName((string) $pdfMedia->file_name)
            ->withCustomProperties([
                'readonly' => true,
                'delivery_note_pdf' => true,
            ])
            ->toMediaCollection('delivery_documents');

        $main->unsetRelation('media');
    }

    private function buildQrDataUri(string $url): ?string
    {
        try {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr-' . uniqid('', true) . '.png';
            QR_Code::png($url, $path, QR_ECLEVEL_M, 4, 2);
            if (! file_exists($path)) {
                return null;
            }
            $binary = file_get_contents($path);
            @unlink($path);
            if ($binary === false) {
                return null;
            }

            return 'data:image/png;base64,' . base64_encode($binary);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keep at most one delivery note per main: remove notes for other order revisions under the same main.
     */
    private function deleteDeliveryNotesForSiblingOrders(Order $current): void
    {
        $mainId = $current->main_id;
        if ($mainId === null) {
            return;
        }

        $otherOrderIds = Order::query()
            ->where('main_id', $mainId)
            ->where('id', '!=', $current->getId())
            ->pluck('id');

        if ($otherOrderIds->isEmpty()) {
            return;
        }

        DeliveryNote::query()
            ->whereIn('order_id', $otherOrderIds)
            ->get()
            ->each(function (DeliveryNote $note): void {
                $note->clearMediaCollection('pdf');
                $note->delete();
            });
    }
}
