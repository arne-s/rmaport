<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Rma;
use App\Support\FormatDisplayDate;
use App\Support\RmaExport\SpreadsheetPlaceholderExporter;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

uses(TestCase::class);

it('fills return universal template headers and multiple data rows', function (): void {
    Carbon::setLocale('nl');

    $customer = Customer::make([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
    ]);

    $batch = ImportBatch::make([
        'reference' => 'REF-TEST',
        'import_date' => Carbon::parse('2026-06-01'),
    ]);

    $firstRow = new ImportRow([
        'reference' => 'R1',
        'ean_nr' => '123',
        'product_name' => 'Prod 1',
        'return_reason' => 'Kapot',
    ]);
    $firstRow->setAttribute('id', 1);
    $firstRow->setRelation('rma', Rma::make(['uid' => 'RMA-001']));
    $firstRow->setRelation('customer', $customer);

    $secondRow = new ImportRow([
        'reference' => 'R2',
        'ean_nr' => '456',
        'product_name' => 'Prod 2',
        'return_reason' => 'Stuk',
    ]);
    $secondRow->setAttribute('id', 2);
    $secondRow->setRelation('rma', Rma::make(['uid' => 'RMA-002']));
    $secondRow->setRelation('customer', $customer);

    $batch->setRelation('importRows', collect([$firstRow, $secondRow]));

    $path = sys_get_temp_dir().'/return-universal-unit-test-'.uniqid('', true).'.xlsx';

    try {
        (new SpreadsheetPlaceholderExporter)->export($batch, $path, [
            1 => 'Comment 1',
            '2' => 'Comment 2',
        ]);

        $sheet = IOFactory::load($path)->getActiveSheet();

        expect(trim((string) $sheet->getCell('B2')->getCalculatedValue()))->toBe('Test Klant')
            ->and(trim((string) $sheet->getCell('B3')->getCalculatedValue()))->toBe('REF-TEST')
            ->and(trim((string) $sheet->getCell('B4')->getCalculatedValue()))->toBe(FormatDisplayDate::longDate(Carbon::parse('2026-06-01')))
            ->and(trim((string) $sheet->getCell('E14')->getCalculatedValue()))->toBe('RMA-001')
            ->and(trim((string) $sheet->getCell('F14')->getCalculatedValue()))->toBe('Comment 1')
            ->and(trim((string) $sheet->getCell('A15')->getCalculatedValue()))->toBe('R2')
            ->and(trim((string) $sheet->getCell('E15')->getCalculatedValue()))->toBe('RMA-002')
            ->and(trim((string) $sheet->getCell('F15')->getCalculatedValue()))->toBe('Comment 2')
            ->and($sheet->getHighestDataColumn())->toBe('F');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
