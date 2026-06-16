<?php

namespace App\Support\RmaImport;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class SpreadsheetTableEditor
{
    /**
     * Copy an existing spreadsheet and update only the specified cells, preserving formatting.
     *
     * @param  list<array{row: int, column: int, value: string}>  $cellUpdates  One-based row index, zero-based column index
     */
    public function copyAndUpdateCells(string $sourcePath, string $destinationPath, array $cellUpdates): void
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Bronbestand niet gevonden: {$sourcePath}");
        }

        if (! is_dir(dirname($destinationPath))) {
            mkdir(dirname($destinationPath), 0755, true);
        }

        copy($sourcePath, $destinationPath);

        if ($cellUpdates === []) {
            return;
        }

        $spreadsheet = IOFactory::load($destinationPath);
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($cellUpdates as $update) {
            $address = Coordinate::stringFromColumnIndex($update['column'] + 1).$update['row'];
            $sheet->setCellValue($address, $update['value']);
        }

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($destinationPath);
    }
}
