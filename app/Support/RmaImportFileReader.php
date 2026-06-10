<?php

namespace App\Support;

use App\Enums\RmaCsvFormat;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class RmaImportFileReader
{
    /**
     * @return list<array<string, string|null>>
     */
    public function read(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'xlsx' => $this->readSpreadsheet($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw ValidationException::withMessages([
                'file' => 'Alleen CSV- of Excel-bestanden (.csv, .txt, .xlsx) worden ondersteund.',
            ]),
        };
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Het bestand kon niet worden gelezen.',
            ]);
        }

        $headerRow = fgetcsv($handle, 0, RmaCsvSchema::DELIMITER);

        if ($headerRow === false) {
            fclose($handle);

            return [];
        }

        $rows = $this->rowsFromTable($headerRow, $handle);
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function readSpreadsheet(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $dataRows = [];
        $table = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var list<Cell> $cells */
                $cells = $row->getCells();
                $values = array_map(
                    fn (Cell $cell): ?string => $this->cellToString($cell),
                    $cells,
                );

                if ($table === null) {
                    $table = $this->startTable($values);

                    continue;
                }

                $normalizedRow = $this->appendTableRow($table, $values);

                if ($normalizedRow !== null) {
                    $dataRows[] = $normalizedRow;
                }
            }

            break;
        }

        $reader->close();

        return $dataRows;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return list<array<string, string|null>>
     */
    private function rowsFromTable(array $headerRow, mixed $handle): array
    {
        $table = $this->startTable($headerRow);
        $rows = [];

        while (($values = fgetcsv($handle, 0, RmaCsvSchema::DELIMITER)) !== false) {
            $normalizedRow = $this->appendTableRow($table, $values);

            if ($normalizedRow !== null) {
                $rows[] = $normalizedRow;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array{format: RmaCsvFormat, headers: list<string>}
     */
    private function startTable(array $headerRow): array
    {
        $headerRow = array_map(
            fn (?string $header): string => trim((string) $header),
            $headerRow,
        );

        $format = RmaCsvSchema::detectFormat($headerRow);

        return [
            'format' => $format,
            'headers' => RmaCsvSchema::normalizeHeaders($headerRow, $format),
        ];
    }

    /**
     * @param  array{format: RmaCsvFormat, headers: list<string>}  $table
     * @param  list<string|null>  $values
     * @return array<string, string|null>|null
     */
    private function appendTableRow(array $table, array $values): ?array
    {
        if ($this->isEmptyRow($values)) {
            return null;
        }

        return RmaCsvSchema::combineHeadersWithValues(
            $table['headers'],
            array_map(
                fn (?string $value): ?string => $value === null ? null : trim($value),
                $values,
            ),
        );
    }

    /**
     * @param  list<string|null>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cellToString(Cell $cell): ?string
    {
        $value = $cell->getValue();

        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            if (is_float($value) && floor($value) === $value) {
                return (string) (int) $value;
            }

            return (string) $value;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
