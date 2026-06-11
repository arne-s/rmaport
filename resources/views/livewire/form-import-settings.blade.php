<div class="customerSection settingspage-payment-tab custom-form-design form-import-settings">
    <div class="grid grid-cols-12">
        <div class="col-span-12 lg:col-span-8 space-y-0">
            @if ($flashMessage)
                <div @class([
                    'rounded-lg px-4 py-3 text-sm mb-4',
                    'bg-success-50 text-success-700' => $flashSuccess,
                    'bg-danger-50 text-danger-700' => ! $flashSuccess,
                ])>
                    {{ $flashMessage }}
                </div>
            @endif

            <div class="form-import-sections">
                <x-form-import-collapsible-section heading="Koppeling" collapse-id="form-import-connection">
                    <div class="settingspage-payment-section">
                    <x-form-import-inline-field label="URL" for="form-import-url">
                        <input id="form-import-url" type="url" wire:model="connectionUrl" class="fi-input w-full" placeholder="https://autovision.nl">
                        @error('connectionUrl')
                            <p class="text-sm text-danger-600 mt-1">{{ $message }}</p>
                        @enderror
                    </x-form-import-inline-field>

                    <x-form-import-inline-field label="Klantsleutel" for="form-import-client-key" wrap-input>
                        <input
                            id="form-import-client-key"
                            type="text"
                            wire:model="connectionUsername"
                            class="fi-input w-full"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                            data-1p-ignore
                            data-lpignore="true"
                            data-bwignore="true"
                            data-form-type="other"
                        >
                        @error('connectionUsername')
                            <p class="text-sm text-danger-600 mt-1">{{ $message }}</p>
                        @enderror
                    </x-form-import-inline-field>

                    <x-form-import-inline-field label="Klantgeheim" for="form-import-client-secret" wrap-input :hint="($connectionId ? ' Laat leeg om ongewijzigd te laten.' : '')">
                        <input
                            id="form-import-client-secret"
                            type="password"
                            wire:model="connectionApiToken"
                            class="fi-input w-full"
                            autocomplete="off"
                            data-1p-ignore
                            data-lpignore="true"
                            data-bwignore="true"
                            data-form-type="other"
                        >
                        @error('connectionApiToken')
                            <p class="text-sm text-danger-600 mt-1">{{ $message }}</p>
                        @enderror
                    </x-form-import-inline-field>

                    <x-form-import-inline-field label="Actief" for="form-import-active" wrap-input>
                        <select id="form-import-active" wire:model="connectionIsActive" class="fi-select form-import-select w-full">
                            <option value="1">Ja</option>
                            <option value="0">Nee</option>
                        </select>
                    </x-form-import-inline-field>
                </div>

                <div class="form-import-section-actions">
                    <button type="button" wire:click="saveConnection" class="fi-btn fi-btn-size-xs fi-btn-color-primary fi-color-primary inline-grid">
                        <span class="fi-btn-label">Opslaan</span>
                    </button>
                    <button type="button" wire:click="testConnection" class="fi-btn fi-btn-size-xs fi-btn-color-gray fi-color-gray fi-btn-outlined inline-grid">
                        <span class="fi-btn-label">Verbinding testen</span>
                    </button>
                    @if ($connectionTestMessage)
                        <p @class([
                            'form-import-section-actions__message',
                            'text-success-600' => $connectionTestSuccess,
                            'text-danger-600' => ! $connectionTestSuccess,
                        ])>{{ $connectionTestMessage }}</p>
                    @endif
                </div>
                </x-form-import-collapsible-section>

                <x-form-import-collapsible-section heading="Formulieren" collapse-id="form-import-forms">
                    @if ($connectionId)
                        <div class="settingspage-payment-section">
                        <x-form-import-inline-field label="Beschikbare formulieren">
                            <button type="button" wire:click="loadAvailableForms" class="fi-btn fi-btn-color-gray fi-color-gray fi-btn-outlined form-import-btn--normal">
                                <span class="fi-btn-label">Formulieren ophalen</span>
                            </button>
                        </x-form-import-inline-field>

                        @if ($availableForms !== [])
                            <x-form-import-inline-field label="Formulier toevoegen" for="form-import-select" wrap-input>
                                <div class="flex flex-wrap items-end gap-3">
                                    <select id="form-import-select" wire:model="selectedFormId" class="fi-select min-w-[16rem]">
                                        <option value="">Selecteer formulier</option>
                                        @foreach ($availableForms as $form)
                                            <option value="{{ $form['id'] }}">{{ $form['title'] }} (ID {{ $form['id'] }})</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="addFormImport" class="fi-btn fi-btn-size-xs fi-btn-color-primary fi-color-primary inline-grid">
                                        <span class="fi-btn-label">Toevoegen</span>
                                    </button>
                                </div>
                            </x-form-import-inline-field>
                        @endif

                        @if ($this->imports->isNotEmpty())
                            <div class="form-import-forms-list">
                                @foreach ($this->imports as $import)
                                    @php
                                        $importMeta = 'ID '.$import->source_form_id.' · '.($import->is_active ? 'Actief' : 'Inactief');

                                        if ($import->last_imported_at) {
                                            $importMeta .= ' · Laatste import: '.$import->last_imported_at->format('d-m-Y H:i').' ('.$import->last_imported_count.')';
                                        }

                                        $sourceFieldOptions = $importSourceFieldOptions[$import->id] ?? [];
                                        $mappingRows = $importMappingRows[$import->id] ?? [];
                                    @endphp

                                    <x-form-import-collapsible-section
                                        wire:key="import-row-{{ $import->id }}"
                                        class="form-import-form-section"
                                        :heading="$import->source_form_title"
                                        :description="$importMeta"
                                        :collapse-id="'form-import-form-'.$import->id"
                                        :collapsed="true"
                                    >
                                        <div class="form-import-form-section__actions">
                                            <button type="button" wire:click="syncImport({{ $import->id }})" class="fi-btn fi-btn-size-xs fi-btn-color-primary fi-color-primary inline-grid">
                                                <span class="fi-btn-label">Nu importeren</span>
                                            </button>
                                            <button type="button" wire:click="toggleImportActive({{ $import->id }})" class="fi-btn fi-btn-size-xs fi-btn-color-gray fi-color-gray fi-btn-outlined inline-grid">
                                                <span class="fi-btn-label">{{ $import->is_active ? 'Deactiveren' : 'Activeren' }}</span>
                                            </button>
                                            <button type="button" wire:click="deleteImport({{ $import->id }})" wire:confirm="Weet je zeker dat je deze formulier-import wilt verwijderen?" class="fi-btn fi-btn-size-xs fi-btn-color-danger fi-color-danger fi-btn-outlined inline-grid">
                                                <span class="fi-btn-label">Verwijderen</span>
                                            </button>
                                        </div>

                                        <div class="settingspage-payment-section form-import-form-section__mapping">
                                            <x-form-import-inline-field label="RMA-nummer" for="form-import-uid-field-{{ $import->id }}" wrap-input hint="Optioneel bronveld voor het RMA-nummer.">
                                                <select id="form-import-uid-field-{{ $import->id }}" wire:model="importUidSourceFieldIds.{{ $import->id }}" class="fi-select w-full max-w-md form-import-select">
                                                    <option value="">Automatisch genereren</option>
                                                    @foreach ($sourceFieldOptions as $field)
                                                        <option value="{{ $field['id'] }}">{{ $field['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </x-form-import-inline-field>

                                            @foreach ($mappingRows as $index => $row)
                                                <x-form-import-inline-field wire:key="mapping-field-{{ $import->id }}-{{ $index }}" hide-label>
                                                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-12 lg:items-end">
                                                        <div class="lg:col-span-2">
                                                            <select wire:model.live="importMappingRows.{{ $import->id }}.{{ $index }}.source_type" class="fi-select w-full form-import-select">
                                                                <option value="field">Bronveld</option>
                                                                <option value="fixed">Vaste waarde</option>
                                                            </select>
                                                        </div>
                                                        <div class="lg:col-span-4">
                                                            @if (($row['source_type'] ?? 'field') === 'fixed')
                                                                <input
                                                                    type="text"
                                                                    wire:model="importMappingRows.{{ $import->id }}.{{ $index }}.fixed_value"
                                                                    class="fi-input w-full"
                                                                    placeholder="Vaste waarde"
                                                                >
                                                            @else
                                                                <select wire:model="importMappingRows.{{ $import->id }}.{{ $index }}.source_field_id" class="fi-select w-full form-import-select">
                                                                    <option value="">Bronveld</option>
                                                                    @foreach ($sourceFieldOptions as $field)
                                                                        <option value="{{ $field['id'] }}">{{ $field['label'] }}</option>
                                                                    @endforeach
                                                                </select>
                                                            @endif
                                                        </div>
                                                        <div class="lg:col-span-5">
                                                            <select wire:model="importMappingRows.{{ $import->id }}.{{ $index }}.rma_field" class="fi-select w-full form-import-select">
                                                                <option value="">RMA-veld</option>
                                                                @foreach ($this->rmaFieldOptions as $value => $label)
                                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="lg:col-span-1">
                                                            <button type="button" wire:click="removeMappingRow({{ $import->id }}, {{ $index }})" class="fi-btn fi-btn-size-xs fi-btn-color-danger fi-color-danger fi-btn-outlined inline-grid">
                                                                <span class="fi-btn-label">×</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </x-form-import-inline-field>
                                            @endforeach

                                            <x-form-import-inline-field hide-label>
                                                <div class="form-import-section-actions form-import-section-actions--inline">
                                                    <button type="button" wire:click="addMappingRow({{ $import->id }})" class="fi-btn fi-btn-size-xs fi-btn-color-gray fi-color-gray fi-btn-outlined inline-grid">
                                                        <span class="fi-btn-label">+ Regel toevoegen</span>
                                                    </button>
                                                    <button type="button" wire:click="saveMappings({{ $import->id }})" class="fi-btn fi-btn-size-xs fi-btn-color-primary fi-color-primary inline-grid">
                                                        <span class="fi-btn-label">Opslaan</span>
                                                    </button>
                                                </div>
                                            </x-form-import-inline-field>
                                        </div>
                                    </x-form-import-collapsible-section>
                                @endforeach
                            </div>
                        @else
                            <x-form-import-inline-field label="Geïmporteerd">
                                <p class="text-sm text-gray-500">Nog geen formulieren toegevoegd.</p>
                            </x-form-import-inline-field>
                        @endif
                    </div>
                    @else
                        <p class="text-sm text-gray-500">Sla eerst de koppeling op om formulieren te beheren.</p>
                    @endif
                </x-form-import-collapsible-section>
            </div>
        </div>
    </div>
</div>
