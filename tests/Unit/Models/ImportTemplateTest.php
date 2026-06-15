<?php

use App\Models\ImportTemplate;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportParser;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use App\Support\RmaImport\Universal\UniversalImportParser;

it('identifies universal import templates', function (): void {
    $template = new ImportTemplate(['class' => UniversalImportParser::class]);

    expect($template->isUniversal())->toBeTrue();
});

it('identifies non-universal import templates', function (string $class): void {
    $template = new ImportTemplate(['class' => $class]);

    expect($template->isUniversal())->toBeFalse();
})->with([
    MediaMarktImportParser::class,
    ConsumerReturnsShipmentImportParser::class,
]);
