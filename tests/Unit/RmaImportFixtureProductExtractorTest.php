<?php

use App\Support\RmaImport\RmaImportFixtureProductExtractor;
use Tests\TestCase;

uses(TestCase::class);

it('extracts unique products with ean and name from rma import fixtures', function (): void {
    $products = (new RmaImportFixtureProductExtractor)->extract();

    expect($products)->not->toBeEmpty()
        ->and(collect($products)->pluck('ean')->unique()->count())->toBe(count($products));

    $turntable = collect($products)->firstWhere('ean', '0846885011362');

    expect($turntable)->not->toBeNull()
        ->and($turntable['name'])->toMatch('/revolution/i');
});

it('normalizes ean values to thirteen digits', function (): void {
    $extractor = new RmaImportFixtureProductExtractor;

    expect($extractor->normalizeEan('812887017404'))->toBe('0812887017404')
        ->and($extractor->normalizeEan('0846885011362'))->toBe('0846885011362')
        ->and($extractor->normalizeEan(null))->toBeNull();
});
