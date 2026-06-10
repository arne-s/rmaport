<?php

use App\Enums\CustomerType;

it('exposes only particulier and b2b as visible types', function () {
    expect(CustomerType::AV->isVisible())->toBeFalse()
        ->and(CustomerType::B2C->isVisible())->toBeTrue()
        ->and(CustomerType::B2B->isVisible())->toBeTrue();
});

it('only offers particulier when creating a customer', function () {
    expect(CustomerType::visibleLabelsForCreate())->toBe([
        'b2c' => 'Particulier',
    ]);
});

it('orders customer table type filter with particulier and b2b', function () {
    expect(CustomerType::visibleLabelsInCustomerTableFilterOrder())->toBe([
        'b2c' => 'Particulier',
        'b2b' => 'B2B',
    ]);
});
