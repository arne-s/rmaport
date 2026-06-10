<?php

use App\Filament\Resources\RmaResource;
use App\Filament\Resources\RmaResource\Pages\EditRma;

uses(Tests\TestCase::class);

it('configures edit page heading and breadcrumbs for retouren', function (): void {
    $editSource = file_get_contents(app_path('Filament/Resources/RmaResource/Pages/EditRma.php'));
    $resourceSource = file_get_contents(app_path('Filament/Resources/RmaResource.php'));

    expect($editSource)
        ->toContain("'Retouren'")
        ->toContain('getRmaHeadingUid')
        ->toContain("'RMA: '");

    expect($resourceSource)
        ->toContain('Retouren-overzicht')
        ->toContain("Tab::make('Algemeen')")
        ->toContain("Tab::make('Eigenschappen')");
});

it('builds edit form with company section layout', function (): void {
    $source = file_get_contents(app_path('Filament/Resources/RmaResource.php'));
    $createSource = file_get_contents(app_path('Filament/Resources/RmaResource/Pages/CreateRma.php'));

    expect($source)
        ->toContain('companySection-wrapper')
        ->toContain('beheer-bedrijfsgegevensSection')
        ->toContain('beheer-factuurgegevensSection')
        ->toContain('back-to-overview-with-heading')
        ->toContain('editForm')
        ->toContain("->where('is_draft', false)");

    expect($createSource)
        ->toContain('Rma::createDraft()')
        ->toContain("getUrl('edit'");

    expect(method_exists(RmaResource::class, 'editForm'))->toBeTrue()
        ->and(class_exists(EditRma::class))->toBeTrue();
});
