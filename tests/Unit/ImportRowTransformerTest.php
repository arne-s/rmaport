<?php

use App\Enums\ImportTemplateType;
use App\Models\ImportTemplate;
use App\Services\Import\ImportRowTransformer;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportParser;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use App\Support\RmaImport\Universal\UniversalImportParser;
use Tests\TestCase;

uses(TestCase::class);

it('transforms mediamarkt rows to import row attributes', function (): void {
    $template = new ImportTemplate([
        'name' => 'MediaMarkt',
        'class' => MediaMarktImportParser::class,
        'type' => ImportTemplateType::File,
    ]);
    $transformer = new ImportRowTransformer;

    $attributes = $transformer->transform($template, [
        'Opdrachtnummer' => 'AD71912248',
        'Referentie' => '78906',
        'EAN' => '0846885011362',
        'Merk' => 'House of Marley',
        'Artikelgroep' => 'HIFI PLATENSPELER',
        'Aankoopdatum' => '2025-10-06',
        'Klachtomschrijving' => 'Defect geluid',
        'DOA' => '1',
        'Accessoires' => 'Platenspeler',
        'Vestiging' => 'Mediamarkt Apeldoorn',
        'Vestiging-ID' => '520',
    ]);

    expect($attributes['assignment_nr'])->toBe('AD71912248')
        ->and($attributes['reference'])->toBe('78906')
        ->and($attributes['ean_nr'])->toBe('0846885011362')
        ->and($attributes['product_name'])->toBe('House of Marley HIFI PLATENSPELER')
        ->and($attributes['purchase_date'])->toBe('2025-10-06')
        ->and($attributes['return_reason'])->toBe('Defect geluid')
        ->and($attributes['is_doa'])->toBeTrue()
        ->and($attributes['accessories'])->toBe('Platenspeler')
        ->and($attributes['source_description'])->toBe('Mediamarkt Apeldoorn 520');
});

it('transforms bol rows with concatenated return reason', function (): void {
    $template = new ImportTemplate([
        'name' => 'bol.com zending',
        'class' => ConsumerReturnsShipmentImportParser::class,
        'type' => ImportTemplateType::File,
    ]);
    $transformer = new ImportRowTransformer;

    $attributes = $transformer->transform($template, [
        'CUSTOMER ORDER ID' => 'C000397X67',
        'DEFECT ID' => 'RID-25199702',
        'EAN' => '0812887019569',
        'PRODUCT DESCRIPTION' => 'JLab Go Work Headset met Microfoon',
        'SHOP ORDER DATE' => '08-Apr-2026',
        'RETURN DATE' => '23-Apr-2026',
        'RETURN REASON' => 'Anders',
        'RETURN SUB REASON' => 'Anders, namelijk',
        'CONSUMER COMMENT' => 'Past niet',
    ]);

    expect($attributes['customer_order_id'])->toBe('C000397X67')
        ->and($attributes['reference'])->toBe('RID-25199702')
        ->and($attributes['product_name'])->toBe('JLab Go Work Headset met Microfoon')
        ->and($attributes['return_date'])->toBe('2026-04-23')
        ->and($attributes['return_reason'])->toBe('Anders | Anders, namelijk | Past niet');
});

it('transforms universal rows', function (): void {
    $template = new ImportTemplate([
        'name' => 'Universeel',
        'class' => UniversalImportParser::class,
        'type' => ImportTemplateType::File,
    ]);
    $transformer = new ImportRowTransformer;

    $attributes = $transformer->transform($template, [
        'UW RMA Referentie' => '64751014',
        'EAN NUMMER' => '8715465017075',
        'Artikel Omschrijving' => 'Platine house of Marley',
        'Aankoopdatum' => '28.01.26',
        'Klachtomschrijving' => 'APPAREIL DOA',
    ]);

    expect($attributes['reference'])->toBe('64751014')
        ->and($attributes['ean_nr'])->toBe('8715465017075')
        ->and($attributes['product_name'])->toBe('Platine house of Marley')
        ->and($attributes['purchase_date'])->toBe('2026-01-28')
        ->and($attributes['return_reason'])->toBe('APPAREIL DOA');
});
