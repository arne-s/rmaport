<?php

use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportParser;
use Tests\TestCase;

uses(TestCase::class);

it('reads consumer returns shipment sheet with bol metadata', function (): void {
    $fixture = base_path('tests/fixtures/rma/consumer-returns-shipment.xlsx');

    expect($fixture)->toBeReadableFile();

    $parser = new ConsumerReturnsShipmentImportParser;

    expect($parser->supports($fixture, 'xlsx'))->toBeTrue();

    $rows = $parser->parse($fixture, 'xlsx');

    expect($rows)->toHaveCount(29)
        ->and($rows[0]['uid'])->toBe('143526279')
        ->and($rows[0]['reference'])->toBe('C000397X67')
        ->and($rows[0]['packing_slip_number'])->toBe('SMT-1941121')
        ->and($rows[0]['location_name'])->toBe('Autovision Holding B.V.')
        ->and($rows[0]['brand'])->toBe('JL')
        ->and($rows[0]['notes'])->toContain('NCKI26077751')
        ->and($rows[0]['notes'])->toContain('DHL')
        ->and($rows[1]['uid'])->toBe('143743568');
});

it('does not match flat consumer returns sheets', function (): void {
    $fixture = base_path('tests/fixtures/rma/consumer-returns-inlees2.xlsx');
    $parser = new ConsumerReturnsShipmentImportParser;

    expect($parser->supports($fixture, 'xlsx'))->toBeFalse();
});
