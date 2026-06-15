<?php

use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use App\Support\RmaImport\Universal\UniversalImportMapper;
use Tests\TestCase;

uses(TestCase::class);

it('maps universal rows with metadata and homar brand alias', function (): void {
    $mapper = new UniversalImportMapper;

    $metadata = [
        'Zending referentie' => '760647648',
        'Datum' => '2026-02-05',
        'Klantnummer Autovision' => '19551',
        'Bedrijfsnaam' => 'Vanden Borre N.V.',
        'Adres' => 'Slesbroekstraat',
        'Huisnummer' => '101 Kaai 35-37',
        'Postcode' => '1600',
        'Plaats' => 'Sint Pieters Leeuw',
        'Land' => 'Belgie',
        'Telefoon' => '02-2561514',
        'Email' => 'AUDIO@Vandenborre.be',
    ];

    $mapped = $mapper->map($metadata, [
        'UW RMA Referentie' => '64751014',
        'EAN NUMMER' => '8715465017075',
        'Merk' => 'HOMAR',
        'Artikel nummer Autovision' => '',
        'Artikel Omschrijving' => 'Platine house of Marley',
        'Serienummer' => '',
        'Aankoopdatum' => '28.01.26',
        'Klachtomschrijving' => 'APPAREIL DOA',
        'RMA NUMMER AUTOVISION ' => '77223',
    ]);

    expect($mapped['uid'])->toBe('77223')
        ->and($mapped['reference'])->toBe('64751014')
        ->and($mapped['brand'])->toBe(ProductBrand::HouseOfMarley->value)
        ->and($mapped['purchased_at'])->toBe('2026-01-28')
        ->and($mapped['location_name'])->toBe('Vanden Borre N.V.')
        ->and($mapped['external_location_id'])->toBe('19551')
        ->and($mapped['packing_slip_number'])->toBe('760647648')
        ->and($mapped['received_at'])->toBe('2026-02-05 00:00:00')
        ->and($mapped['status'])->toBe(RmaStatus::Open->value)
        ->and($mapped['notes'])->toContain('Slesbroekstraat');
});
