<?php

use App\Models\ExportTemplate;
use App\Models\ImportTemplate;
use App\Support\RmaExport\ReturnUniversal\ReturnUniversalExportGenerator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $exportTemplate = ExportTemplate::query()->updateOrCreate(
            ['class' => ReturnUniversalExportGenerator::class],
            [
                'name' => 'Sheet retour',
                'filename' => 'return_universal.xlsx',
                'description' => 'Universele sheet retour export met RMA-nummers en opmerkingen',
            ],
        );

        ImportTemplate::query()->update([
            'export_template_id' => $exportTemplate->id,
        ]);

        ExportTemplate::query()
            ->where('class', '!=', ReturnUniversalExportGenerator::class)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible: old per-import export generators are removed from the codebase.
    }
};
