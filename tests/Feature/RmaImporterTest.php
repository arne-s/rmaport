<?php

use App\Enums\ProductUnit;
use App\Enums\RmaStatus;
use App\Filament\Imports\RmaImporter;
use App\Models\Product;
use App\Models\Rma;
use App\Models\User;
use App\Support\RmaImport\ConsumerReturns\ConsumerReturnsImportMapper;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportMapper;
use App\Support\RmaImport\RmaImportReader;
use Filament\Actions\Imports\Models\Import;
use Spatie\Permission\Models\Permission;

function createImportUser(): User
{
    return User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'User',
    ]);
}

it('imports media markt and consumer returns rows via upsert', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    Product::query()->create([
        'uid' => 'IMP-MM-1',
        'name' => 'MediaMarkt product',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    Product::query()->create([
        'uid' => 'IMP-CR-1',
        'name' => 'Consumer returns product',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0812887019569',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $user = createImportUser();
    $import = Import::query()->create([
        'user_id' => $user->id,
        'file_name' => 'rmas.csv',
        'file_path' => 'imports/rmas.csv',
        'importer' => RmaImporter::class,
        'total_rows' => 2,
        'successful_rows' => 0,
    ]);

    $mediaMarktRow = (new MediaMarktImportMapper)->map([
        'Opdrachtnummer' => 'AD71912248',
        'Referentie' => '78906',
        'EAN' => '0846885011362',
        'Artikelnummer' => '1873946',
        'Merk' => 'House of Marley',
        'Artikelgroep' => 'HIFI PLATENSPELER',
        'Serienummer' => 'UNKNOWN',
        'Taal' => 'Nederlands',
        'Aankoopdatum' => '2025-10-06',
        'Klachtomschrijving' => 'Defect geluid',
        'RMA-nummer' => '',
        'Type' => 'REVOLUTION SB BT TURNTABLE',
        'Vestiging' => 'Mediamarkt Apeldoorn',
        'Barcode' => "'000719122482",
        'Vestigingcode' => '2555',
        'Refurbish' => '0',
        'DOA' => '0',
        'Gefactureerd' => '0',
        'Staat van product' => '',
        'Accessoires' => 'Platenspeler',
        'Vestiging-ID' => '520',
    ]);

    $importer = new RmaImporter($import, [], []);
    $importer($mediaMarktRow);

    $record = Rma::query()->where('uid', 'AD71912248')->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(RmaStatus::Open)
        ->and($record->complaint)->toBe('Defect geluid')
        ->and($record->product_id)->not->toBeNull();

    $consumerRow = (new ConsumerReturnsImportMapper)->map([
        'QUANTITY' => '1',
        'CUSTOMER ORDER ID' => 'C000397X67',
        'DEFECT ID' => 'RID-25199702',
        'EAN' => '0812887019569',
        'GLOBAL ID' => '9300000033112570',
        'RETURN ID (RMA)' => '143526279',
        'PRODUCT DESCRIPTION' => 'JLab Go Work Headset met Microfoon',
        'GRADED TYPE' => 'Unsalable',
        'SERIAL NUMBER' => '',
        'IMEI' => '',
        'SHOP ORDER ID' => 'C000397X67',
        'SHOP ORDER DATE' => '08-Apr-2026',
        'RETURN DATE' => '23-Apr-2026',
        'RETURN REASON' => 'Anders',
        'RETURN SUB REASON' => 'Anders, namelijk',
        'CONSUMER COMMENT' => 'Past niet in laptop',
    ]);

    $importer = new RmaImporter($import, [], []);
    $importer($consumerRow);

    $consumerRecord = Rma::query()->where('uid', '143526279')->first();

    expect($consumerRecord)->not->toBeNull()
        ->and($consumerRecord->return_reason)->toBe('Anders')
        ->and($consumerRecord->product_id)->not->toBeNull();

    $importer = new RmaImporter($import, [], []);
    $importer(array_merge($consumerRow, [
        'complaint' => 'Bijgewerkt',
    ]));

    expect(Rma::query()->where('uid', '143526279')->count())->toBe(1)
        ->and(Rma::query()->where('uid', '143526279')->value('complaint'))->toBe('Bijgewerkt');
});

it('imports media markt excel export rows', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $rows = app(RmaImportReader::class)->read($fixture, 'xlsx');

    expect($rows)->toHaveCount(8);

    $user = createImportUser();
    $import = Import::query()->create([
        'user_id' => $user->id,
        'file_name' => 'media-markt-export.xlsx',
        'file_path' => $fixture,
        'importer' => RmaImporter::class,
        'total_rows' => count($rows),
        'successful_rows' => 0,
    ]);

    foreach ($rows as $row) {
        (new RmaImporter($import, [], []))($row);
    }

    expect(Rma::query()->where('uid', 'AD71912248')->exists())->toBeTrue()
        ->and(Rma::query()->where('uid', 'GR71914195')->exists())->toBeTrue()
        ->and(Rma::query()->where('uid', 'AD71912248')->value('complaint'))->not->toBeNull();
});

it('imports consumer returns excel export rows', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    $fixture = base_path('tests/fixtures/rma/consumer-returns-inlees2.xlsx');
    $rows = app(RmaImportReader::class)->read($fixture, 'xlsx');

    expect($rows)->toHaveCount(29);

    $user = createImportUser();
    $import = Import::query()->create([
        'user_id' => $user->id,
        'file_name' => 'consumer-returns-inlees2.xlsx',
        'file_path' => $fixture,
        'importer' => RmaImporter::class,
        'total_rows' => count($rows),
        'successful_rows' => 0,
    ]);

    foreach ($rows as $row) {
        (new RmaImporter($import, [], []))($row);
    }

    expect(Rma::query()->where('uid', '143526279')->exists())->toBeTrue()
        ->and(Rma::query()->where('uid', '143526279')->value('return_reason'))->toBe('Anders')
        ->and(Rma::query()->count())->toBe(29);
});

it('imports universal excel export rows', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    $fixture = base_path('tests/fixtures/rma/autovision-vanden-borre.xlsx');
    $rows = app(RmaImportReader::class)->read($fixture, 'xlsx');

    expect($rows)->toHaveCount(4);

    $user = createImportUser();
    $import = Import::query()->create([
        'user_id' => $user->id,
        'file_name' => 'autovision-vanden-borre.xlsx',
        'file_path' => $fixture,
        'importer' => RmaImporter::class,
        'total_rows' => count($rows),
        'successful_rows' => 0,
    ]);

    foreach ($rows as $row) {
        (new RmaImporter($import, [], []))($row);
    }

    expect(Rma::query()->where('uid', '77222')->exists())->toBeTrue()
        ->and(Rma::query()->where('uid', '77222')->value('notes'))->toContain('Vanden Borre N.V.')
        ->and(Rma::query()->count())->toBe(4);
});
