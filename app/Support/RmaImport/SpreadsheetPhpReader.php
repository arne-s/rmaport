<?php

namespace App\Support\RmaImport;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class SpreadsheetPhpReader
{
    /**
     * @return list<array{row: int, values: list<string|null>}>
     */
    public function readRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $highestColumn = $sheet->getHighestDataColumn();
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rowIndex = $row->getRowIndex();
            $cellIterator = $row->getCellIterator('A', $highestColumn);
            $cellIterator->setIterateOnlyExistingCells(false);

            $values = [];

            foreach ($cellIterator as $cell) {
                $values[] = $this->valueToString($cell->getCalculatedValue());
            }

            $rows[] = [
                'row' => $rowIndex,
                'values' => $values,
            ];
        }

        return $rows;
    }

    private function valueToString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return trim((string) $value);
    }
}
