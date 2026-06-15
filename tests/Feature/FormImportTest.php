<?php

use App\Models\FormImport;
use App\Models\FormImportConnection;
use App\Models\FormImportFieldMapping;
use App\Models\FormImportEntryLog;
use App\Models\Rma;
use App\Support\FormImport\ConfigurableFormImportEntryMapper;
use App\Support\FormImport\FormImportFormSchemaNormalizer;
use App\Support\FormImport\ImportFormEntriesAction;
use Illuminate\Support\Facades\Http;

describe('FormImportApiClient', function (): void {
    it('requests the gf v2 forms endpoint under wp-json', function (): void {
        Http::fake([
            'https://forms.test/wp-json/gf/v2/forms' => Http::response([
                ['id' => 1, 'title' => 'Reparatie', 'is_active' => '1'],
            ]),
        ]);

        $connection = FormImportConnection::query()->make([
            'name' => 'Test',
            'base_url' => 'https://forms.test',
            'username' => 'ck_test',
            'api_token' => 'cs_test',
        ]);

        $forms = (new \App\Support\FormImport\FormImportApiClient)->listForms($connection);

        expect($forms)->toHaveCount(1)
            ->and($forms[0]['id'])->toBe(1);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://forms.test/wp-json/gf/v2/forms';
        });
    });

    it('requests entries with a json encoded search parameter', function (): void {
        Http::fake([
            'https://forms.test/wp-json/gf/v2/forms/1/entries*' => Http::response([
                'total_count' => 0,
                'entries' => [],
            ]),
        ]);

        $connection = FormImportConnection::query()->make([
            'name' => 'Test',
            'base_url' => 'https://forms.test',
            'username' => 'ck_test',
            'api_token' => 'cs_test',
        ]);

        (new \App\Support\FormImport\FormImportApiClient)->getEntries($connection, 1);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return str_contains($request->url(), '/forms/1/entries')
                && isset($query['search'])
                && is_string($query['search'])
                && json_decode($query['search'], true)['status'] === 'active';
        });
    });

    it('reports when gf rest api is not enabled on the wordpress site', function (): void {
        Http::fake([
            'https://forms.test/wp-json/*' => Http::response([
                'namespaces' => ['wp/v2', 'oembed/1.0'],
            ]),
        ]);

        $connection = FormImportConnection::query()->make([
            'name' => 'Test',
            'base_url' => 'https://forms.test',
            'username' => 'ck_test',
            'api_token' => 'cs_test',
        ]);

        (new \App\Support\FormImport\FormImportApiClient)->assertRestApiAvailable($connection);
    })->throws(RuntimeException::class, 'Gravity Forms REST API is niet ingeschakeld');
});

describe('FormImportFormSchemaNormalizer', function (): void {
    it('flattens composite fields into mappable sub-inputs', function (): void {
        $normalizer = new FormImportFormSchemaNormalizer;

        $fields = $normalizer->normalizeFields([
            'fields' => [
                [
                    'id' => 17,
                    'type' => 'address',
                    'label' => 'Adres',
                    'inputs' => [
                        ['id' => '17.1', 'label' => 'Straat'],
                        ['id' => '17.3', 'label' => 'Plaats'],
                    ],
                ],
                [
                    'id' => 13,
                    'type' => 'textarea',
                    'label' => 'Klacht',
                ],
            ],
        ]);

        expect($fields)->toHaveCount(3)
            ->and($fields[0]['id'])->toBe('17.1')
            ->and($fields[2]['id'])->toBe('13');
    });
});

describe('ConfigurableFormImportEntryMapper', function (): void {
    it('maps configured source fields to rma attributes', function (): void {
        $import = FormImport::query()->make([
            'source_form_id' => 1,
            'uid_fallback_prefix' => 'FI',
        ]);

        $import->setRelation('fieldMappings', collect([
            new FormImportFieldMapping([
                'source_field_id' => '13',
                'source_field_label' => 'Klacht',
                'rma_field' => 'complaint',
            ]),
            new FormImportFieldMapping([
                'source_field_id' => '21',
                'source_field_label' => 'E-mail',
                'rma_field' => 'notes',
            ]),
        ]));

        $result = (new ConfigurableFormImportEntryMapper)->map($import, [
            'id' => 99,
            'form_id' => 1,
            'date_created' => '2026-06-11 10:00:00',
            '13' => 'Scherm defect',
            '21' => 'jan@example.com',
        ]);

        expect($result['complaint'])->toBe('Scherm defect')
            ->and($result['notes'])->toBe('jan@example.com')
            ->and($result['uid'])->toBe('FI1-99')
            ->and($result['status'])->toBe('open');
    });

    it('maps fixed values to rma attributes', function (): void {
        $import = FormImport::query()->make([
            'source_form_id' => 1,
            'uid_fallback_prefix' => 'FI',
        ]);

        $import->setRelation('fieldMappings', collect([
            new FormImportFieldMapping([
                'fixed_value' => 'Autovision',
                'rma_field' => 'notes',
            ]),
        ]));

        $result = (new ConfigurableFormImportEntryMapper)->map($import, [
            'id' => 42,
            'form_id' => 1,
            'date_created' => '2026-06-11 10:00:00',
        ]);

        expect($result['notes'])->toBe('Autovision')
            ->and($result['uid'])->toBe('FI1-42');
    });
});

describe('ImportFormEntriesAction', function (): void {
    it('imports entries via the remote api and skips duplicates', function (): void {
        Http::fake([
            'https://forms.test/wp-json/gf/v2/forms/1/entries*' => Http::response([
                'total_count' => 1,
                'entries' => [[
                    'id' => '501',
                    'form_id' => '1',
                    'date_created' => '2026-06-11 12:00:00',
                    'source_url' => 'https://forms.test/reparatie',
                    '13' => 'Kapot scherm',
                ]],
            ]),
        ]);

        $connection = FormImportConnection::query()->create([
            'name' => 'Test',
            'base_url' => 'https://forms.test',
            'username' => 'api',
            'api_token' => 'secret',
            'is_active' => true,
        ]);

        $import = FormImport::query()->create([
            'form_import_connection_id' => $connection->id,
            'source_form_id' => 1,
            'source_form_title' => 'Reparatie',
            'is_active' => true,
        ]);

        FormImportFieldMapping::query()->create([
            'form_import_id' => $import->id,
            'source_field_id' => '13',
            'source_field_label' => 'Klacht',
            'rma_field' => 'complaint',
        ]);

        $action = app(ImportFormEntriesAction::class);

        $first = $action->sync($import->fresh(['connection', 'fieldMappings', 'state']), full: true);
        $second = $action->sync($import->fresh(['connection', 'fieldMappings', 'state']), full: true);

        expect($first)->toBe(['imported' => 1, 'skipped' => 0, 'failed' => 0])
            ->and($second)->toBe(['imported' => 0, 'skipped' => 1, 'failed' => 0]);

        $rma = Rma::query()->where('uid', 'FI1-501')->first();

        expect($rma)->not->toBeNull()
            ->and($rma->complaint)->toBe('Kapot scherm')
            ->and(FormImportEntryLog::query()->count())->toBe(1);
    });
});
