<?php

use App\Enums\CustomerStatus;
use App\Enums\ProductUnit;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use App\Services\Import\ImportRowProductResolver;
use App\Services\Import\ImportRowValidator;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->seed(ImportTemplateSeeder::class);
});

it('marks rows as invalid when ean does not match a product and no fallback exists', function (): void {
    Product::query()->where('uid', ImportRowProductResolver::FALLBACK_ARTICLE_NUMBER)->delete();

    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $result = app(ImportRowValidator::class)->validate($template, $customer->id, [
        [
            'Referentie' => '78906',
            'EAN' => '1234567890123',
            'Opdrachtnummer' => 'AD71912248',
        ],
    ]);

    expect($result->summaryLabel())->toBe('1 rijen totaal, 0 nieuw, 0 bestaand, 1 ongeldig.')
        ->and($result->invalidIssues()[0]->reasonLabel())->toBe('EAN komt niet overeen met een product');
});

it('marks rows as new when ean does not match but fallback product exists', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    Product::query()->create([
        'uid' => ImportRowProductResolver::FALLBACK_ARTICLE_NUMBER,
        'name' => 'Onbekend product',
        'unit' => ProductUnit::Pieces,
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $result = app(ImportRowValidator::class)->validate($template, $customer->id, [
        [
            'Referentie' => '78906',
            'EAN' => '1234567890123',
            'Opdrachtnummer' => 'AD71912248',
        ],
    ]);

    expect($result->summaryLabel())->toBe('1 rijen totaal, 1 nieuw, 0 bestaand, 0 ongeldig.');
});

it('marks rows as new when ean matches a product and reference is unique', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    Product::query()->create([
        'uid' => 'IMP-TEST-1',
        'name' => 'Test product',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $result = app(ImportRowValidator::class)->validate($template, $customer->id, [
        [
            'Referentie' => '78906',
            'EAN' => '0846885011362',
            'Opdrachtnummer' => 'AD71912248',
        ],
    ]);

    expect($result->summaryLabel())->toBe('1 rijen totaal, 1 nieuw, 0 bestaand, 0 ongeldig.');
});

it('marks duplicate references in the import file as invalid', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    Product::query()->create([
        'uid' => 'IMP-TEST-2',
        'name' => 'Test product',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $result = app(ImportRowValidator::class)->validate($template, $customer->id, [
        [
            'Referentie' => '78906',
            'EAN' => '0846885011362',
            'Opdrachtnummer' => 'AD71912248',
        ],
        [
            'Referentie' => '78906',
            'EAN' => '0846885011362',
            'Opdrachtnummer' => 'AD71912249',
        ],
    ]);

    expect($result->summaryLabel())->toBe('2 rijen totaal, 1 nieuw, 0 bestaand, 1 ongeldig.')
        ->and($result->invalidIssues()[0]->reasonLabel())->toBe('Dubbele referentie in importbestand');
});

it('marks existing customer and reference combinations as bestaand', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    Product::query()->create([
        'uid' => 'IMP-TEST-3',
        'name' => 'Test product',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $source = Source::query()->create([
        'name' => 'MediaMarkt',
        'import_template_id' => $template->id,
        'customer_id' => $customer->id,
    ]);

    $batch = ImportBatch::query()->create([
        'user_id' => User::query()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'first_name' => 'Import',
            'last_name' => 'Tester',
        ])->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => '78906',
        'ean_nr' => '0846885011362',
    ]);

    $result = app(ImportRowValidator::class)->validate($template, $customer->id, [
        [
            'Referentie' => '78906',
            'EAN' => '0846885011362',
            'Opdrachtnummer' => 'AD71912248',
        ],
    ]);

    expect($result->summaryLabel())->toBe('1 rijen totaal, 0 nieuw, 1 bestaand, 0 ongeldig.');
});

it('marks rows as invalid when customer does not exist', function (): void {
    Product::query()->create([
        'uid' => 'IMP-TEST-4',
        'name' => 'Test product',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $result = app(ImportRowValidator::class)->validate($template, 999999, [
        [
            'Referentie' => '78906',
            'EAN' => '0846885011362',
            'Opdrachtnummer' => 'AD71912248',
        ],
    ]);

    expect($result->invalidIssues()[0]->reasonLabel())->toBe('Klant bestaat niet');
});
