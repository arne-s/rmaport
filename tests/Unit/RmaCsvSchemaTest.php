<?php

use App\Enums\RmaCsvFormat;
use App\Support\RmaCsvSchema;

it('uses semicolon delimiter', function (): void {
    expect(RmaCsvSchema::DELIMITER)->toBe(';');
});

it('detects media markt csv format', function (): void {
    $format = RmaCsvSchema::detectFormat(['Opdrachtnummer', 'Referentie', 'EAN']);

    expect($format)->toBe(RmaCsvFormat::MediaMarkt);
});

it('detects consumer returns csv format', function (): void {
    $format = RmaCsvSchema::detectFormat(['QUANTITY', 'RETURN ID (RMA)', 'DEFECT ID']);

    expect($format)->toBe(RmaCsvFormat::ConsumerReturns);
});

it('normalizes duplicate media markt headers by position', function (): void {
    $headers = [
        'Referentie',
        'Referentie',
        'Vestiging',
        'Vestiging',
        'Vestiging',
    ];

    expect(RmaCsvSchema::normalizeHeaders($headers, RmaCsvFormat::MediaMarkt))
        ->toBe(['Referentie', '_skip', 'Vestiging', 'Vestigingcode', '_skip']);
});

it('combines normalized headers with row values', function (): void {
    $row = RmaCsvSchema::combineHeadersWithValues(
        ['Referentie', '_skip', 'EAN'],
        ['12345', 'ignored', '0812887019569'],
    );

    expect($row)->toBe([
        'Referentie' => '12345',
        'EAN' => '0812887019569',
    ]);
});
