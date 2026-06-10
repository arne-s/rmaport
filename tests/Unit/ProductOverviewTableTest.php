<?php

use App\Filament\Resources\ProductResource;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

uses(TestCase::class);

it('eager loads stock on the product overview query', function (): void {
    $query = ProductResource::getEloquentQuery();

    expect($query->getEagerLoads())->toHaveKey('stock');
});

it('configures the product overview table columns', function (): void {
    $livewire = Mockery::mock(HasTable::class);
    $table = ProductResource::table(Table::make($livewire));

    $columns = $table->getColumns();

    expect(array_keys($columns))->toBe([
        'uid',
        'brand',
        'name',
        'description2',
        'stock.available_stock',
    ]);

    expect($columns['uid']->getLabel())->toBe('Artikelnummer');
    expect($columns['brand']->getLabel())->toBe('Merk');
    expect($columns['name']->getLabel())->toBe('Omschrijving');
    expect($columns['description2']->getLabel())->toBe('Omschrijving2');
    expect($columns['stock.available_stock']->getLabel())->toBe('Beschikbare voorraad');
});

it('returns an eloquent query builder from getEloquentQuery', function (): void {
    expect(ProductResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
});
