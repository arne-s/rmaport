<?php

namespace App\Services\Exact\Invoices;

use App\Enums\OrderType;
use App\Models\Document;
use App\Models\ExactVATCode;
use App\Models\Order\BaseOrder;
use App\Models\Order\DepositInvoice;
use App\Services\Exact\Documents\ExactOrderDocumentUploader;
use App\Services\Exact\ExactAccountGuidForOrder;
use App\Services\ExactOnlineService;
use App\Support\Exact\ExactApiErrorMessage;
use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Throwable;

class ExactSalesEntry
{
    public const DOCUMENT_TYPE_SALES_INVOICE = 10;

    /** @var array{guid: string, gl_revenue: string, vat_code: string}|null */
    private ?array $depositProductCache = null;

    public function __construct(
        private ExactOnlineService $exact,
    ) {}

    /**
     * Submit an invoice as a SalesEntry to Exact Online.
     *
     * @return array{invoice: BaseOrder, entry_number: int|null, journal: string|null, amount: float|null}|null When OAuth has no usable access token.
     *
     * @throws Exception
     */
    public function submitSalesEntry(BaseOrder $invoice): ?array
    {
        if ($invoice->getIsTest()) {
            $this->exact->log('[testmode] Running submit sales entry for invoice id: ' . $invoice->getId());

            $invoice->setExactId('TEST-' . now()->format('YmdHis') . '-' . $invoice->getId());
            $invoice->setExactSyncedAt(now());
            $invoice->setExactErrorAt(null);
            $invoice->save();

            return ['invoice' => $invoice, 'entry_number' => null, 'journal' => null, 'amount' => null];
        }

        $this->exact->log('Running submit sales entry for invoice id: ' . $invoice->getId());

        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('Skipping sales entry: no Exact OAuth access token available.', 'warning');

            return null;
        }

        $invoice->setExactErrorAt(now());
        $invoice->save();

        $documentId = $this->resolveCrmDocumentGuidForSalesEntry($invoice);

        $customer = ExactAccountGuidForOrder::resolve($invoice, $this->exact);
        $lines = $this->buildLines($invoice);
        $this->assertSalesEntryLinesHaveGlAccounts($lines);

        $isCreditNote = $invoice->getType() === OrderType::CreditInvoice;

        $body = [
            'Journal' => config('exact.sales_journal_code'),
            'Customer' => $customer,
            'Description' => $invoice->getUidFormatted(),
            'YourRef' => $this->buildYourRef($invoice),
            'EntryDate' => now()->format('Y-m-d'),
            'DueDate' => $invoice->resolveDueDateForExact(),
            'PaymentCondition' => $this->resolvePaymentCondition($invoice),
            'SalesEntryLines' => $lines,
        ];

        if ($isCreditNote) {
            $body['Type'] = 21;
        }

        if ($documentId) {
            $body['Document'] = $documentId;
        }

        $url = $this->exact->url('v1/' . config('exact.division') . '/salesentry/SalesEntries');

        $this->exact->log('Submitting sales entry, waiting for reply..', 'debug', $body);

        try {
            $response = $this->exact->client->post($url, [
                'exact_service' => 'ExactSalesEntry',
                'body' => json_encode($body),
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $entry = $data['d'] ?? [];
            $entryId = $entry['EntryID'] ?? null;

            if (! $entryId) {
                throw new Exception('Exact returned no EntryID for invoice ' . $invoice->getId());
            }

            $invoice->setExactId($entryId);
            $invoice->setExactSyncedAt(now());
            $invoice->setExactErrorAt(null);
            $invoice->save();

            return [
                'invoice' => $invoice,
                'entry_number' => $entry['EntryNumber'] ?? null,
                'journal' => $entry['Journal'] ?? null,
                'amount' => isset($entry['AmountDC']) ? (float) $entry['AmountDC'] : null,
            ];
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $rawBody = $response !== null ? (string) $response->getBody() : '';
            $parsed = ExactApiErrorMessage::fromResponseBody($rawBody)
                ?? ExactApiErrorMessage::fromResponse($response);
            $message = $parsed !== null
                ? $this->formatSalesEntryFailureMessage($invoice, $parsed)
                : "Error submitting sales entry for invoice {$invoice->getId()}: "
                    . "{$response?->getStatusCode()} {$rawBody}";
            $this->exact->log($message, 'error');
            throw new Exception($message, 0, $e);
        } catch (Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->exact->log('Error submitting sales entry: ' . $e->getMessage(), 'error');
            throw new Exception('Failed to submit sales entry: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws Exception
     */
    private function assertSalesEntryLinesHaveGlAccounts(array $lines): void
    {
        foreach ($lines as $line) {
            $glGuid = $line['GLAccount'] ?? null;
            if (is_string($glGuid) && $glGuid !== '') {
                continue;
            }

            $description = (string) ($line['Description'] ?? '');

            throw new Exception(
                'Factuurregel "'.$description.'" heeft geen omzet-grootboekrekening. '
                .'Koppel aan het product een artikelgroep met een omzetrekening (Filament → artikelgroepen).'
            );
        }
    }

    private function formatSalesEntryFailureMessage(BaseOrder $invoice, string $exactMessage): string
    {
        $uid = $invoice->getUidFormatted() ?: (string) $invoice->getId();
        $prefix = "Factuur #{$uid}: ";

        if (str_contains($exactMessage, 'Geblokkeerd') && str_contains($exactMessage, 'Grootboekrekening')) {
            return $prefix.$exactMessage
                .' Deblokkeer deze rekening in Exact Online (Grootboek → rekening eigenschappen), '
                .'of wijzig de omzetrekening op de artikelgroep van de betreffende producten en voer '
                .'`php artisan exact-online:sync-article-groups` uit.';
        }

        return $prefix.$exactMessage;
    }

    /**
     * Build the SalesEntryLines based on invoice type.
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    private function buildLines(BaseOrder $invoice): array
    {
        return match ($invoice->getType()) {
            OrderType::DepositInvoice => $this->buildDepositInvoiceLines($invoice),
            OrderType::Invoice => $this->buildFinalInvoiceLines($invoice),
            OrderType::CreditInvoice => $this->buildCreditInvoiceLines($invoice),
            default => throw new Exception("Unsupported invoice type: {$invoice->getType()?->value}"),
        };
    }

    /**
     * Deposit invoice: single line with the deposit product (RD.AANB.01).
     * Amount = total order value incl. VAT at deposit percentage.
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    private function buildDepositInvoiceLines(BaseOrder $invoice): array
    {
        $depositProduct = $this->resolveDepositProduct();
        $percentage = (int) ($invoice->getPaymentPercentage() ?? DepositInvoice::DEFAULT_DEPOSIT_PERCENTAGE);
        $depositAmount = (float) ($invoice->getPaymentAmount() ?? $invoice->getDepositAmount());
        $productCode = config('exact.deposit_product_code');

        return [
            [
                'Description' => "{$productCode} - Aanbetaling {$percentage}%",
                'AmountFC' => round($this->stripVatFromDeposit($depositAmount, $depositProduct['vat_code']), 2),
                'VATCode' => $depositProduct['vat_code'],
                'GLAccount' => $depositProduct['gl_revenue'],
            ],
        ];
    }

    /**
     * Final invoice: normal product lines + a negative deposit line to subtract the advance payment.
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    private function buildFinalInvoiceLines(BaseOrder $invoice): array
    {
        $lines = $this->buildProductLines($invoice);

        if ($invoice->getDepositAmount() > 0) {
            $order = $invoice->order;
            $mainId = $order?->main_id ?? $invoice->main_id;
            $depositInvoice = null;
            if ($mainId !== null) {
                $depositInvoice = DepositInvoice::query()
                    ->where('main_id', $mainId)
                    ->orderByDesc('id')
                    ->first();
            }

            $depositProduct = $this->resolveDepositProduct();
            $percentage = (int) ($depositInvoice?->getPaymentPercentage() ?? DepositInvoice::DEFAULT_DEPOSIT_PERCENTAGE);
            $depositAmount = $invoice->getDepositAmount();
            $productCode = config('exact.deposit_product_code');

            $lines[] = [
                'Description' => "{$productCode} - Aanbetaling {$percentage}%",
                'AmountFC' => round(-$this->stripVatFromDeposit($depositAmount, $depositProduct['vat_code']), 2),
                'VATCode' => $depositProduct['vat_code'],
                'GLAccount' => $depositProduct['gl_revenue'],
            ];
        }

        return $lines;
    }

    /**
     * Credit invoice: negative amounts per credited line.
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    private function buildCreditInvoiceLines(BaseOrder $invoice): array
    {
        $lines = [];

        foreach ($invoice->orderProducts as $orderProduct) {
            if (! $orderProduct->getHasCredit()) {
                continue;
            }

            $creditedAmount = $orderProduct->getCompanySalesPriceCredited();

            $lines[] = [
                'Description' => $orderProduct->getValue(),
                'AmountFC' => round(-abs($creditedAmount), 2),
                'VATCode' => $this->resolveSalesVatCode($orderProduct),
                'GLAccount' => $orderProduct->product?->exactArticleGroup?->getRevenueGlAccount()?->guid,
            ];
        }

        return $lines;
    }

    /**
     * Build product lines for a regular invoice (no factor scaling).
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    private function buildProductLines(BaseOrder $invoice): array
    {
        $lines = [];

        foreach ($invoice->orderProducts as $orderProduct) {
            $priceExVat = $orderProduct->getCompanyPriceIncludedProducts();

            $lines[] = [
                'Description' => $orderProduct->getValue(),
                'AmountFC' => round($priceExVat, 2),
                'VATCode' => $this->resolveSalesVatCode($orderProduct),
                'GLAccount' => $orderProduct->product?->exactArticleGroup?->getRevenueGlAccount()?->guid,
            ];
        }

        return $lines;
    }

    /**
     * Upload the invoice PDF to Exact as a Document + Attachment.
     * Returns the Document GUID or null on failure.
     */
    public function uploadPdfAndCreateDocument(BaseOrder $invoice): ?string
    {
        $type = $invoice->getType();
        $collection = match ($type) {
            OrderType::DepositInvoice => 'deposit_invoice',
            OrderType::CreditInvoice => 'credit_invoice',
            default => 'invoice',
        };

        $media = $invoice->getFirstMedia($collection);
        if (! $media) {
            $this->exact->log("No PDF media found for invoice {$invoice->getId()} in collection {$collection}", 'warning');
            return null;
        }

        $pdfPath = $media->getPath();
        if (! file_exists($pdfPath)) {
            $this->exact->log("PDF file not found at {$pdfPath} for invoice {$invoice->getId()}", 'warning');
            return null;
        }

        try {
            $customer = ExactAccountGuidForOrder::resolve($invoice, $this->exact);

            $documentId = $this->exact->createDocument([
                'Type' => self::DOCUMENT_TYPE_SALES_INVOICE,
                'Subject' => 'Factuur ' . $invoice->getUidFormatted(),
                'Account' => $customer,
                'DocumentDate' => now()->format('Y-m-d'),
            ]);

            if (! $documentId) {
                $this->exact->log('Failed to create document for invoice ' . $invoice->getId(), 'error');
                return null;
            }

            $attachmentId = $this->exact->uploadDocumentAttachment($documentId, $pdfPath);
            if (! $attachmentId) {
                $this->exact->log('Failed to upload PDF attachment for invoice ' . $invoice->getId(), 'error');
                $this->exact->deleteResource('documents/Documents', $documentId);
                return null;
            }

            return $documentId;
        } catch (Throwable $e) {
            $this->exact->log('Error uploading invoice document: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Prefer the CRM document already linked to the {@see Document} snapshot (queue or prior upload),
     * otherwise create the legacy invoice PDF document in Exact.
     */
    private function resolveCrmDocumentGuidForSalesEntry(BaseOrder $invoice): ?string
    {
        $doc = Document::query()
            ->where('documentable_type', $invoice->getMorphClass())
            ->where('documentable_id', $invoice->getId())
            ->latest('id')
            ->first();

        if ($doc === null) {
            return $this->uploadPdfAndCreateDocument($invoice);
        }

        if ($doc->exact_id !== null && $doc->exact_id !== '') {
            return $doc->exact_id;
        }

        $uploader = app(ExactOrderDocumentUploader::class);
        $id = $uploader->uploadToCustomer($doc);

        return $id ?? $this->uploadPdfAndCreateDocument($invoice);
    }

    /**
     * Resolve the Exact payment condition from the billing party, falling back to config.
     */
    private function resolvePaymentCondition(BaseOrder $invoice): string
    {
        $condition = $invoice->getExactPaymentConditionCodeForView();
        if ($condition !== '') {
            return $condition;
        }

        $condition = $invoice->billingCustomer?->getExactPaymentCondition()
            ?? $invoice->customer?->getExactPaymentCondition();

        return $condition ?: config('exact.payment_condition_id');
    }

    private function buildYourRef(BaseOrder $invoice): ?string
    {
        $ref = $invoice->main?->getReference()
            ?? $invoice->order?->main?->getReference();

        if ($invoice->getType() === OrderType::DepositInvoice) {
            $percentage = (int) ($invoice->getPaymentPercentage() ?? DepositInvoice::DEFAULT_DEPOSIT_PERCENTAGE);
            $suffix = "{$percentage}% aanbetaling";
            return $ref ? "{$ref} - {$suffix}" : $suffix;
        }

        return $ref;
    }

    /**
     * Resolve the Exact sales VAT code for an order product.
     */
    private function resolveSalesVatCode(\App\Models\OrderProduct $orderProduct): string
    {
        $salesVatCode = $orderProduct->product?->exactSalesVatCode?->code;
        if ($salesVatCode !== null) {
            return $salesVatCode;
        }

        $vatPct = $orderProduct->getVat();

        return match (true) {
            abs($vatPct - 21) < 0.01 => '2',
            abs($vatPct - 9) < 0.01 => '1',
            abs($vatPct) < 0.01 => '0',
            default => ExactVATCode::DEFAULT_SALES_VAT_CODE,
        };
    }

    /**
     * Convert an incl. VAT deposit amount to excl. VAT when using an exclusive VAT code.
     */
    private function stripVatFromDeposit(float $amountIncVat, string $vatCode): float
    {
        $vatRecord = ExactVATCode::query()->where('code', $vatCode)->first();

        if ($vatRecord && $vatRecord->type === 'E' && $vatRecord->percentage > 0) {
            return $amountIncVat / (1 + (float) $vatRecord->percentage);
        }

        return $amountIncVat;
    }

    /**
     * Look up the deposit product (RD.AANB.01) in Exact and return its GLRevenue + VATCode.
     *
     * @return array{gl_revenue: string, vat_code: string}
     * @throws Exception
     */
    private function resolveDepositProduct(): array
    {
        if ($this->depositProductCache !== null) {
            return $this->depositProductCache;
        }

        if ($this->exact->testmode) {
            $this->depositProductCache = [
                'gl_revenue' => config('exact.testdata.product_item_group'),
                'vat_code' => ExactVATCode::DEFAULT_SALES_VAT_CODE,
            ];

            return $this->depositProductCache;
        }

        $code = config('exact.deposit_product_code');
        $url = $this->exact->url(
            'v1/' . config('exact.division')
            . "/logistics/Items?\$filter=Code eq '{$code}'"
            . '&$select=ID,Code,Description,GLRevenue,SalesVatCode&$top=1'
        );

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactSalesEntry',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $items = $data['d']['results'] ?? $data['d'] ?? [];
            $item = $items[0] ?? null;

            if (! $item || empty($item['GLRevenue'])) {
                throw new Exception("Deposit product '{$code}' not found or has no GLRevenue in Exact Online.");
            }

            $this->depositProductCache = [
                'gl_revenue' => $item['GLRevenue'],
                'vat_code' => config('exact.deposit_vat_code')
                    ?? trim($item['SalesVatCode'] ?? ExactVATCode::DEFAULT_SALES_VAT_CODE),
            ];

            return $this->depositProductCache;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            throw new Exception(
                "Error looking up deposit product '{$code}': "
                . "{$response?->getStatusCode()} {$response?->getBody()?->getContents()}",
                0,
                $e
            );
        }
    }

    /**
     * Delete a sales entry from Exact Online.
     *
     * @throws Exception
     */
    public function deleteSalesEntry(BaseOrder $invoice): bool
    {
        $this->exact->log('Running deleteSalesEntry for invoice id: ' . $invoice->getId());

        $exactId = $invoice->getExactId();
        if (! $exactId) {
            throw new Exception('No Exact ID found for invoice ' . $invoice->getId());
        }

        return $this->exact->deleteResource('salesentry/SalesEntries', $exactId);
    }
}
