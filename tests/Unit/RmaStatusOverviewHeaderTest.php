<?php

it('renders rma status overview header with status-overview class', function (): void {
    $basePath = dirname(__DIR__, 2);

    expect(file_get_contents($basePath.'/resources/views/filament/resources/rmas/pages/status-overview-header.blade.php'))
        ->toContain('fi-header-actions-ctn status-overview');

    expect(file_get_contents($basePath.'/app/Filament/Resources/RmaResource/Pages/ListRmasStatusPage.php'))
        ->toContain('status-overview-header');
});
