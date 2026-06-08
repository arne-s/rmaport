<?php

namespace App\Services\Exact\Documents;

use App\Enums\ExactDocumentMappedType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExactDocument;
use App\Services\ExactOnlineService;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExactDocumentImportService
{
    public function __construct(
        private ExactDocuments $exactDocuments,
        private ExactOnlineService $exact,
    ) {}

    /**
     * Import all documents from Exact for the given customer.
     *
     * @return int Number of newly imported documents.
     */
    public function importForCustomer(Customer $customer): int
    {
        if ($customer->exact_id === null || $customer->exact_id === '') {
            return 0;
        }

        $remoteDocuments = $this->exactDocuments->fetchForAccount($customer->exact_id);

        if ($remoteDocuments === []) {
            return 0;
        }

        // Pre-load exact IDs already tracked in our system to skip them efficiently
        $alreadyUploadedByUs = $this->loadUploadedExactIds();
        $alreadyImported = $this->loadImportedExactIdsForCustomer((int) $customer->id);

        $imported = 0;

        foreach ($remoteDocuments as $doc) {
            $exactId = $this->extractId($doc);
            if ($exactId === null) {
                continue;
            }

            // Skip documents we uploaded to Exact ourselves (they are shown via orders already)
            if (isset($alreadyUploadedByUs[$exactId])) {
                continue;
            }

            // Skip if already imported in a previous run
            if (isset($alreadyImported[$exactId])) {
                continue;
            }

            $exactType = isset($doc['Type']) ? (int) $doc['Type'] : 0;
            $typeDescription = trim((string) ($doc['TypeDescription'] ?? ''));
            $subject = trim((string) ($doc['Subject'] ?? ''));
            $documentDateRaw = $doc['DocumentDate'] ?? null;
            $documentDate = $this->parseExactDate($documentDateRaw);

            $mappedType = ExactDocumentMappedType::fromExact($exactType, $typeDescription, $subject);

            try {
                $record = DB::transaction(function () use (
                    $customer,
                    $exactId,
                    $exactType,
                    $typeDescription,
                    $subject,
                    $mappedType,
                    $documentDate,
                ): ExactDocument {
                    /** @var ExactDocument $record */
                    $record = ExactDocument::query()->create([
                        'customer_id' => $customer->id,
                        'exact_id' => $exactId,
                        'exact_type' => $exactType ?: null,
                        'exact_type_description' => $typeDescription !== '' ? $typeDescription : null,
                        'exact_subject' => $subject !== '' ? $subject : null,
                        'mapped_type' => $mappedType->value,
                        'document_date' => $documentDate,
                        'exact_synced_at' => now(),
                    ]);

                    return $record;
                });

                // Download and store the PDF outside the transaction to avoid long-held locks
                $pdfBytes = $this->exactDocuments->downloadPdf($exactId);
                if ($pdfBytes !== null && $pdfBytes !== '') {
                    $fileName = $this->buildPdfFileName($subject, $exactId);
                    $record->addMediaFromString($pdfBytes)
                        ->usingFileName($fileName)
                        ->toMediaCollection('pdf');
                }

                $alreadyImported[$exactId] = true;
                $imported++;
            } catch (Throwable $e) {
                $this->exact->log(
                    "ExactDocumentImportService: failed to import document {$exactId} for customer {$customer->id}: " . $e->getMessage(),
                    'error'
                );
            }
        }

        return $imported;
    }

    /**
     * Returns a lookup map of exact_id → true for all documents already uploaded from this application.
     *
     * @return array<string, bool>
     */
    private function loadUploadedExactIds(): array
    {
        return Document::query()
            ->whereNotNull('exact_id')
            ->pluck('exact_id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();
    }

    /**
     * Returns a lookup map of exact_id → true for all ExactDocuments already imported for this customer.
     *
     * @return array<string, bool>
     */
    private function loadImportedExactIdsForCustomer(int $customerId): array
    {
        return ExactDocument::query()
            ->where('customer_id', $customerId)
            ->pluck('exact_id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();
    }

    private function extractId(mixed $doc): ?string
    {
        if (! is_array($doc)) {
            return null;
        }

        $id = $doc['ID'] ?? $doc['Id'] ?? $doc['id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        $id = trim($id, " \t\n\r\0\x0B{}");

        return $id !== '' ? $id : null;
    }

    private function parseExactDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Exact returns dates as "/Date(milliseconds)/"
        if (is_string($value) && preg_match('#/Date\((\d+)\)/#', $value, $m) === 1) {
            return date('Y-m-d', (int) ($m[1] / 1000));
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function buildPdfFileName(string $subject, string $exactId): string
    {
        $base = $subject !== '' ? $subject : $exactId;
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $base) ?? $exactId;
        $safe = trim($safe, '_');

        return ($safe !== '' ? $safe : $exactId) . '.pdf';
    }
}
