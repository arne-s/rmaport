<?php

use App\Filament\Resources\RmaResource;
use App\Filament\Support\SalesAuthorization;
use Tests\TestCase;

uses(TestCase::class);

it('denies rma resource when user is not authenticated', function (): void {
    expect(RmaResource::canViewAny())->toBeFalse()
        ->and(SalesAuthorization::canManage())->toBeFalse();
});

it('includes retouren in topbar and mobile sidebar menus', function (): void {
    $topbar = file_get_contents(resource_path('views/vendor/filament-panels/livewire/topbar.blade.php'));
    $sidebar = file_get_contents(resource_path('views/vendor/filament-panels/livewire/sidebar.blade.php'));

    expect($topbar)
        ->toContain('Retouren')
        ->toContain('filament.app.resources.rmas.index')
        ->toContain('filament.app.resources.rmas.create')
        ->toContain('@can(\'manage sales\')');

    expect(file_get_contents(app_path('Filament/Resources/RmaResource.php')))
        ->toContain('filament.components.back-to-overview')
        ->toContain('filament.app.pages.dashboard');

    expect(file_get_contents(resource_path('scss/overrides/filament/admin.scss')))
        ->toContain('.fi-resource-rmas');

    expect($sidebar)
        ->toContain('Retouren')
        ->toContain('activeMenu === \'retouren\'')
        ->toContain('filament.app.resources.rmas.index');
});
