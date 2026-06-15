<?php

use App\Support\RmaImport\Universal\UniversalImportParser;
use Tests\TestCase;

uses(TestCase::class);

it('reads universal sheet rows with merged metadata', function (): void {
    $fixture = base_path('tests/fixtures/rma/autovision-vanden-borre.xlsx');

    expect($fixture)->toBeReadableFile();

    $parser = new UniversalImportParser;

    expect($parser->supports($fixture, 'xlsx'))->toBeTrue();

    $rows = $parser->parse($fixture, 'xlsx');

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['uid'])->toBe('77222')
        ->and($rows[0]['notes'])->toContain('Vanden Borre N.V.')
        ->and($rows[1]['uid'])->toBe('77223')
        ->and($rows[1]['ean'])->toBe('8715465017075');
});
