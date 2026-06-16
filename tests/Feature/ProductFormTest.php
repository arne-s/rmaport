<?php

use App\Enums\ProductUnit;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('manage products', 'web');
    Permission::findOrCreate('access filament panel', 'web');
});

it('requires artikelnummer when creating a product', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Product',
        'last_name' => 'Tester',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage products']);

    $this->actingAs($user);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Test product',
        ])
        ->call('create')
        ->assertHasFormErrors(['uid' => 'required']);
});

it('enables artikelnummer on create and disables it on edit', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Product',
        'last_name' => 'Tester',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage products']);

    $product = Product::query()->create([
        'uid' => 'PROD-FORM-1',
        'name' => 'Existing product',
        'unit' => ProductUnit::Pieces,
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $this->actingAs($user);

    Livewire::test(CreateProduct::class)
        ->assertFormFieldIsEnabled('uid');

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormFieldIsDisabled('uid');
});
