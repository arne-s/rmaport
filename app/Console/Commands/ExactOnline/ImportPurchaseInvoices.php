<?php

namespace App\Console\Commands\ExactOnline;

use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImportPurchaseInvoices extends Command
{
    protected $signature = 'exact-online:import-purchase-invoices {--days=7 : Number of days to look back (0 = all)}';

    protected $description = 'Import purchase invoices from Exact Online and link them to purchase/stock orders when possible';

    private const UID_PATTERN = '/(MT[OS]-\d{4}-\d{4}(?:-\d+)?)/';

    public function handle(ExactOnlineService $exact): int
    {
        if (! $exact->ensureAccessTokenForApi()) {
            $this->error('Could not obtain Exact Online access token.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $this->info("Fetching purchase entries from Exact Online" . ($days > 0 ? " (last {$days} day(s))" : ' (all)') . '...');

        $entries = $exact->getPurchaseEntries($days);
        $this->info("Found " . count($entries) . " entries.");

        $stats = [
            'processed' => 0,
            'imported' => 0,
            'imported_orphan' => 0,
            'linked' => 0,
            'linked_to_po' => 0,
            'pdf_attached' => 0,
            'pdf_still_missing' => 0,
            'skipped_exists' => 0,
            'failed' => 0,
        ];

        foreach ($entries as $entry) {
            $stats['processed']++;

            $entryId = $entry['EntryID'] ?? null;
            if (! $entryId) {
                continue;
            }

            $existing = PurchaseOrderInvoice::query()->where('exact_id', $entryId)->first();
            $purchaseOrder = $this->matchPurchaseOrderFromEntry($entry);

            if ($existing !== null) {
                if (! $existing->isLinkedToPurchaseOrder() && $purchaseOrder !== null) {
                    try {
                        $this->linkOrphanInvoiceToPurchaseOrder($existing, $purchaseOrder, $entry, $exact);
                        $stats['linked_to_po']++;
                        $this->info("  Linked orphan to PO: {$entry['Description']} -> {$purchaseOrder->reference_number}");
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        $this->error("  Failed linking orphan: {$entry['Description']} — {$e->getMessage()}");
                        Log::error("ImportPurchaseInvoices link orphan failed for entry {$entryId}: " . (string) $e);
                    }
                } else {
                    $purchaseOrderForPdf = $purchaseOrder ?? $existing->purchaseOrder();

                    if ($existing->resolveLinkedMedia($purchaseOrderForPdf) === null) {
                        try {
                            $existing->purgeInvalidLinkedDocumentMedia($purchaseOrderForPdf);
                            $this->attachPdf($entry, $existing, $exact, $purchaseOrderForPdf);

                            $existing = $existing->fresh();

                            if ($existing->resolveLinkedMedia($purchaseOrderForPdf) !== null) {
                                $stats['pdf_attached']++;
                                $this->info("  PDF attached (existing): {$existing->invoice_number}");
                            } else {
                                $stats['pdf_still_missing']++;
                                $this->warn("  PDF still missing (existing): {$existing->invoice_number}");
                            }
                        } catch (\Throwable $e) {
                            $stats['failed']++;
                            $this->error("  Failed attaching PDF: {$existing->invoice_number} — {$e->getMessage()}");
                            Log::error("ImportPurchaseInvoices attach PDF failed for entry {$entryId}: " . (string) $e);
                        }
                    } else {
                        $stats['skipped_exists']++;
                        $this->line("  Skip (exists): {$entryId}");
                    }
                }

                continue;
            }

            try {
                if ($purchaseOrder !== null) {
                    [$invoice, $linked] = $this->createOrLinkInvoiceRecord($entry, $purchaseOrder, $exact);
                    $this->attachPdf($entry, $invoice, $exact, $purchaseOrder);

                    if ($linked) {
                        $stats['linked']++;
                        $this->info("  Linked existing: {$entry['Description']} -> {$purchaseOrder->reference_number}");
                    } else {
                        $stats['imported']++;
                        $this->info("  Imported: {$entry['Description']} -> {$purchaseOrder->reference_number}");
                    }
                } else {
                    $invoice = $this->createOrphanInvoiceRecord($entry, $exact);
                    $this->attachPdf($entry, $invoice, $exact);
                    $stats['imported_orphan']++;
                    $this->info("  Imported (unlinked): {$entry['Description']}");
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->error("  Failed: {$entry['Description']} — {$e->getMessage()}");
                Log::error("ImportPurchaseInvoices failed for entry {$entryId}: " . (string) $e);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($v, $k) => [ucfirst(str_replace('_', ' ', $k)), $v])->values()->all()
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function matchPurchaseOrderFromEntry(array $entry): ?PurchaseOrder
    {
        foreach ([
            $entry['Description'] ?? '',
            $entry['YourRef'] ?? '',
            $entry['InvoiceNumber'] ?? '',
        ] as $text) {
            if (! is_string($text) || $text === '') {
                continue;
            }

            $purchaseOrder = $this->matchPurchaseOrder($text);

            if ($purchaseOrder !== null) {
                return $purchaseOrder;
            }
        }

        return null;
    }

    private function matchPurchaseOrder(string $description): ?PurchaseOrder
    {
        if (! preg_match(self::UID_PATTERN, $description, $matches)) {
            return null;
        }

        $uid = $matches[1];

        if (str_starts_with($uid, 'MTO-')) {
            return PurchaseOrder::where('reference_number', $uid)->first();
        }

        if (str_starts_with($uid, 'MTS-')) {
            $stockOrder = BaseOrder::withoutGlobalScopes()
                ->where('type', 'stock_order')
                ->where('uid', $uid)
                ->first();

            if (! $stockOrder) {
                return null;
            }

            return PurchaseOrder::where('order_id', $stockOrder->id)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: PurchaseOrderInvoice, 1: bool}
     */
    private function createOrLinkInvoiceRecord(array $entry, PurchaseOrder $purchaseOrder, ExactOnlineService $exact): array
    {
        $attributes = $this->buildInvoiceAttributesFromEntry($entry, $exact);
        $invoiceNumber = $attributes['invoice_number'] ?? null;

        if (filled($invoiceNumber)) {
            $existing = PurchaseOrderInvoice::query()
                ->where('orderable_type', PurchaseOrder::class)
                ->where('orderable_id', $purchaseOrder->id)
                ->where('invoice_number', $invoiceNumber)
                ->whereNull('exact_id')
                ->first();

            if ($existing !== null) {
                $existing->update([
                    ...$attributes,
                    'exact_id' => $entry['EntryID'],
                    'exact_synced_at' => now(),
                    'exact_error_at' => null,
                    'exact_error_message' => null,
                ]);

                return [$existing->fresh(), true];
            }
        }

        $invoice = PurchaseOrderInvoice::create([
            'orderable_type' => PurchaseOrder::class,
            'orderable_id' => $purchaseOrder->id,
            'main_id' => $purchaseOrder->main_id,
            'exact_id' => $entry['EntryID'],
            'exact_synced_at' => now(),
            ...$attributes,
        ]);

        return [$invoice, false];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function createOrphanInvoiceRecord(array $entry, ExactOnlineService $exact): PurchaseOrderInvoice
    {
        return PurchaseOrderInvoice::create([
            'orderable_type' => null,
            'orderable_id' => null,
            'main_id' => null,
            'exact_id' => $entry['EntryID'],
            'exact_synced_at' => now(),
            ...$this->buildInvoiceAttributesFromEntry($entry, $exact),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function buildInvoiceAttributesFromEntry(array $entry, ExactOnlineService $exact): array
    {
        $entryDate = ! empty($entry['EntryDate'])
            ? $exact->parseDotNetDate($entry['EntryDate'])->toDateString()
            : now()->toDateString();

        $dueDate = ! empty($entry['DueDate'])
            ? $exact->parseDotNetDate($entry['DueDate'])->toDateString()
            : null;

        $amount = (float) ($entry['AmountDC'] ?? 0);
        $vatAmount = isset($entry['VATAmountDC']) ? (float) $entry['VATAmountDC'] : null;
        $totalIncVat = $vatAmount !== null ? $amount + $vatAmount : null;

        return [
            'description' => $entry['Description'] ?? '',
            'amount' => $amount,
            'vat_amount' => $vatAmount,
            'total_amount_inc_vat' => $totalIncVat,
            'currency' => $entry['Currency'] ?? 'EUR',
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'invoice_number' => $entry['InvoiceNumber'] ?? null,
            'supplier_name' => $entry['SupplierName'] ?? null,
            'document_id' => $entry['Document'] ?? null,
        ];
    }

    private function linkOrphanInvoiceToPurchaseOrder(
        PurchaseOrderInvoice $invoice,
        PurchaseOrder $purchaseOrder,
        array $entry,
        ExactOnlineService $exact,
    ): void {
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
        } else {
            $this->attachPdf($entry, $invoice, $exact, $purchaseOrder);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function attachPdf(array $entry, PurchaseOrderInvoice $invoice, ExactOnlineService $exact, ?PurchaseOrder $purchaseOrder = null): void
    {
        if ($invoice->resolveLinkedMedia($purchaseOrder) !== null) {
            return;
        }

        $documentId = $entry['Document'] ?? $invoice->document_id ?? null;
        if (! filled($documentId)) {
            return;
        }

        $attachments = $exact->getDocumentAttachments($documentId);
        if (empty($attachments)) {
            return;
        }

        $attachment = $attachments[0];
        $fileContent = $this->downloadAttachmentContent($attachment, $exact);
        if ($fileContent === '' || ! str_starts_with($fileContent, '%PDF')) {
            return;
        }

        ['filename' => $filename, 'display_name' => $displayName] = $this->resolveFilename($invoice, $purchaseOrder);

        $mediaBuilder = ($purchaseOrder ?? $invoice)
            ->addMediaFromString($fileContent)
            ->usingFileName($filename)
            ->usingName($displayName)
            ->withCustomProperties([
                'purchase_order_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'entry_date' => $invoice->entry_date?->format('Y-m-d'),
                'readonly' => true,
            ]);

        $media = $mediaBuilder->toMediaCollection('documents');

        if ($purchaseOrder === null) {
            return;
        }

        $this->copyPurchaseOrderMediaToRelatedOrders($media, $purchaseOrder);
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

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function downloadAttachmentContent(array $attachment, ExactOnlineService $exact): ?string
    {
        $base64 = $attachment['Attachment'] ?? '';
        if (! empty($base64)) {
            return base64_decode($base64);
        }

        $url = $attachment['Url'] ?? null;
        if (! $url) {
            return null;
        }

        try {
            $response = $exact->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $exact->getCurrentAccessToken(),
                ],
            ]);

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            $this->warn("  Could not download attachment from {$url}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @return array{filename: string, display_name: string}
     */
    private function resolveFilename(PurchaseOrderInvoice $invoice, ?PurchaseOrder $purchaseOrder = null): array
    {
        $prefix = $invoice->amount < 0 ? 'Factuur' : 'Creditfactuur';

        if ($purchaseOrder === null) {
            $label = filled($invoice->invoice_number)
                ? (string) $invoice->invoice_number
                : ('Exact ' . ($invoice->exact_id ?? $invoice->id));

            return [
                'filename' => "{$prefix} {$label}.pdf",
                'display_name' => "{$prefix} | {$label}",
            ];
        }

        $uid = $purchaseOrder->reference_number;

        $existingCount = $purchaseOrder->purchaseOrderInvoices()
            ->where('id', '!=', $invoice->id)
            ->where('amount', $invoice->amount < 0 ? '<' : '>=', 0)
            ->count();

        $suffix = $existingCount > 0 ? ' (' . ($existingCount + 1) . ')' : '';

        return [
            'filename' => "{$prefix} Inkoop {$uid}{$suffix}.pdf",
            'display_name' => "{$prefix} | Inkoop | {$uid}{$suffix}",
        ];
    }
}
