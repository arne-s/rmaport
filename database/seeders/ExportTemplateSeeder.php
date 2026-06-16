<?php

namespace Database\Seeders;

use App\Models\ExportTemplate;
use App\Support\RmaExport\ConsumerReturnsShipment\ConsumerReturnsShipmentExportGenerator;
use App\Support\RmaExport\MediaMarkt\MediaMarktExportGenerator;
use App\Support\RmaExport\Universal\UniversalExportGenerator;
use Illuminate\Database\Seeder;

class ExportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        ExportTemplate::query()->updateOrCreate(
            ['class' => MediaMarktExportGenerator::class],
            [
                'name' => 'MediaMarkt',
                'filename' => 'mediamarkt-export.xlsx',
                'description' => 'MediaMarkt export met RMA-nummers',
            ],
        );

        ExportTemplate::query()->updateOrCreate(
            ['class' => ConsumerReturnsShipmentExportGenerator::class],
            [
                'name' => 'bol.com zending',
                'filename' => 'bol-export.xlsx',
                'description' => 'Consumer returns zending export (bol.com)',
            ],
        );

        ExportTemplate::query()->updateOrCreate(
            ['class' => UniversalExportGenerator::class],
            [
                'name' => 'Universeel',
                'filename' => 'universal-export.xlsx',
                'description' => 'Autovision bulk RMA export',
            ],
        );
    }
}
