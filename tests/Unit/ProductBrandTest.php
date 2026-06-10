<?php

use App\Enums\ProductBrand;

it('provides labels for all brands', function (): void {
    foreach (ProductBrand::cases() as $case) {
        expect($case->getLabel())->not->toBeEmpty();
        expect(ProductBrand::labels())->toHaveKey($case->value);
    }
});

it('resolves import values case insensitively', function (): void {
    expect(ProductBrand::resolveImportValue('JLAB'))->toBe(ProductBrand::Jlab);
    expect(ProductBrand::resolveImportValue('House of Marley'))->toBe(ProductBrand::HouseOfMarley);
    expect(ProductBrand::resolveImportValue('Homedics'))->toBe(ProductBrand::Homedics);
});

it('supports legacy JL product brand value', function (): void {
    expect(ProductBrand::tryFrom('JL'))->toBe(ProductBrand::Jlab);
});

it('resolves brand from product description', function (): void {
    expect(ProductBrand::resolveFromProductDescription('JLab Go Work Headset'))->toBe(ProductBrand::Jlab);
    expect(ProductBrand::resolveFromProductDescription('House of Marley Positive Vibration'))->toBe(ProductBrand::HouseOfMarley);
    expect(ProductBrand::resolveFromProductDescription('Unknown Brand Speaker'))->toBeNull();
});
