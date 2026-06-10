<?php

use App\Enums\ProductBattery;
use App\Models\Product;

it('maps search_text accessor to search_code column', function (): void {
    $product = new Product();
    $product->search_code = 'existing-code';

    expect($product->search_text)->toBe('existing-code');

    $product->search_text = 'new-code';

    expect($product->search_code)->toBe('new-code');
});

it('includes supplier fields in fillable attributes', function (): void {
    $product = new Product();

    foreach (['supplier_id', 'supplier_product_uid', 'supplier_product_name'] as $field) {
        expect($product->isFillable($field))->toBeTrue();
    }
});

it('casts battery to ProductBattery enum', function (): void {
    $product = new Product([
        'battery' => ProductBattery::Aa->value,
    ]);

    expect($product->battery)->toBe(ProductBattery::Aa);
});

it('includes new detail fields in fillable attributes', function (): void {
    $product = new Product();

    foreach ([
        'is_eol',
        'description2',
        'search_code',
        'brand',
        'sub_group',
        'manufacturer',
        'stock_location',
        'mediamarkt_nr_nl',
        'mediamarkt_nr_bnl',
        'ean_1',
        'ean_2',
        'dl_code',
        'hs_code',
        'krefel_nr',
        'bol_nr',
        'coolblue_nr',
        'battery',
        'pcb',
    ] as $field) {
        expect($product->isFillable($field))->toBeTrue();
    }
});
