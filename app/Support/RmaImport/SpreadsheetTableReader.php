<?php

namespace App\Support\RmaImport;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class SpreadsheetTableReader
{
    public const DELIMITER = ';';

    /**
     * @return array{headers: list<string>, rows: list<list<string|null>>}
     */
    public function readFlatTable(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'xlsx' => $this->readFlatTableFromSpreadsheet($path),
            'csv', 'txt' => $this->readFlatTableFromCsv($path),
            default => throw ValidationException::withMessages([
                'file' => 'Alleen CSV- of Excel-bestanden (.csv, .txt, .xlsx) worden ondersteund.',
            ]),
        };
    }

    /**
     * @return list<list<string|null>>
     */
    public function readAllRows(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var list<Cell> $cells */
                $cells = $row->getCells();
                $rows[] = array_map(
                    fn (Cell $cell): ?string => $this->cellToString($cell),
                    $cells,
                );
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string|null>>}
     */
    private function readFlatTableFromCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Het bestand kon niet worden gelezen.',
            ]);
        }

        $headerRow = fgetcsv($handle, 0, self::DELIMITER);

        if ($headerRow === false) {
            fclose($handle);

            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(
            fn (?string $header): string => trim((string) $header),
            $headerRow,
        );

        $rows = [];

        while (($values = fgetcsv($handle, 0, self::DELIMITER)) !== false) {
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $rows[] = array_map(
                fn (?string $value): ?string => $value === null ? null : trim($value),
                $values,
            );
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string|null>>}
     */
    private function readFlatTableFromSpreadsheet(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $headers = [];
        $rows = [];
        $isHeaderRead = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var list<Cell> $cells */
                $cells = $row->getCells();
                $values = array_map(
                    fn (Cell $cell): ?string => $this->cellToString($cell),
                    $cells,
                );

                if (! $isHeaderRead) {
                    $headers = array_map(
                        fn (?string $header): string => trim((string) $header),
                        $values,
                    );
                    $isHeaderRead = true;

                    continue;
                }

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                $rows[] = array_map(
                    fn (?string $value): ?string => $value === null ? null : trim($value),
                    $values,
                );
            }

            break;
        }

        $reader->close();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  list<string|null>  $values
     */
    public function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $values
     * @return array<string, string|null>
     */
    public function combineHeadersWithValues(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '_skip') {
                continue;
            }

            $row[$header] = $values[$index] ?? null;
        }

        return $row;
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
