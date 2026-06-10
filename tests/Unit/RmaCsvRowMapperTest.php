<?php

use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use App\Services\RmaCsvRowMapper;

it('maps media markt rows and falls back uid to order number', function (): void {
    $mapped = app(RmaCsvRowMapper::class)->mapMediaMarktRow([
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

it('maps consumer returns rows with parsed dates and inferred brand', function (): void {
    $mapped = app(RmaCsvRowMapper::class)->mapConsumerReturnsRow([
        'QUANTITY' => '1',
        'CUSTOMER ORDER ID' => 'C000397X67',
        'DEFECT ID' => 'RID-25199702',
        'EAN' => '0812887019569',
        'GLOBAL ID' => '9300000033112570',
        'RETURN ID (RMA)' => '143526279',
        'PRODUCT DESCRIPTION' => 'JLab Go Work Headset met Microfoon',
        'GRADED TYPE' => 'Unsalable',
        'SERIAL NUMBER' => '',
        'IMEI' => '',
        'SHOP ORDER ID' => 'C000397X67',
        'SHOP ORDER DATE' => '08-Apr-2026',
        'RETURN DATE' => '23-Apr-2026',
        'RETURN REASON' => 'Anders',
        'RETURN SUB REASON' => 'Anders, namelijk',
        'CONSUMER COMMENT' => 'De usb C aansluiting past niet',
    ]);

    expect($mapped['uid'])->toBe('143526279')
        ->and($mapped['reference'])->toBe('C000397X67')
        ->and($mapped['order_nr'])->toBe('C000397X67')
        ->and($mapped['brand'])->toBe(ProductBrand::Jlab->value)
        ->and($mapped['purchased_at'])->toBe('2026-04-08')
        ->and($mapped['received_at'])->toBe('2026-04-23 00:00:00')
        ->and($mapped['return_reason'])->toBe('Anders')
        ->and($mapped['complaint'])->toBe('De usb C aansluiting past niet');
});

it('resolves uid using fallback chain', function (): void {
    $mapper = app(RmaCsvRowMapper::class);

    expect($mapper->resolveUid(null, 'ORDER-1', 'REF-1', 'RID-1'))->toBe('ORDER-1')
        ->and($mapper->resolveUid(null, null, 'REF-1', 'RID-1'))->toBe('REF-1')
        ->and($mapper->resolveUid(null, null, null, 'RID-1'))->toBe('RID-1');
});
