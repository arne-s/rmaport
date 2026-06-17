<?php

namespace Database\Seeders;

use App\Models\ExportTemplate;
use App\Support\RmaExport\ReturnUniversal\ReturnUniversalExportGenerator;
use Illuminate\Database\Seeder;

class ExportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        ExportTemplate::query()->updateOrCreate(
            ['class' => ReturnUniversalExportGenerator::class],
            [
                'name' => 'Sheet retour',
                'filename' => 'return_universal.xlsx',
                'description' => 'Universele sheet retour export met RMA-nummers en opmerkingen',
            ],
        );
    }
}
