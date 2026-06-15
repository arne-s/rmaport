<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowValidationStatus;
use App\Models\Customer;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Product;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;

final class ImportRowValidator
{
    use MapsRmaImportRows;

    public function __construct(
        private readonly ImportRowTransformer $transformer = new ImportRowTransformer,
    ) {}

    /**
     * @param  list<array<string, string|null>>  $parsedRows
     */
    public function validate(ImportTemplate $template, int $customerId, array $parsedRows): ImportRowValidationResult
    {
        $customerExists = Customer::query()->whereKey($customerId)->exists();
        $productEans = $this->loadProductEans();
        $existingReferences = ImportRow::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('reference')
            ->pluck('reference')
            ->flip()
            ->all();

        $seenReferences = [];
        $issues = [];
        $newCount = 0;
        $existingCount = 0;
        $invalidCount = 0;

        foreach ($parsedRows as $index => $parsedRow) {
            $rowNumber = $index + 1;
            $attributes = $this->transformer->transform($template, $parsedRow);

            if ($attributes === []) {
                $issues[] = new ImportRowValidationIssue(
                    rowNumber: $rowNumber,
                    status: ImportRowValidationStatus::Invalid,
                    reference: null,
                    eanNr: null,
                    reasons: ['Rij kon niet worden verwerkt'],
                );
                $invalidCount++;

                continue;
            }

            $reference = $attributes['reference'] ?? null;
            $eanNr = $attributes['ean_nr'] ?? null;
            $normalizedEan = $this->normalizeEan($eanNr);
            $reasons = [];

            if (! $customerExists) {
                $reasons[] = 'Klant bestaat niet';
            }

            if ($reference === null) {
                $reasons[] = 'Referentie ontbreekt';
            }

            if ($normalizedEan === null) {
                $reasons[] = 'EAN ontbreekt of is ongeldig';
            } elseif (! array_key_exists($normalizedEan, $productEans)) {
                $reasons[] = 'EAN komt niet overeen met een product';
            }

            if ($reference !== null && array_key_exists($reference, $seenReferences)) {
                $reasons[] = 'Dubbele referentie in importbestand';
            }

            if ($reference !== null) {
                $seenReferences[$reference] = true;
            }

            if ($reasons !== []) {
                $issues[] = new ImportRowValidationIssue(
                    rowNumber: $rowNumber,
                    status: ImportRowValidationStatus::Invalid,
                    reference: $reference,
                    eanNr: $eanNr,
                    reasons: $reasons,
                    attributes: $attributes,
                );
                $invalidCount++;

                continue;
            }

            if ($reference !== null && array_key_exists($reference, $existingReferences)) {
                $issues[] = new ImportRowValidationIssue(
                    rowNumber: $rowNumber,
                    status: ImportRowValidationStatus::Existing,
                    reference: $reference,
                    eanNr: $eanNr,
                    attributes: $attributes,
                );
                $existingCount++;

                continue;
            }

            $issues[] = new ImportRowValidationIssue(
                rowNumber: $rowNumber,
                status: ImportRowValidationStatus::New,
                reference: $reference,
                eanNr: $eanNr,
                attributes: $attributes,
            );
            $newCount++;
        }

        return new ImportRowValidationResult(
            total: count($parsedRows),
            newCount: $newCount,
            existingCount: $existingCount,
            invalidCount: $invalidCount,
            issues: $issues,
        );
    }

    /**
     * @return array<string, true>
     */
    private function loadProductEans(): array
    {
        $eans = [];

        Product::query()
            ->select(['ean_1', 'ean_2'])
            ->where(function ($query): void {
                $query->whereNotNull('ean_1')
                    ->orWhereNotNull('ean_2');
            })
            ->each(function (Product $product) use (&$eans): void {
                foreach ([$product->ean_1, $product->ean_2] as $ean) {
                    $normalizedEan = $this->normalizeEan($ean);

                    if ($normalizedEan !== null) {
                        $eans[$normalizedEan] = true;
                    }
                }
            });

        return $eans;
    }
}
