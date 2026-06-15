<?php

use App\Support\RmaImport\ConsumerReturns\ConsumerReturnsImportParser;
use Tests\TestCase;

uses(TestCase::class);

it('reads consumer returns excel export rows', function (): void {
    $fixture = base_path('tests/fixtures/rma/consumer-returns-inlees2.xlsx');

    expect($fixture)->toBeReadableFile();

    $rows = (new ConsumerReturnsImportParser)->parse($fixture, 'xlsx');

    expect($rows)->toHaveCount(29)
        ->and($rows[0]['uid'])->toBe('143526279')
        ->and($rows[0]['ean'])->toBe('0812887019569');
});
