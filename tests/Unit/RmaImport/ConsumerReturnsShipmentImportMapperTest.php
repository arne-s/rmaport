<?php

use App\Enums\RmaStatus;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportMapper;
use Tests\TestCase;

uses(TestCase::class);

it('maps consumer returns shipment rows with bol metadata', function (): void {
    $mapper = new ConsumerReturnsShipmentImportMapper;

    $metadata = [
        'Reference number (bol.com)' => 'NCKI26077751',
        'Reference number (recipient)' => 'Automatisch aangemaakte order',
        'Shipment Reference' => 'SMT-1941121',
        'Shipment date' => '08-May-2026',
        'Company name' => 'Autovision Holding B.V.',
        'Address' => 'Burgemeester Engelbertsstraat 92',
        'Contact' => 'Autovision Holding B.V.',
        'Shipment Carrier' => 'DHL',
        'Track & Trace number' => 'JVGL06160816001129545183',
        'Quantity shipped' => '29',
    ];

    $mapped = $mapper->map($metadata, [
        'RETURN ID (RMA)' => '143526279',
        'DEFECT ID' => 'RID-25199702',
        'EAN' => '0812887019569',
        'GLOBAL ID' => '9300000033112570',
        'PRODUCT DESCRIPTION' => 'JLab Go Work Headset',
        'GRADED TYPE' => 'Unsalable',
        'QUANTITY' => '1',
        'SHOP ORDER ID' => 'C000397X67',
        'CUSTOMER ORDER ID' => 'C000397X67',
        'SHOP ORDER DATE' => '08-Apr-2026',
        'RETURN DATE' => '23-Apr-2026',
        'RETURN REASON' => 'Anders',
        'RETURN SUB REASON' => 'Anders, namelijk',
        'CONSUMER COMMENT' => 'Defect usb poort',
    ]);

    expect($mapped['uid'])->toBe('143526279')
        ->and($mapped['packing_slip_number'])->toBe('SMT-1941121')
        ->and($mapped['return_date'])->toBe('2026-04-23')
        ->and($mapped['status'])->toBe(RmaStatus::Open->value)
        ->and($mapped['notes'])->toContain('Bol.com referentie: NCKI26077751')
        ->and($mapped['notes'])->toContain('Autovision Holding B.V.')
        ->and($mapped['notes'])->toContain('Track & Trace: JVGL06160816001129545183');
});
