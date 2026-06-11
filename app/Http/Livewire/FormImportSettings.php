<?php

namespace App\Http\Livewire;

use App\Models\FormImport;
use App\Models\FormImportConnection;
use App\Models\FormImportFieldMapping;
use App\Support\FormImport\FormImportApiClient;
use App\Support\FormImport\ImportFormEntriesAction;
use App\Support\FormImport\RmaFieldRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class FormImportSettings extends Component
{
    public ?int $connectionId = null;

    public string $connectionUrl = '';

    public string $connectionUsername = '';

    public string $connectionApiToken = '';

    public string $connectionIsActive = '1';

    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    /** @var list<array{id: int, title: string, is_active: bool}> */
    public array $availableForms = [];

    public ?int $selectedFormId = null;

    /** @var array<int, string|null> */
    public array $importUidSourceFieldIds = [];

    /** @var array<int, list<array{source_type: string, source_field_id: string, source_field_label: string, fixed_value: string, rma_field: ?string}>> */
    public array $importMappingRows = [];

    /** @var array<int, list<array{id: string, label: string}>> */
    public array $importSourceFieldOptions = [];

    public ?string $flashMessage = null;

    public bool $flashSuccess = false;

    public function mount(): void
    {
        $connection = FormImportConnection::query()->first();

        if ($connection === null) {
            return;
        }

        $this->loadConnection($connection);
    }

    public function saveConnection(): void
    {
        $validated = $this->validate([
            'connectionUrl' => ['required', 'url', 'max:500'],
            'connectionUsername' => ['required', 'string', 'max:191'],
            'connectionApiToken' => [$this->connectionId ? 'nullable' : 'required', 'string', 'max:500'],
            'connectionIsActive' => ['required', Rule::in(['0', '1'])],
        ]);

        $connection = $this->connectionId
            ? FormImportConnection::query()->findOrFail($this->connectionId)
            : new FormImportConnection;

        $connection->fill([
            'name' => $this->connectionNameFromUrl($validated['connectionUrl']),
            'base_url' => $validated['connectionUrl'],
            'username' => $validated['connectionUsername'],
            'is_active' => $validated['connectionIsActive'] === '1',
        ]);

        if (filled($validated['connectionApiToken'] ?? null)) {
            $connection->api_token = $validated['connectionApiToken'];
        } elseif (! $connection->exists) {
            $this->addError('connectionApiToken', 'API-token is verplicht.');

            return;
        }

        $connection->save();

        $this->loadConnection($connection);
        $this->setFlash('Koppeling opgeslagen.', true);
    }

    public function testConnection(): void
    {
        $connection = $this->resolveConnectionForApi();

        if ($connection === null) {
            return;
        }

        try {
            app(FormImportApiClient::class)->testConnection($connection);
            $this->connectionTestMessage = 'Verbinding geslaagd.';
            $this->connectionTestSuccess = true;
        } catch (\Throwable $exception) {
            $this->connectionTestMessage = $exception->getMessage();
            $this->connectionTestSuccess = false;
        }
    }

    public function loadAvailableForms(): void
    {
        $connection = $this->resolveConnectionForApi();

        if ($connection === null) {
            return;
        }

        try {
            $this->availableForms = app(FormImportApiClient::class)->listForms($connection);
            $this->setFlash('Formulieren opgehaald.', true);
        } catch (\Throwable $exception) {
            $this->setFlash($exception->getMessage(), false);
        }
    }

    public function addFormImport(): void
    {
        $this->validate([
            'selectedFormId' => ['required', 'integer'],
        ]);

        $connection = FormImportConnection::query()->find($this->connectionId);

        if ($connection === null) {
            $this->setFlash('Sla eerst een koppeling op.', false);

            return;
        }

        $form = collect($this->availableForms)->firstWhere('id', $this->selectedFormId);

        if ($form === null) {
            try {
                $forms = app(FormImportApiClient::class)->listForms($connection);
                $this->availableForms = $forms;
                $form = collect($forms)->firstWhere('id', $this->selectedFormId);
            } catch (\Throwable $exception) {
                $this->setFlash($exception->getMessage(), false);

                return;
            }
        }

        if ($form === null) {
            $this->setFlash('Formulier niet gevonden.', false);

            return;
        }

        $import = FormImport::query()->firstOrCreate(
            [
                'form_import_connection_id' => $connection->id,
                'source_form_id' => $form['id'],
            ],
            [
                'source_form_title' => $form['title'],
                'is_active' => true,
            ],
        );

        $import->update(['source_form_title' => $form['title']]);

        $this->selectedFormId = null;
        $this->syncImportStateForImport($import->fresh(['fieldMappings']));

        if ($this->importMappingRows[$import->id] === []) {
            $this->importMappingRows[$import->id][] = $this->emptyMappingRow();
        }

        $this->setFlash('Formulier toegevoegd.', true);
    }

    public function addMappingRow(int $importId): void
    {
        $this->importMappingRows[$importId] ??= [];
        $this->importMappingRows[$importId][] = $this->emptyMappingRow();
    }

    public function removeMappingRow(int $importId, int $index): void
    {
        unset($this->importMappingRows[$importId][$index]);
        $this->importMappingRows[$importId] = array_values($this->importMappingRows[$importId]);
    }

    public function saveMappings(int $importId): void
    {
        $allowedRmaFields = RmaFieldRegistry::allowedFields();

        $this->validate([
            'importUidSourceFieldIds.'.$importId => ['nullable', 'string', 'max:20'],
            'importMappingRows.'.$importId => ['array'],
            'importMappingRows.'.$importId.'.*.source_type' => ['required', Rule::in(['field', 'fixed'])],
            'importMappingRows.'.$importId.'.*.source_field_id' => ['nullable', 'string', 'max:20'],
            'importMappingRows.'.$importId.'.*.fixed_value' => ['nullable', 'string', 'max:1000'],
            'importMappingRows.'.$importId.'.*.rma_field' => ['required', 'string', Rule::in($allowedRmaFields)],
        ]);

        $import = FormImport::query()->findOrFail($importId);
        $uidSourceFieldId = $this->importUidSourceFieldIds[$importId] ?? null;
        $mappingRows = $this->importMappingRows[$importId] ?? [];
        $sourceFieldOptions = $this->importSourceFieldOptions[$importId] ?? [];

        $import->update([
            'uid_source_field_id' => blank($uidSourceFieldId) ? null : $uidSourceFieldId,
        ]);

        $import->fieldMappings()->delete();

        foreach ($mappingRows as $index => $row) {
            if (blank($row['rma_field'])) {
                continue;
            }

            $isFixed = ($row['source_type'] ?? 'field') === 'fixed';

            if ($isFixed) {
                if (blank($row['fixed_value'])) {
                    continue;
                }

                $import->fieldMappings()->create([
                    'source_field_id' => '__fixed__.'.$index.'.'.Str::lower(Str::random(8)),
                    'source_field_label' => 'Vaste waarde',
                    'fixed_value' => $row['fixed_value'],
                    'rma_field' => $row['rma_field'],
                    'append_to_notes' => false,
                    'sort_order' => $index,
                ]);

                continue;
            }

            if (blank($row['source_field_id'])) {
                continue;
            }

            $label = collect($sourceFieldOptions)
                ->firstWhere('id', $row['source_field_id'])['label'] ?? $row['source_field_label'] ?? null;

            $import->fieldMappings()->create([
                'source_field_id' => $row['source_field_id'],
                'source_field_label' => $label,
                'fixed_value' => null,
                'rma_field' => $row['rma_field'],
                'append_to_notes' => false,
                'sort_order' => $index,
            ]);
        }

        $this->syncImportStateForImport($import->fresh(['fieldMappings']));
        $this->setFlash('Veld-koppeling opgeslagen.', true);
    }

    public function toggleImportActive(int $importId): void
    {
        $import = FormImport::query()->findOrFail($importId);
        $import->update(['is_active' => ! $import->is_active]);
        $this->setFlash('Status bijgewerkt.', true);
    }

    public function deleteImport(int $importId): void
    {
        FormImport::query()->whereKey($importId)->delete();

        unset(
            $this->importMappingRows[$importId],
            $this->importUidSourceFieldIds[$importId],
            $this->importSourceFieldOptions[$importId],
        );

        $this->setFlash('Formulier-import verwijderd.', true);
    }

    public function syncImport(int $importId, bool $full = false): void
    {
        $import = FormImport::query()->with(['connection', 'fieldMappings', 'state'])->findOrFail($importId);

        try {
            $result = app(ImportFormEntriesAction::class)->sync($import, $full);
            $this->setFlash(
                sprintf(
                    '%d geïmporteerd, %d overgeslagen, %d mislukt.',
                    $result['imported'],
                    $result['skipped'],
                    $result['failed'],
                ),
                $result['failed'] === 0,
            );
        } catch (\Throwable $exception) {
            $this->setFlash($exception->getMessage(), false);
        }
    }

    /**
     * @return Collection<int, FormImport>
     */
    public function getImportsProperty(): Collection
    {
        if ($this->connectionId === null) {
            return collect();
        }

        return FormImport::query()
            ->where('form_import_connection_id', $this->connectionId)
            ->orderBy('source_form_title')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function getRmaFieldOptionsProperty(): array
    {
        return RmaFieldRegistry::options();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.form-import-settings');
    }

    private function loadConnection(FormImportConnection $connection): void
    {
        $this->connectionId = $connection->id;
        $this->connectionUrl = $connection->base_url;
        $this->connectionUsername = $connection->username;
        $this->connectionApiToken = '';
        $this->connectionIsActive = $connection->is_active ? '1' : '0';

        $this->syncImportStates();
    }

    private function syncImportStates(): void
    {
        if ($this->connectionId === null) {
            return;
        }

        $imports = FormImport::query()
            ->with('fieldMappings')
            ->where('form_import_connection_id', $this->connectionId)
            ->get();

        foreach ($imports as $import) {
            $this->syncImportStateForImport($import);
        }

        $validIds = $imports->pluck('id')->all();

        foreach (array_keys($this->importMappingRows) as $importId) {
            if (! in_array($importId, $validIds, true)) {
                unset(
                    $this->importMappingRows[$importId],
                    $this->importUidSourceFieldIds[$importId],
                    $this->importSourceFieldOptions[$importId],
                );
            }
        }
    }

    private function syncImportStateForImport(FormImport $import): void
    {
        $importId = $import->id;

        $this->importUidSourceFieldIds[$importId] = $import->uid_source_field_id ?? '';
        $this->importMappingRows[$importId] = $this->mappingRowsFromImport($import);

        if ($this->importMappingRows[$importId] === [] && array_key_exists($importId, $this->importSourceFieldOptions)) {
            $this->importMappingRows[$importId][] = $this->emptyMappingRow();
        }

        if (array_key_exists($importId, $this->importSourceFieldOptions)) {
            return;
        }

        $connection = $import->connection ?? FormImportConnection::query()->find($this->connectionId);

        if ($connection === null) {
            $this->importSourceFieldOptions[$importId] = [];

            return;
        }

        try {
            $fields = app(FormImportApiClient::class)->listFormFields($connection, $import->source_form_id);
            $this->importSourceFieldOptions[$importId] = array_map(
                fn (array $field): array => ['id' => $field['id'], 'label' => $field['label']],
                $fields,
            );
        } catch (\Throwable $exception) {
            $this->importSourceFieldOptions[$importId] = [];
            $this->setFlash($exception->getMessage(), false);
        }

        if ($this->importMappingRows[$importId] === [] && $this->importSourceFieldOptions[$importId] !== []) {
            $this->importMappingRows[$importId][] = $this->emptyMappingRow();
        }
    }

    /**
     * @return list<array{source_type: string, source_field_id: string, source_field_label: string, fixed_value: string, rma_field: ?string}>
     */
    private function mappingRowsFromImport(FormImport $import): array
    {
        return $import->fieldMappings->map(fn (FormImportFieldMapping $mapping): array => [
            'source_type' => filled($mapping->fixed_value) ? 'fixed' : 'field',
            'source_field_id' => $mapping->source_field_id ?? '',
            'source_field_label' => $mapping->source_field_label ?? '',
            'fixed_value' => $mapping->fixed_value ?? '',
            'rma_field' => $mapping->rma_field,
        ])->values()->all();
    }

    /**
     * @return array{source_type: string, source_field_id: string, source_field_label: string, fixed_value: string, rma_field: null}
     */
    private function emptyMappingRow(): array
    {
        return [
            'source_type' => 'field',
            'source_field_id' => '',
            'source_field_label' => '',
            'fixed_value' => '',
            'rma_field' => null,
        ];
    }

    private function resolveConnectionForApi(): ?FormImportConnection
    {
        if ($this->connectionId) {
            $connection = FormImportConnection::query()->find($this->connectionId);

            if ($connection !== null) {
                if (filled($this->connectionApiToken)) {
                    $connection->api_token = $this->connectionApiToken;
                }

                return $connection;
            }
        }

        if (! filled($this->connectionUrl) || ! filled($this->connectionUsername) || ! filled($this->connectionApiToken)) {
            $this->setFlash('Vul URL, gebruikersnaam en API-token in.', false);

            return null;
        }

        $connection = new FormImportConnection([
            'name' => $this->connectionNameFromUrl($this->connectionUrl),
            'base_url' => $this->connectionUrl,
            'username' => $this->connectionUsername,
            'api_token' => $this->connectionApiToken,
            'is_active' => true,
        ]);

        return $connection;
    }

    private function setFlash(string $message, bool $success): void
    {
        $this->flashMessage = $message;
        $this->flashSuccess = $success;
    }

    private function connectionNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return mb_substr($host, 0, 255);
        }

        return 'Formulier-import';
    }
}
