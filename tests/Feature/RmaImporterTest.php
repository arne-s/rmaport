<?php

use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use App\Filament\Imports\RmaImporter;
use App\Models\Rma;
use App\Models\User;
use App\Support\RmaImportFileReader;
use Filament\Actions\Imports\Models\Import;
use Spatie\Permission\Models\Permission;

it('imports media markt and consumer returns rows via upsert', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    $user = User::factory()->create();
    $import = Import::query()->create([
        'user_id' => $user->id,
        'file_name' => 'rmas.csv',
        'file_path' => 'imports/rmas.csv',
        'importer' => RmaImporter::class,
        'total_rows' => 2,
        'successful_rows' => 0,
    ]);

    $mediaMarktClean = [
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
    ];

    $importer = new RmaImporter($import, [], []);
    $importer($mediaMarktClean);

    $record = Rma::query()->where('uid', 'AD71912248')->first();

    expect($record)->not->toBeNull()
        ->and($record->brand)->toBe(ProductBrand::HouseOfMarley)
        ->and($record->status)->toBe(RmaStatus::Open)
        ->and($record->complaint)->toBe('Defect geluid')
        ->and($record->location_code)->toBe('2555');

    $consumerRow = [
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
    ];

    $importer = new RmaImporter($import, [], []);
    $importer($consumerRow);

    $consumerRecord = Rma::query()->where('uid', '143526279')->first();

    expect($consumerRecord)->not->toBeNull()
        ->and($consumerRecord->brand)->toBe(ProductBrand::Jlab)
        ->and($consumerRecord->return_reason)->toBe('Anders')
        ->and($consumerRecord->order_nr)->toBe('C000397X67');

    $importer = new RmaImporter($import, [], []);
    $importer(array_merge($consumerRow, [
        'CONSUMER COMMENT' => 'Bijgewerkt',
    ]));

    expect(Rma::query()->where('uid', '143526279')->count())->toBe(1)
        ->and(Rma::query()->where('uid', '143526279')->value('complaint'))->toBe('Bijgewerkt');
});

it('imports media markt excel export rows', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');

    expect($fixture)->toBeReadableFile();

    $rows = app(RmaImportFileReader::class)->read($fixture, 'xlsx');

    expect($rows)->toHaveCount(8)
        ->and($rows[0]['Opdrachtnummer'])->toBe('AD71912248')
        ->and($rows[0]['Vestigingcode'])->toBe('2555')
        ->and($rows[0]['Type'])->toBe('REVOLUTION SB BT TURNTABLE');

    $user = User::factory()->create();
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
        ->and(Rma::query()->where('uid', 'AD71912248')->value('location_name'))->toBe('Mediamarkt Apeldoorn')
        ->and(Rma::query()->where('uid', 'DSG71926342')->value('is_doa'))->toBeTrue();
});

it('imports consumer returns excel export rows', function (): void {
    Permission::findOrCreate('manage sales', 'web');

    $fixture = base_path('tests/fixtures/rma/consumer-returns-inlees2.xlsx');

    expect($fixture)->toBeReadableFile();

    $rows = app(RmaImportFileReader::class)->read($fixture, 'xlsx');

    expect($rows)->toHaveCount(29);

    $user = User::factory()->create();
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
        ->and(Rma::query()->where('uid', '143526279')->first()?->purchased_at?->toDateString())->toBe('2026-04-08')
        ->and(Rma::query()->count())->toBe(29);
});
