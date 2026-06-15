<?php

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Enums\ImportTemplateType;
use App\Models\Customer;
use App\Models\ImportTemplate;
use App\Models\Source;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportParser;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use App\Support\RmaImport\Universal\UniversalImportParser;
use Illuminate\Database\Seeder;

class ImportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $mediaMarktCustomer = Customer::query()->create([
            'status' => CustomerStatus::Active,
            'name' => 'MediaMarkt',
            'debtor_number' => 'MM-001',
        ]);

        $bolCustomer = Customer::query()->create([
            'status' => CustomerStatus::Active,
            'name' => 'bol.com',
            'debtor_number' => 'BOL-001',
        ]);

        $mediaMarktTemplate = ImportTemplate::query()->updateOrCreate(
            ['class' => MediaMarktImportParser::class],
            [
                'name' => 'MediaMarkt',
                'filename' => 'mediamarkt.xlsx',
                'type' => ImportTemplateType::File,
                'description' => 'MediaMarkt CSV/Excel export',
            ],
        );

        $bolTemplate = ImportTemplate::query()->updateOrCreate(
            ['class' => ConsumerReturnsShipmentImportParser::class],
            [
                'name' => 'bol.com zending',
                'filename' => 'bol.xlsx',
                'type' => ImportTemplateType::File,
                'description' => 'Consumer returns zending (bol.com)',
            ],
        );

        $universalTemplate = ImportTemplate::query()->updateOrCreate(
            ['class' => UniversalImportParser::class],
            [
                'name' => 'Universeel',
                'filename' => 'universal.xlsx',
                'type' => ImportTemplateType::File,
                'description' => 'Autovision bulk RMA aanmeldformulier',
                'source_id' => null,
            ],
        );

        $mediaMarktSource = Source::query()->updateOrCreate(
            ['import_template_id' => $mediaMarktTemplate->id, 'name' => 'MediaMarkt'],
            [
                'email' => null,
                'notes' => null,
                'customer_id' => $mediaMarktCustomer->id,
            ],
        );

        $bolSource = Source::query()->updateOrCreate(
            ['import_template_id' => $bolTemplate->id, 'name' => 'bol.com'],
            [
                'email' => null,
                'notes' => null,
                'customer_id' => $bolCustomer->id,
            ],
        );

        $mediaMarktTemplate->update(['source_id' => $mediaMarktSource->id]);
        $bolTemplate->update(['source_id' => $bolSource->id]);

        Source::query()->updateOrCreate(
            ['import_template_id' => $universalTemplate->id, 'name' => 'Universeel'],
            [
                'email' => null,
                'notes' => 'Dynamische bron via Klantnummer Autovision',
                'customer_id' => null,
            ],
        );
    }
}
