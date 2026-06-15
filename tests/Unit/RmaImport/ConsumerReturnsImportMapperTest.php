<?php

use App\Support\RmaImport\ConsumerReturns\ConsumerReturnsImportMapper;

it('detects consumer returns headers', function (): void {
    $mapper = new ConsumerReturnsImportMapper;

    expect($mapper->supportsHeaders(['QUANTITY', 'RETURN ID (RMA)', 'DEFECT ID']))->toBeTrue();
});

it('maps consumer returns rows with parsed dates and inferred brand', function (): void {
    $mapped = (new ConsumerReturnsImportMapper)->map([
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
        ->and($mapped['ean'])->toBe('0812887019569')
        ->and($mapped['received_at'])->toBe('2026-04-23 00:00:00')
        ->and($mapped['return_reason'])->toBe('Anders')
        ->and($mapped['complaint'])->toBe('De usb C aansluiting past niet');
});
