<?php

use App\Support\RmaImport\SpreadsheetTableEditor;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

uses(TestCase::class);

it('copies a spreadsheet and updates only the specified cells', function (): void {
    $fixture = base_path('tests/fixtures/rma/consumer-returns-shipment.xlsx');
    $destination = storage_path('app/testing/export-format-test.xlsx');

    if (is_file($destination)) {
        unlink($destination);
    }

    $sourceStyles = IOFactory::load($fixture)->getActiveSheet()->getStyle('A1')->exportArray();

    (new SpreadsheetTableEditor)->copyAndUpdateCells($fixture, $destination, [
        ['row' => 22, 'column' => 6, 'value' => '00000099'],
    ]);

    expect($destination)->toBeReadableFile();

    $exportSheet = IOFactory::load($destination)->getActiveSheet();

    expect($exportSheet->getCell('G22')->getValue())->toBe('00000099')
        ->and($exportSheet->getStyle('A1')->exportArray())->toBe($sourceStyles);

    unlink($destination);
});
