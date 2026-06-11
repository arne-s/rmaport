<?php

use App\Filament\Forms\Components\TableReorderable;

it('uses the published filament tables index view', function (): void {
    $reflection = new ReflectionClass(TableReorderable::class);

    expect($reflection->getDefaultProperties()['view'] ?? null)->toBe('filament-tables::index');
});
