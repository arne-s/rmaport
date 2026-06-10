<?php

use App\Enums\CustomerType;
use App\Support\CustomerCsvSchema;

it('resolves csv import types for particulier and b2b', function () {
    expect(CustomerType::resolveCsvImportTypeValue('b2c'))->toBe('b2c')
        ->and(CustomerType::resolveCsvImportTypeValue('Particulier'))->toBe('b2c')
        ->and(CustomerType::resolveCsvImportTypeValue('b2b'))->toBe('b2b')
        ->and(CustomerType::resolveCsvImportTypeValue('B2B'))->toBe('b2b');
});

it('rejects deprecated and system customer types in csv import', function () {
    expect(CustomerType::resolveCsvImportTypeValue('dealer'))->toBeNull()
        ->and(CustomerType::resolveCsvImportTypeValue('Uniek Sporten'))->toBeNull()
        ->and(CustomerType::resolveCsvImportTypeValue('av'))->toBeNull()
        ->and(CustomerType::resolveCsvImportTypeValue('AV'))->toBeNull();
});

it('round-trips delivery address type labels for csv export and import', function () {
    expect(CustomerCsvSchema::formatDeliveryAddressTypeForExport('contact'))->toBe('factuuradres')
        ->and(CustomerCsvSchema::parseDeliveryAddressType('factuuradres'))->toBe('contact')
        ->and(CustomerCsvSchema::formatDeliveryAddressTypeForExport('custom'))->toBe('afwijkend')
        ->and(CustomerCsvSchema::parseDeliveryAddressType('afwijkend'))->toBe('custom');
});

it('exposes matching csv import and export customer type values', function () {
    expect(CustomerType::csvImportTypeValues())->toBe(['b2c', 'b2b']);
});
