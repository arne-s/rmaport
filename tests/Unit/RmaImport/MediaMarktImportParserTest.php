<?php

use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Tests\TestCase;

uses(TestCase::class);

it('reads media markt csv rows with duplicate headers by column position', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rma-import-');
    $csv = <<<'CSV'
Opdrachtnummer;Referentie;Referentie;Vestiging;Vestiging;Vestiging-ID
AD71912248;78906;ignored-ref;Mediamarkt Apeldoorn;2555;520
CSV;

    file_put_contents($path, $csv);

    $rows = (new MediaMarktImportParser)->parse($path, 'csv');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uid'])->toBe('AD71912248')
        ->and($rows[0]['reference'])->toBe('78906')
        ->and($rows[0]['location_name'])->toBe('Mediamarkt Apeldoorn')
        ->and($rows[0]['location_code'])->toBe('2555')
        ->and($rows[0]['external_location_id'])->toBe('520');

    unlink($path);
});

it('reads media markt excel export rows', function (): void {
    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');

    expect($fixture)->toBeReadableFile();

    $rows = (new MediaMarktImportParser)->parse($fixture, 'xlsx');

    expect($rows)->toHaveCount(8)
        ->and($rows[0]['uid'])->toBe('AD71912248')
        ->and($rows[0]['location_code'])->toBe('2555')
        ->and($rows[0]['product_name'])->toBe('REVOLUTION SB BT TURNTABLE');
});
