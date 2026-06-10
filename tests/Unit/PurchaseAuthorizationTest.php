<?php

use App\Filament\Support\PurchaseAuthorization;
use Tests\TestCase;

uses(TestCase::class);

it('denies supplier management when user is not authenticated', function (): void {
    expect(PurchaseAuthorization::canManage())->toBeFalse();
});

it('includes leveranciers in the topbar inkoop menu', function (): void {
    $contents = file_get_contents(resource_path('views/vendor/filament-panels/livewire/topbar.blade.php'));

    expect($contents)
        ->toContain('filament.app.resources.suppliers.index')
        ->toContain('Leveranciers')
        ->toContain('@can(\'manage purchases\')');
});
