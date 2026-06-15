<?php

use App\Enums\ProductUnit;
use App\Models\Product;
use App\Services\Import\ImportRowProductResolver;
use Tests\TestCase;

uses(TestCase::class);

it('resolves products by normalized ean', function (): void {
    $product = Product::query()->create([
        'uid' => 'RESOLVER-1',
        'name' => 'Ninebot Kickscooter',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $resolver = app(ImportRowProductResolver::class);

    expect($resolver->findByEan('846885011362')?->is($product))->toBeTrue()
        ->and($resolver->findByEan('0846885011362')?->is($product))->toBeTrue()
        ->and($resolver->findByEan('0000000000000'))->toBeNull();
});

it('resolves products by secondary ean', function (): void {
    $product = Product::query()->create([
        'uid' => 'RESOLVER-2',
        'name' => 'Reserve EAN product',
        'unit' => ProductUnit::Pieces,
        'ean_2' => '4012345678901',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $resolver = app(ImportRowProductResolver::class);

    expect($resolver->findByEan('4012345678901')?->is($product))->toBeTrue();
});
