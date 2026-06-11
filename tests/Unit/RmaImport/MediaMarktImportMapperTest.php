<?php

use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportMapper;
use App\Support\RmaImport\SpreadsheetTableReader;

it('uses semicolon delimiter', function (): void {
    expect(SpreadsheetTableReader::DELIMITER)->toBe(';');
});

it('detects media markt headers', function (): void {
    $mapper = new MediaMarktImportMapper;

    expect($mapper->supportsHeaders(['Opdrachtnummer', 'Referentie', 'EAN']))->toBeTrue();
});

it('normalizes duplicate media markt headers by position', function (): void {
    $mapper = new MediaMarktImportMapper;

    expect($mapper->normalizeHeaders([
        'Referentie',
        'Referentie',
        'Vestiging',
        'Vestiging',
        'Vestiging',
    ]))->toBe(['Referentie', '_skip', 'Vestiging', 'Vestigingcode', '_skip']);
});

it('maps media markt rows and falls back uid to order number', function (): void {
    $mapped = (new MediaMarktImportMapper)->map([
        'Opdrachtnummer' => 'AD71912248',
        'Referentie' => '78906',
        'EAN' => '0846885011362',
        'Artikelnummer' => '1873946',
        'Merk' => 'JLAB',
        'Artikelgroep' => 'HIFI PLATENSPELER',
        'Serienummer' => 'UNKNOWN',
        'Taal' => 'Nederlands',
        'Aankoopdatum' => '2025-10-06',
        'Klachtomschrijving' => "Hoofdproduct\n\nOpmerkingen: defect",
        'RMA-nummer' => '',
        'Type' => 'REVOLUTION SB BT TURNTABLE',
        'Vestiging' => 'Mediamarkt Apeldoorn',
        'Barcode' => "'000719122482",
        'Vestigingcode' => '2555',
        'Refurbish' => '0',
        'DOA' => '1',
        'Gefactureerd' => '0',
        'Staat van product' => '',
        'Accessoires' => 'Platenspeler',
        'Vestiging-ID' => '520',
    ]);

    expect($mapped['uid'])->toBe('AD71912248')
        ->and($mapped['order_nr'])->toBe('AD71912248')
        ->and($mapped['brand'])->toBe(ProductBrand::Jlab->value)
        ->and($mapped['is_doa'])->toBeTrue()
        ->and($mapped['barcode'])->toBe('000719122482')
        ->and($mapped['status'])->toBe(RmaStatus::Open->value);
});
