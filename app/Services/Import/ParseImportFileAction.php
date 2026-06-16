<?php

namespace App\Services\Import;

use App\Models\Customer;
use App\Models\ImportTemplate;
use App\Support\Import\ImportParseResult;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportMapper;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportMapper;
use App\Support\RmaImport\SpreadsheetTableReader;
use App\Support\RmaImport\Universal\UniversalImportMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ParseImportFileAction
{
    use MapsRmaImportRows;

    public function __construct(
        private readonly SpreadsheetTableReader $reader = new SpreadsheetTableReader,
        private readonly MediaMarktImportMapper $mediaMarktMapper = new MediaMarktImportMapper,
        private readonly ConsumerReturnsShipmentImportMapper $bolMapper = new ConsumerReturnsShipmentImportMapper,
        private readonly UniversalImportMapper $universalMapper = new UniversalImportMapper,
    ) {}

    public function __invoke(string $path, string $extension, ?ImportTemplate $template = null): ImportParseResult
    {
        $extension = strtolower($extension);

        if ($template === null) {
            $template = $this->detectTemplate($path, $extension);
        }

        [$metadata, $rows] = $this->extractRows($path, $extension, $template);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'Het bestand bevat geen importeerbare rijen.',
            ]);
        }

        $detectedCustomerId = $this->detectCustomerId($template, $metadata);

        return new ImportParseResult(
            template: $template,
            metadata: $metadata,
            rows: $rows,
            detectedCustomerId: $detectedCustomerId,
            reference: $this->extractBatchReference($template, $metadata),
            trackTraceNr: $this->nullableString($metadata['Track & Trace number'] ?? null),
            importDate: $this->extractBatchImportDate($template, $metadata),
            shipmentDate: $this->extractBatchShipmentDate($template, $metadata),
            shipmentReference: $this->extractBatchShipmentReference($template, $metadata),
        );
    }

    private function detectTemplate(string $path, string $extension): ImportTemplate
    {
        $templates = ImportTemplate::query()->with('source.customer')->get();

        foreach ($templates as $candidate) {
            $parser = app($candidate->class);

            if (method_exists($parser, 'supports') && $parser->supports($path, $extension)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages([
            'file' => 'Het bestandsformaat kon niet worden herkend. Kies een importtemplate of controleer het bestand.',
        ]);
    }

    /**
     * @return array{0: array<string, string|null>, 1: list<array<string, string|null>>}
     */
    private function extractRows(string $path, string $extension, ImportTemplate $template): array
    {
        if (Str::contains($template->class, 'MediaMarktImportParser')) {
            $table = $this->reader->readFlatTable($path, $extension);
            $headers = $this->mediaMarktMapper->normalizeHeaders($table['headers']);
            $rows = [];

            foreach ($table['rows'] as $values) {
                $rows[] = $this->reader->combineHeadersWithValues($headers, $values);
            }

            return [[], $rows];
        }

        if (Str::contains($template->class, 'ConsumerReturnsShipmentImportParser')) {
            $sections = $this->bolMapper->extractSections($this->reader->readAllRows($path));
            $rows = [];

            foreach ($sections['dataRows'] as $values) {
                $row = $this->reader->combineHeadersWithValues($sections['headers'], $values);
                if ($this->rowHasValues($row)) {
                    $rows[] = $row;
                }
            }

            return [$sections['metadata'], $rows];
        }

        if (Str::contains($template->class, 'UniversalImportParser')) {
            $sections = $this->universalMapper->extractSections($this->reader->readAllRows($path));
            $rows = [];

            foreach ($sections['dataRows'] as $values) {
                $row = $this->reader->combineHeadersWithValues($sections['headers'], $values);
                if ($this->rowHasValues($row)) {
                    $rows[] = $row;
                }
            }

            return [$sections['metadata'], $rows];
        }

        throw ValidationException::withMessages([
            'template' => 'Onbekend importtemplate.',
        ]);
    }

    /**
     * @param  array<string, string|null>  $metadata
     */
    private function detectCustomerId(ImportTemplate $template, array $metadata): ?int
    {
        $template->loadMissing('source.customer');

        if ($template->source?->customer_id !== null) {
            return $template->source->customer_id;
        }

        $debtorNumber = $this->nullableString($metadata['Klantnummer Autovision'] ?? null);

        if ($debtorNumber === null) {
            return null;
        }

        return Customer::query()
            ->where('debtor_number', $debtorNumber)
            ->value('id');
    }

    /**
     * @param  array<string, string|null>  $metadata
     */
    private function extractBatchReference(ImportTemplate $template, array $metadata): ?string
    {
        if (Str::contains($template->class, 'ConsumerReturnsShipmentImportParser')) {
            return $this->nullableString($metadata['Reference number (bol.com)'] ?? null);
        }

        if (Str::contains($template->class, 'UniversalImportParser')) {
            return $this->nullableString($metadata['Zending referentie'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $metadata
     */
    private function extractBatchImportDate(ImportTemplate $template, array $metadata): ?string
    {
        if (Str::contains($template->class, 'UniversalImportParser')) {
            return $this->parseDate($this->nullableString($metadata['Datum'] ?? null), 'Y-m-d')
                ?? $this->parseDate($this->nullableString($metadata['Datum'] ?? null), 'd.m.Y');
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $metadata
     */
    private function extractBatchShipmentDate(ImportTemplate $template, array $metadata): ?string
    {
        if (Str::contains($template->class, 'ConsumerReturnsShipmentImportParser')) {
            return $this->parseDate($this->nullableString($metadata['Shipment date'] ?? null), 'd-M-Y');
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $metadata
     */
    private function extractBatchShipmentReference(ImportTemplate $template, array $metadata): ?string
    {
        if (Str::contains($template->class, 'ConsumerReturnsShipmentImportParser')) {
            return $this->nullableString($metadata['Shipment Reference'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function rowHasValues(array $row): bool
    {
        foreach ($row as $value) {
            if (filled($value)) {
                return true;
            }
        }

        return false;
    }
}
