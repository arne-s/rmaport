<?php

namespace App\Support\RmaImport;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

final class SpreadsheetTableWriter
{
    /**
     * @param  list<list<string|null>>  $rows
     */
    public function writeAllRows(string $path, array $rows): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                fn (?string $value): string => $value ?? '',
                $row,
            )));
        }

        $writer->close();
    }
}
