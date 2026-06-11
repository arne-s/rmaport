<?php

namespace Database\Seeders;

use App\Models\FormImport;
use App\Models\FormImportConnection;
use App\Models\FormImportFieldMapping;
use Illuminate\Database\Seeder;

class FormImportDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $connection = FormImportConnection::query()->firstOrCreate(
            ['name' => 'Autovision website'],
            [
                'base_url' => config('app.url'),
                'username' => 'service',
                'api_token' => 'replace-me',
                'is_active' => false,
            ],
        );

        $this->seedForm($connection, 1, 'RMA Reparatie', [
            ['source_field_id' => '4', 'source_field_label' => 'Merk', 'rma_field' => 'brand'],
            ['source_field_id' => '13', 'source_field_label' => 'Probleemomschrijving', 'rma_field' => 'complaint'],
            ['source_field_id' => '46', 'source_field_label' => 'Product', 'rma_field' => 'product_name'],
            ['source_field_id' => '49', 'source_field_label' => 'Referentie', 'rma_field' => 'reference'],
            ['source_field_id' => '30', 'source_field_label' => 'Aankoopdatum', 'rma_field' => 'purchased_at'],
        ]);

        $this->seedForm($connection, 5, 'RMA Retour zending', []);

        $this->seedForm($connection, 18, 'RMA JLab vervanging', [
            ['source_field_id' => '60', 'source_field_label' => 'Product', 'rma_field' => 'product_name'],
            ['source_field_id' => '6', 'source_field_label' => 'Serienummer', 'rma_field' => 'serial_number'],
            ['source_field_id' => '61', 'source_field_label' => 'EAN', 'rma_field' => 'ean'],
        ]);
    }

    /**
     * @param  list<array{source_field_id: string, source_field_label?: string, rma_field?: string}>  $mappings
     */
    private function seedForm(FormImportConnection $connection, int $formId, string $title, array $mappings): void
    {
        $import = FormImport::query()->firstOrCreate(
            [
                'form_import_connection_id' => $connection->id,
                'source_form_id' => $formId,
            ],
            [
                'source_form_title' => $title,
                'is_active' => false,
            ],
        );

        if ($mappings === []) {
            return;
        }

        foreach ($mappings as $index => $mapping) {
            FormImportFieldMapping::query()->updateOrCreate(
                [
                    'form_import_id' => $import->id,
                    'source_field_id' => $mapping['source_field_id'],
                ],
                [
                    'source_field_label' => $mapping['source_field_label'] ?? null,
                    'rma_field' => $mapping['rma_field'] ?? null,
                    'append_to_notes' => $mapping['append_to_notes'] ?? false,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
