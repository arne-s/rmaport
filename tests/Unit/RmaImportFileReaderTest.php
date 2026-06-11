<?php

use App\Support\RmaImportFileReader;
use Tests\TestCase;

uses(TestCase::class);

it('reads media markt csv rows with duplicate headers by column position', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rma-import-');
    $csv = <<<'CSV'
Opdrachtnummer;Referentie;Referentie;Vestiging;Vestiging;Vestiging-ID
AD71912248;78906;ignored-ref;Mediamarkt Apeldoorn;2555;520
CSV;

    file_put_contents($path, $csv);

    $rows = app(RmaImportFileReader::class)->read($path, 'csv');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Opdrachtnummer'])->toBe('AD71912248')
        ->and($rows[0]['Referentie'])->toBe('78906')
        ->and($rows[0]['Vestiging'])->toBe('Mediamarkt Apeldoorn')
        ->and($rows[0]['Vestigingcode'])->toBe('2555')
        ->and($rows[0]['Vestiging-ID'])->toBe('520')
        ->and($rows[0])->not->toHaveKey('ignored-ref');

    unlink($path);
});

it('reads consumer returns excel export rows', function (): void {
    $fixture = base_path('tests/fixtures/rma/consumer-returns-inlees2.xlsx');

    expect($fixture)->toBeReadableFile();

    $rows = app(RmaImportFileReader::class)->read($fixture, 'xlsx');

    expect($rows)->toHaveCount(29)
        ->and($rows[0]['RETURN ID (RMA)'])->toBe('143526279')
        ->and($rows[0]['CUSTOMER ORDER ID'])->toBe('C000397X67')
        ->and($rows[0]['SHOP ORDER DATE'])->toBe('08-Apr-2026')
        ->and($rows[0]['PRODUCT DESCRIPTION'])->toContain('JLab Go Work Headset');
});
